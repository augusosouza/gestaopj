<?php

namespace App\Http\Controllers;

use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\CobrancaRegistro;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\Movimento;
use App\Models\Parcela;
use App\Models\Titulo;
use App\Services\PosicaoFinanceira;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Central de Contas a Pagar e de Recebimentos (FIN-REM-01 + Augi 3.0, itens 7 e 11).
 * A mesma central serve os dois lados, sobre os mesmos títulos, parcelas e baixas do
 * financeiro (nada é duplicado). Faturas de cartão aparecem uma vez, só em "a pagar".
 */
class ContasPagarController extends Controller
{
    public const ABAS = ['visao' => 'Visão geral', 'aberto' => 'Em aberto', 'vencidas' => 'Vencidas', 'parcelamentos' => 'Parceladas', 'pagas' => 'Pagas', 'calendario' => 'Calendário'];

    public const ABAS_RECEBER = ['visao' => 'Visão geral', 'aberto' => 'Em aberto', 'vencidas' => 'Atrasados', 'pagas' => 'Recebidos',
        'cobrancas' => 'Cobranças', 'renegociacoes' => 'Renegociações', 'calendario' => 'Calendário'];

    private const POR_PAGINA = 25;

    private string $tipo = 'pagar';

    public static function abas(string $tipo): array
    {
        return $tipo === 'receber' ? self::ABAS_RECEBER : self::ABAS;
    }

    public function index(Request $request, PosicaoFinanceira $pos, string $tipo = 'pagar'): View
    {
        $this->tipo = $tipo === 'receber' ? 'receber' : 'pagar';
        $abas = self::abas($this->tipo);
        $abaSolicitada = $request->query('aba');
        $aba = is_string($abaSolicitada) && isset($abas[$abaSolicitada]) ? $abaSolicitada : 'visao';
        $hoje = CarbonImmutable::today();
        $f = $this->filtros($request);
        if ($aba === 'vencidas') {
            $f['situacao'] = 'vencido';
        }
        $todasAbertas = $this->abertas($aba === 'vencidas' ? array_diff_key($f, ['situacao' => 1]) : $f);
        $abertas = $aba === 'vencidas' ? $todasAbertas->where('atrasado', true)->values() : $todasAbertas;
        $mes = $hoje->startOfMonth();
        $pagasMes = $this->totalPago(['de' => $mes, 'ate' => $hoje->endOfMonth()] + array_diff_key($f, ['de' => 1, 'ate' => 1]));
        $vencidas = $todasAbertas->where('atrasado', true);
        $aVencer = $todasAbertas->where('atrasado', false);

        $ind = [
            'aberto' => (int) $todasAbertas->sum('saldo'),
            'qtd' => $todasAbertas->count(),
            'hoje' => (int) $aVencer->filter(fn ($r) => $r['vencimento']->isSameDay($hoje))->sum('saldo'),
            'sete' => (int) $aVencer->filter(fn ($r) => $r['vencimento']->lte($hoje->addDays(7)))->sum('saldo'),
            'trinta' => (int) $aVencer->filter(fn ($r) => $r['vencimento']->lte($hoje->addDays(30)))->sum('saldo'),
            'atrasado' => (int) $vencidas->sum('saldo'),
            'qtd_atrasado' => $vencidas->count(),
            'pago_mes' => $pagasMes,
            'inadimplentes' => $vencidas->pluck('fornecedor')->filter()->unique()->count(),
            'renegociado' => $this->tipo === 'receber' ? (int) CobrancaRegistro::where('tipo', 'renegociacao')->whereDate('data', '>=', $mes)->get()
                ->sum(fn ($r) => $r->detalhes['valor'] ?? 0) : 0,
        ];
        $ind['previsto_mes'] = (int) $todasAbertas->filter(fn ($r) => $r['vencimento']->isSameMonth($hoje))->sum('saldo') + $ind['pago_mes'];
        $dados = ['tipo' => $this->tipo, 'abas' => $abas, 'aba' => $aba, 'f' => $f, 'ind' => $ind, 'hoje' => $hoje,
            'categorias' => CategoriaFinanceira::where('tipo', $this->tipo === 'receber' ? 'receita' : 'despesa')->orderBy('nome')->get(),
            'centros' => CentroCusto::orderBy('nome')->get(),
            'contas' => ContaFinanceira::orderBy('nome')->get(), 'chave' => (string) Str::uuid()];

        $periodoPago = $f + ['de' => $mes, 'ate' => $hoje->endOfMonth()];

        return view('contas.index', $dados + match ($aba) {
            'visao' => ['proximos' => $abertas->where('atrasado', false)->take(8)->values(), 'atrasadosTop' => $vencidas->take(5)->values(),
                'semanas' => $this->porSemana($abertas, $hoje),
                'categoriasTop' => $abertas->groupBy('categoria')->map(fn ($g, $k) => ['rotulo' => $k ?: 'Sem categoria', 'valor' => (int) $g->sum('saldo')])->sortByDesc('valor')->take(6)->values(),
                'caixa' => $pos->projetar(30)],
            'aberto', 'vencidas' => ['linhas' => $this->paginar($abertas), 'totalLinhas' => (int) $abertas->sum('saldo')],
            'parcelamentos' => ['parcelados' => $this->paginar($this->parcelados($f))],
            'calendario' => $this->calendario($request, $abertas),
            'pagas' => $this->listarPagas($periodoPago),
            'cobrancas' => ['registros' => CobrancaRegistro::with('titulo', 'parcela', 'responsavel')->whereIn('tipo', ['contato', 'promessa'])
                ->orderByDesc('data')->orderByDesc('id')->limit(300)->get(), 'vencidasCobrar' => $vencidas->take(20)->values()],
            'renegociacoes' => ['registros' => CobrancaRegistro::with('titulo', 'criadoPor')->where('tipo', 'renegociacao')->orderByDesc('data')->limit(300)->get()],
        });
    }

    /** Filtros comuns às abas. Valores em reais na tela, centavos aqui. */
    private function filtros(Request $r): array
    {
        $data = function (string $k) use ($r): ?CarbonImmutable {
            $valor = $r->query($k);
            if (! is_string($valor) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $valor, $partes)
                || ! checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
                return null;
            }

            return CarbonImmutable::parse($valor);
        };
        $dinheiro = function (string $k) use ($r): ?int {
            $valor = $r->query($k);

            return is_string($valor) && preg_match('/^[\d.,]+$/D', $valor)
                ? (int) round((float) str_replace(['.', ','], ['', '.'], $valor) * 100)
                : null;
        };
        $competencia = $r->query('competencia');
        $fornecedor = $r->query('fornecedor');
        $prox = in_array($r->integer('proximos'), [7, 15, 30], true) ? $r->integer('proximos') : null;

        return array_filter([
            'de' => $data('de'), 'ate' => $prox ? CarbonImmutable::today()->addDays($prox) : $data('ate'), 'proximos' => $prox,
            'categoria' => $r->integer('categoria') ?: null, 'fornecedor' => is_string($fornecedor) ? (trim($fornecedor) ?: null) : null,
            'centro' => $r->integer('centro') ?: null,
            'competencia' => is_string($competencia) && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $competencia) ? $competencia : null,
            'conta' => $r->integer('conta') ?: null, 'min' => $dinheiro('min'), 'max' => $dinheiro('max'),
            'situacao' => in_array($r->query('situacao'), ['vencido', 'a_vencer', 'parcial'], true) ? $r->query('situacao') : null,
        ], fn ($v) => $v !== null);
    }

    /** Parcelas a pagar com saldo (cartão agrupado por fatura), já filtradas. */
    private function abertas(array $f): Collection
    {
        $hoje = CarbonImmutable::today();
        $ps = Parcela::with(['titulo' => fn ($q) => $q->withCount('parcelas'), 'titulo.categoria', 'titulo.origem', 'movimentos.estornoDe', 'fatura.cartao'])
            ->whereHas('titulo', fn ($q) => $this->filtrarTitulo($q, $f)->whereNull('cancelado_em'))
            ->when(isset($f['categoria']) || isset($f['centro']) || isset($f['competencia']), fn ($q) => $q->whereNull('fatura_cartao_id'))
            ->when(isset($f['de']) || isset($f['ate']), function ($q) use ($f) {
                $datas = fn ($q) => $q
                    ->when($f['de'] ?? null, fn ($q, $de) => $q->where('vencimento', '>=', $de->toDateString()))
                    ->when($f['ate'] ?? null, fn ($q, $ate) => $q->where('vencimento', '<', $ate->addDay()->toDateString()));
                $q->where(fn ($q) => $q
                    ->where(fn ($p) => $datas($p->whereNull('fatura_cartao_id')))
                    ->orWhereHas('fatura', $datas));
            })
            ->orderBy('vencimento')->orderBy('id')->get()->filter(fn (Parcela $p) => $p->saldo() > 0);

        $linhas = $ps->whereNull('fatura_cartao_id')->map(function (Parcela $p) use ($hoje) {
            $t = $p->titulo;
            $conta = $t->origem instanceof Compra ? $t->origem->conta_id : null;

            return ['tipo' => 'parcela', 'parcela' => $p, 'titulo' => $t, 'descricao' => $t->descricao, 'fornecedor' => $t->contraparte,
                'categoria' => $t->categoria?->nome, 'categoria_id' => $t->categoria_id, 'forma' => Titulo::FORMAS[$t->forma] ?? null,
                'vencimento' => CarbonImmutable::parse($p->vencimento->toDateString()), 'valor' => $p->valor_centavos, 'saldo' => $p->saldo(),
                'parcial' => $p->abatido() > 0, 'n' => $t->parcelas_count > 1 ? $p->numero.'/'.$t->parcelas_count : null,
                'atrasado' => $p->vencimento->lt($hoje), 'conta_id' => $conta, 'url' => route('financeiro.titulo', $t)];
        });
        $faturas = $ps->whereNotNull('fatura_cartao_id')->groupBy('fatura_cartao_id')->map(function ($g) use ($hoje) {
            $fat = $g->first()->fatura;

            return ['tipo' => 'fatura', 'parcela' => null, 'titulo' => null, 'descricao' => 'Fatura '.$fat->cartao->nome.' — '.$fat->rotulo(),
                'fornecedor' => $fat->cartao->instituicao, 'categoria' => null, 'categoria_id' => null, 'forma' => 'Cartão de crédito',
                'vencimento' => CarbonImmutable::parse($fat->vencimento->toDateString()), 'valor' => (int) $g->sum('valor_centavos'), 'saldo' => (int) $g->sum(fn ($p) => $p->saldo()),
                'parcial' => false, 'n' => $g->count().' compra(s)', 'atrasado' => $fat->vencimento->lt($hoje), 'conta_id' => $fat->cartao->conta_pagamento_id,
                'url' => route('cartoes.fatura', $fat), 'fatura' => $fat];
        });

        return $linhas->concat($faturas->values())
            ->filter(fn ($l) => (! isset($f['de']) || $l['vencimento']->gte($f['de'])) && (! isset($f['ate']) || $l['vencimento']->lte($f['ate']))
                && (! isset($f['categoria']) || $l['categoria_id'] === $f['categoria'])
                && (! isset($f['fornecedor']) || Str::contains(Str::lower(($l['fornecedor'] ?? '').' '.$l['descricao']), Str::lower($f['fornecedor'])))
                && (! isset($f['conta']) || $l['conta_id'] === $f['conta'])
                && (! isset($f['min']) || $l['saldo'] >= $f['min']) && (! isset($f['max']) || $l['saldo'] <= $f['max'])
                && match ($f['situacao'] ?? null) {
                    'vencido' => $l['atrasado'], 'a_vencer' => ! $l['atrasado'], 'parcial' => $l['parcial'], default => true
                })
            ->sortBy(fn ($l) => $l['vencimento']->toDateString())->values();
    }

    private function filtrarTitulo(Builder $query, array $f): Builder
    {
        return $query->where('tipo', $this->tipo)
            ->when(isset($f['categoria']), fn ($q) => $q->where('categoria_id', $f['categoria']))
            ->when(isset($f['centro']), fn ($q) => $q->whereHas('rateios', fn ($rateios) => $rateios->where('centro_custo_id', $f['centro'])))
            ->when(isset($f['competencia']), fn ($q) => $q
                ->where('competencia', '>=', $f['competencia'].'-01')
                ->where('competencia', '<', CarbonImmutable::parse($f['competencia'].'-01')->addMonth()->toDateString()));
    }

    private function queryPagas(array $f): Builder
    {
        return Movimento::query()->where('tipo', 'baixa')->whereDoesntHave('estorno')
            ->where('data', '>=', $f['de']->toDateString())->where('data', '<', $f['ate']->addDay()->toDateString())
            ->whereHas('parcela.titulo', fn ($q) => $this->filtrarTitulo($q, $f))
            ->when(isset($f['conta']), fn ($q) => $q->where('conta_id', $f['conta']))
            ->when(isset($f['min']), fn ($q) => $q->where('valor_centavos', '>=', $f['min']))
            ->when(isset($f['max']), fn ($q) => $q->where('valor_centavos', '<=', $f['max']));
    }

    private function correspondeContraparte(Movimento $m, string $termo): bool
    {
        $titulo = $m->parcela->titulo;

        return Str::contains(Str::lower($titulo->contraparte.' '.$titulo->descricao), Str::lower($termo));
    }

    private function totalPago(array $f): int
    {
        $query = $this->queryPagas($f);
        if (! isset($f['fornecedor'])) {
            return (int) $query->sum('valor_centavos');
        }

        return (int) $query->with('parcela.titulo')->lazyById()
            ->filter(fn (Movimento $m) => $this->correspondeContraparte($m, $f['fornecedor']))->sum('valor_centavos');
    }

    private function listarPagas(array $f): array
    {
        $query = $this->queryPagas($f)->orderByDesc('data')->orderByDesc('id');
        if (! isset($f['fornecedor'])) {
            return [
                'totalPagas' => (int) (clone $query)->reorder()->sum('valor_centavos'),
                'pagas' => $query->with('parcela.titulo', 'conta', 'criadoPor')->paginate(self::POR_PAGINA)->withQueryString(),
            ];
        }

        // Mantém a busca por trecho sem depender da collation do banco.
        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $inicio = ($pagina - 1) * self::POR_PAGINA;
        $registros = new \Illuminate\Database\Eloquent\Collection;
        $total = $qtd = 0;
        foreach ($query->with('parcela.titulo')->lazy() as $movimento) {
            if (! $this->correspondeContraparte($movimento, $f['fornecedor'])) {
                continue;
            }
            if ($qtd >= $inicio && $registros->count() < self::POR_PAGINA) {
                $registros->push($movimento);
            }
            $qtd++;
            $total += $movimento->valor_centavos;
        }
        $registros->load('conta', 'criadoPor');

        return ['totalPagas' => $total, 'pagas' => $this->montarPagina($registros, $qtd, $pagina)];
    }

    private function paginar(Collection $linhas): LengthAwarePaginator
    {
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        return $this->montarPagina($linhas->forPage($pagina, self::POR_PAGINA)->values(), $linhas->count(), $pagina);
    }

    private function montarPagina(Collection $linhas, int $total, int $pagina): LengthAwarePaginator
    {
        return (new LengthAwarePaginator($linhas, $total, self::POR_PAGINA, $pagina, ['path' => LengthAwarePaginator::resolveCurrentPath()]))
            ->withQueryString();
    }

    private function parcelados(array $f): Collection
    {
        return $this->filtrarTitulo(Titulo::with('parcelas.movimentos.estornoDe', 'categoria', 'origem'), $f)->whereNull('cancelado_em')
            ->has('parcelas', '>', 1)->orderByDesc('id')->get()
            ->filter(fn (Titulo $t) => $t->saldo() > 0 && (! isset($f['categoria']) || $t->categoria_id === $f['categoria'])
                && (! isset($f['fornecedor']) || Str::contains(Str::lower($t->contraparte.' '.$t->descricao), Str::lower($f['fornecedor']))))
            ->values();
    }

    private function porSemana(Collection $abertas, CarbonImmutable $hoje): array
    {
        $ini = $hoje->startOfWeek();
        $sem = [];
        for ($i = 0; $i < 8; $i++) {
            $de = $ini->addWeeks($i);
            $sem[] = ['rotulo' => $de->format('d/m'), 'valor' => (int) $abertas->filter(fn ($l) => $i === 0 ? $l['vencimento']->lte($de->endOfWeek()) : $l['vencimento']->between($de, $de->endOfWeek()))->sum('saldo')];
        }

        return $sem;
    }

    private function calendario(Request $r, Collection $abertas): array
    {
        $valor = $r->query('mes');
        $mes = is_string($valor) && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $valor)
            ? CarbonImmutable::parse($valor.'-01')
            : CarbonImmutable::today()->startOfMonth();

        return ['mes' => $mes, 'porDia' => $abertas->filter(fn ($l) => $l['vencimento']->isSameMonth($mes))->groupBy(fn ($l) => $l['vencimento']->toDateString())];
    }
}
