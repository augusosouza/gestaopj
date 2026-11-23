<?php

namespace App\Http\Controllers;

use App\Models\CategoriaFinanceira;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\Movimento;
use App\Models\Parcela;
use App\Models\Titulo;
use App\Services\PosicaoFinanceira;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * FIN-REM-01 — Central de Contas a Pagar.
 * Abas: Visão geral, Em aberto, Parcelamentos, Calendário, Pagas. Usa os mesmos títulos,
 * parcelas e baixas do financeiro (nada é duplicado); faturas de cartão aparecem uma vez.
 */
class ContasPagarController extends Controller
{
    public const ABAS = ['visao' => 'Visão geral', 'aberto' => 'Em aberto', 'parcelamentos' => 'Parcelamentos', 'calendario' => 'Calendário', 'pagas' => 'Pagas'];

    public function index(Request $request, PosicaoFinanceira $pos): View
    {
        $aba = array_key_exists((string) $request->query('aba'), self::ABAS) ? $request->query('aba') : 'visao';
        $hoje = CarbonImmutable::today();
        $f = $this->filtros($request);
        $abertas = $this->abertas($f);
        $mes = $hoje->startOfMonth();
        $pagasMes = $this->pagas(['de' => $mes, 'ate' => $hoje->endOfMonth()] + $f);

        $ind = [
            'aberto' => (int) $abertas->sum('saldo'),
            'qtd' => $abertas->count(),
            'sete' => (int) $abertas->filter(fn ($r) => ! $r['atrasado'] && $r['vencimento']->lte($hoje->addDays(7)))->sum('saldo'),
            'atrasado' => (int) $abertas->where('atrasado', true)->sum('saldo'),
            'qtd_atrasado' => $abertas->where('atrasado', true)->count(),
            'pago_mes' => (int) $pagasMes->sum('valor'),
        ];
        $dados = ['aba' => $aba, 'f' => $f, 'ind' => $ind, 'hoje' => $hoje,
            'categorias' => CategoriaFinanceira::where('tipo', 'despesa')->orderBy('nome')->get(),
            'contas' => ContaFinanceira::orderBy('nome')->get(), 'chave' => (string) Str::uuid()];

        return view('contas_pagar.index', $dados + match ($aba) {
            'visao' => ['proximos' => $abertas->take(8), 'semanas' => $this->porSemana($abertas, $hoje),
                'categoriasTop' => $abertas->groupBy('categoria')->map(fn ($g, $k) => ['rotulo' => $k ?: 'Sem categoria', 'valor' => (int) $g->sum('saldo')])->sortByDesc('valor')->take(6)->values(),
                'caixa' => $pos->projetar(30)],
            'aberto' => ['linhas' => $abertas],
            'parcelamentos' => ['parcelados' => $this->parcelados($f)],
            'calendario' => $this->calendario($request, $abertas),
            'pagas' => ['pagas' => $pagas = $this->pagas($f + ['de' => $f['de'] ?? $mes, 'ate' => $f['ate'] ?? $hoje->endOfMonth()])],
        });
    }

    /** Filtros comuns às abas. Valores em reais na tela, centavos aqui. */
    private function filtros(Request $r): array
    {
        $data = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query($k)) ? CarbonImmutable::parse($r->query($k)) : null;
        $dinheiro = fn ($k) => filled($r->query($k)) && preg_match('/^[\d.,]+$/', $r->query($k)) ? (int) round((float) str_replace(['.', ','], ['', '.'], $r->query($k)) * 100) : null;
        $prox = in_array($r->integer('proximos'), [7, 15, 30], true) ? $r->integer('proximos') : null;

        return array_filter([
            'de' => $data('de'), 'ate' => $prox ? CarbonImmutable::today()->addDays($prox) : $data('ate'), 'proximos' => $prox,
            'categoria' => $r->integer('categoria') ?: null, 'fornecedor' => trim((string) $r->query('fornecedor')) ?: null,
            'conta' => $r->integer('conta') ?: null, 'min' => $dinheiro('min'), 'max' => $dinheiro('max'),
            'situacao' => in_array($r->query('situacao'), ['vencido', 'a_vencer', 'parcial'], true) ? $r->query('situacao') : null,
        ], fn ($v) => $v !== null);
    }

    /** Parcelas a pagar com saldo (cartão agrupado por fatura), já filtradas. */
    private function abertas(array $f): Collection
    {
        $hoje = CarbonImmutable::today();
        $ps = Parcela::with(['titulo' => fn ($q) => $q->withCount('parcelas'), 'titulo.categoria', 'titulo.origem', 'movimentos.estornoDe', 'fatura.cartao'])
            ->whereHas('titulo', fn ($q) => $q->where('tipo', 'pagar')->whereNull('cancelado_em'))
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

    /** Baixas (pagamentos) no período, sem estornadas. */
    private function pagas(array $f): Collection
    {
        return Movimento::with('parcela.titulo.categoria', 'conta', 'estorno', 'criadoPor')->where('tipo', 'baixa')
            ->whereBetween('data', [$f['de']->toDateString(), $f['ate']->toDateString()])
            ->whereHas('parcela.titulo', fn ($q) => $q->where('tipo', 'pagar'))
            ->when($f['conta'] ?? null, fn ($q, $c) => $q->where('conta_id', $c))
            ->orderByDesc('data')->orderByDesc('id')->get()->reject->estornado()
            ->filter(fn (Movimento $m) => (! isset($f['categoria']) || $m->parcela->titulo->categoria_id === $f['categoria'])
                && (! isset($f['fornecedor']) || Str::contains(Str::lower($m->parcela->titulo->contraparte.' '.$m->parcela->titulo->descricao), Str::lower($f['fornecedor'])))
                && (! isset($f['min']) || $m->valor_centavos >= $f['min']) && (! isset($f['max']) || $m->valor_centavos <= $f['max']))
            ->map(fn (Movimento $m) => ['mov' => $m, 'valor' => $m->valor_centavos])->values();
    }

    private function parcelados(array $f): Collection
    {
        return Titulo::with('parcelas.movimentos.estornoDe', 'categoria', 'origem')->where('tipo', 'pagar')->whereNull('cancelado_em')
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
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $r->query('mes')) ? CarbonImmutable::parse($r->query('mes').'-01') : CarbonImmutable::today()->startOfMonth();

        return ['mes' => $mes, 'porDia' => $abertas->filter(fn ($l) => $l['vencimento']->isSameMonth($mes))->groupBy(fn ($l) => $l['vencimento']->toDateString())];
    }
}
