<?php

namespace App\Services;

use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\Evento;
use App\Models\Orcamento;
use App\Models\Titulo;
use App\Models\TituloRateio;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Augi 3.0, etapa 8 — centros de custo, rateios e orçamentos.
 *
 * Definições (as mesmas em telas, relatórios, sugestões e assistente):
 *  - Comprometido: despesas lançadas na competência (pagas ou não). É o que já "gastou" do orçamento.
 *  - Realizado: a parte do comprometido que já foi paga (abatida por baixas).
 *  - Disponível: orçado − comprometido (nunca exibido como dinheiro em conta).
 *  - Orçamento de categoria inclui as subcategorias. Orçamento de centro usa o valor rateado.
 */
final class Planejamento
{
    public const ALERTA_PCT = 90;

    public function __construct(private Financeiro $fin) {}

    /**
     * Define o rateio de um lançamento. Percentuais em texto ("33,33"); a soma precisa dar 100%.
     *
     * @param  array<int, string>  $percentuais  centro_custo_id => percentual
     */
    public function ratear(Titulo $t, array $percentuais): void
    {
        $bps = [];
        foreach ($percentuais as $centro => $pct) {
            if (trim((string) $pct) === '') {
                continue;
            }
            if (! preg_match('/^\d{1,3}([.,]\d{1,2})?$/', trim($pct))) {
                throw ValidationException::withMessages(['rateio' => "Percentual inválido: {$pct}."]);
            }
            $bp = (int) round((float) str_replace(',', '.', $pct) * 100);
            if ($bp > 0) {
                $bps[(int) $centro] = $bp;
            }
        }
        $centros = CentroCusto::where('ativo', true)->whereIn('id', array_keys($bps))->pluck('id')->all();
        $erro = match (true) {
            $t->cancelado() => 'Lançamento cancelado.',
            $bps !== [] && array_sum($bps) !== 10000 => 'A soma dos percentuais precisa ser 100% (está em '.number_format(array_sum($bps) / 100, 2, ',', '.').'%).',
            count($centros) !== count($bps) => 'Centro de custo inválido ou inativo.',
            default => null,
        };
        if ($erro) {
            throw ValidationException::withMessages(['rateio' => $erro]);
        }
        $this->fin->exigirMesAberto($t->organizacao_id, $t->competencia->toDateString(), 'rateio');

        DB::transaction(function () use ($t, $bps) {
            $antes = $t->rateios()->with('centro')->get()->map(fn ($r) => $r->centro->nome.' '.($r->percentual_bp / 100).'%')->implode(', ');
            TituloRateio::where('titulo_id', $t->id)->delete();
            if ($bps) {
                $valores = Dinheiro::ratear($t->valor_total_centavos, $bps);
                foreach ($bps as $centro => $bp) {
                    TituloRateio::create(['organizacao_id' => $t->organizacao_id, 'titulo_id' => $t->id, 'centro_custo_id' => $centro,
                        'percentual_bp' => $bp, 'valor_centavos' => $valores[$centro]]);
                }
            }
            $depois = $t->rateios()->with('centro')->get()->map(fn ($r) => $r->centro->nome.' '.($r->percentual_bp / 100).'%')->implode(', ');
            Evento::registrar('financeiro.rateio', $t, ['de' => $antes ?: 'sem rateio', 'para' => $depois ?: 'sem rateio']);
        });
    }

    /** Categoria + todas as subcategorias (ids). */
    public function familia(int $categoriaId): array
    {
        $todas = CategoriaFinanceira::get(['id', 'pai_id'])->groupBy('pai_id');
        $ids = [$categoriaId];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($todas->get($ids[$i], collect()) as $f) {
                $ids[] = $f->id;
            }
        }

        return $ids;
    }

    public function salvarOrcamento(CarbonImmutable $mes, ?int $categoriaId, ?int $centroId, int $valor): Orcamento
    {
        if (! $categoriaId === ! $centroId) {
            throw ValidationException::withMessages(['valor' => 'Escolha uma categoria OU um centro de custo.']);
        }
        if ($categoriaId && ! CategoriaFinanceira::where('tipo', 'despesa')->find($categoriaId)) {
            throw ValidationException::withMessages(['valor' => 'Orçamento por categoria vale para categorias de despesa.']);
        }
        if ($centroId && ! CentroCusto::find($centroId)) {
            throw ValidationException::withMessages(['valor' => 'Centro de custo inválido.']);
        }
        if ($valor <= 0) {
            throw ValidationException::withMessages(['valor' => 'Informe um valor positivo.']);
        }
        $o = Orcamento::updateOrCreate(['competencia' => $mes->startOfMonth()->toDateString(), 'categoria_id' => $categoriaId, 'centro_custo_id' => $centroId],
            ['valor_centavos' => $valor, 'criado_por' => auth()->id()]);
        Evento::registrar('financeiro.orcamento', $o, ['mes' => $mes->format('m/Y'), 'valor' => $valor]);

        return $o;
    }

    /** Copia os orçamentos do mês anterior para os que ainda não existem no mês. */
    public function copiarMesAnterior(CarbonImmutable $mes): int
    {
        $n = 0;
        foreach (Orcamento::whereDate('competencia', $mes->subMonthNoOverflow()->startOfMonth())->get() as $o) {
            $ja = Orcamento::whereDate('competencia', $mes->startOfMonth())->where('categoria_id', $o->categoria_id)->where('centro_custo_id', $o->centro_custo_id)->exists();
            if (! $ja) {
                Orcamento::create(['competencia' => $mes->startOfMonth()->toDateString(), 'categoria_id' => $o->categoria_id, 'centro_custo_id' => $o->centro_custo_id,
                    'valor_centavos' => $o->valor_centavos, 'criado_por' => auth()->id()]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Orçado × comprometido × realizado do mês.
     *
     * @return Collection<int, array{orcamento: Orcamento, rotulo: string, tipo: string, orcado: int, comprometido: int, realizado: int, disponivel: int, pct: int, estado: string}>
     */
    public function acompanhamento(CarbonImmutable $mes): Collection
    {
        $titulos = $this->despesasDoMes($mes);

        return Orcamento::with('categoria.pai', 'centro')->whereDate('competencia', $mes->startOfMonth())->get()->map(function (Orcamento $o) use ($titulos) {
            if ($o->categoria_id) {
                $ids = $this->familia($o->categoria_id);
                $doGrupo = $titulos->filter(fn ($t) => in_array($t->categoria_id, $ids, true));
                $comp = (int) $doGrupo->sum('valor_total_centavos');
                $real = (int) $doGrupo->sum(fn ($t) => $t->liquidado());
            } else {
                [$comp, $real] = [0, 0];
                foreach ($titulos as $t) {
                    $r = $t->rateios->firstWhere('centro_custo_id', $o->centro_custo_id);
                    if ($r) {
                        $comp += $r->valor_centavos;
                        $real += Dinheiro::ratear($t->liquidado(), $t->rateios->pluck('percentual_bp', 'centro_custo_id')->all())[$o->centro_custo_id];
                    }
                }
            }
            $pct = $o->valor_centavos > 0 ? (int) floor($comp * 100 / $o->valor_centavos) : 0;

            return ['orcamento' => $o, 'rotulo' => $o->categoria ? $o->categoria->caminho() : $o->centro->rotulo(), 'tipo' => $o->categoria_id ? 'categoria' : 'centro',
                'orcado' => $o->valor_centavos, 'comprometido' => $comp, 'realizado' => $real, 'disponivel' => $o->valor_centavos - $comp, 'pct' => $pct,
                'estado' => $comp > $o->valor_centavos ? 'excedido' : ($pct >= self::ALERTA_PCT ? 'alerta' : 'ok')];
        })->sortByDesc('pct')->values();
    }

    /** Despesas com gasto no mês mas sem orçamento (nem na categoria, nem na categoria-mãe). */
    public function semOrcamento(CarbonImmutable $mes): Collection
    {
        $cobertas = collect(Orcamento::whereDate('competencia', $mes->startOfMonth())->whereNotNull('categoria_id')->pluck('categoria_id'))
            ->flatMap(fn ($id) => $this->familia($id))->unique()->all();

        return $this->despesasDoMes($mes)->reject(fn ($t) => $t->categoria_id && in_array($t->categoria_id, $cobertas, true))
            ->groupBy(fn ($t) => $t->categoria?->caminho() ?? 'Sem categoria')
            ->map(fn ($g, $k) => ['rotulo' => $k, 'valor' => (int) $g->sum('valor_total_centavos')])->sortByDesc('valor')->values();
    }

    /** Totais de despesa por centro de custo no mês (rateado; o que não tem rateio fica em "Sem centro"). */
    public function porCentro(CarbonImmutable $mes): Collection
    {
        $out = [];
        foreach ($this->despesasDoMes($mes) as $t) {
            if ($t->rateios->isEmpty()) {
                $out['Sem centro de custo'] = ($out['Sem centro de custo'] ?? 0) + $t->valor_total_centavos;
            }
            foreach ($t->rateios as $r) {
                $out[$r->centro->rotulo()] = ($out[$r->centro->rotulo()] ?? 0) + $r->valor_centavos;
            }
        }
        arsort($out);

        return collect($out)->map(fn ($v, $k) => ['rotulo' => $k, 'valor' => $v])->values();
    }

    /**
     * Despesas por categoria-mãe nos últimos N meses, com a variação do último mês contra a média dos anteriores.
     *
     * @return array{meses: list<CarbonImmutable>, linhas: Collection}
     */
    public function comparativo(CarbonImmutable $ate, int $meses = 6): array
    {
        $lista = [];
        for ($i = $meses - 1; $i >= 0; $i--) {
            $lista[] = $ate->startOfMonth()->subMonthsNoOverflow($i);
        }
        $tits = Titulo::with('categoria.pai.pai')->where('tipo', 'pagar')->whereNull('cancelado_em')
            ->whereDate('competencia', '>=', $lista[0]->toDateString())->whereDate('competencia', '<=', $ate->startOfMonth()->toDateString())->get(); // whereDate: coluna date pode vir com hora
        $linhas = $tits->groupBy(fn ($t) => $t->categoria?->raiz()->nome ?? 'Sem categoria')
            ->map(function ($g, $nome) use ($lista) {
                $valores = array_map(fn ($m) => (int) $g->filter(fn ($t) => $t->competencia->isSameMonth($m))->sum('valor_total_centavos'), $lista);
                $anteriores = array_slice($valores, 0, -1);
                $media = count($anteriores) ? array_sum($anteriores) / count($anteriores) : 0;
                $ultimo = end($valores);

                return ['rotulo' => $nome, 'valores' => $valores, 'total' => array_sum($valores),
                    'variacao' => $media > 0 ? (int) round(($ultimo - $media) * 100 / $media) : null];
            })->sortByDesc('total')->values();

        return ['meses' => $lista, 'linhas' => $linhas];
    }

    private function despesasDoMes(CarbonImmutable $mes): Collection
    {
        return Titulo::with('parcelas.movimentos.estornoDe', 'rateios.centro', 'categoria.pai')->where('tipo', 'pagar')->whereNull('cancelado_em')
            ->whereDate('competencia', $mes->startOfMonth())->get();
    }
}
