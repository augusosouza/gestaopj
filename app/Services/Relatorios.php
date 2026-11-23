<?php

namespace App\Services;

use App\Models\CategoriaFinanceira;
use App\Models\Cobranca;
use App\Models\Contrato;
use App\Models\Fatura;
use App\Models\FaturaCartao;
use App\Models\Movimento;
use App\Models\Titulo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Augi 3.0, etapa 9 — relatórios gerenciais e DRE gerencial.
 *
 * Todos saem dos mesmos títulos, parcelas e baixas das telas (nenhuma base paralela).
 * Regimes:
 *   competência = valor dos lançamentos pelo mês a que pertencem (cancelados fora);
 *   caixa       = dinheiro que entrou/saiu (baixas não estornadas), pela data do pagamento.
 * Transferências entre contas não aparecem: não são receita nem despesa.
 */
final class Relatorios
{
    /** slug => [título, descrição] */
    public const LISTA = [
        'dre' => ['DRE gerencial', 'Receita, custos, despesas e resultado por mês. Relatório de gestão, não a DRE contábil oficial.'],
        'fluxo-caixa' => ['Fluxo de caixa realizado', 'Entradas e saídas efetivas por mês e o saldo do período.'],
        'previsto-realizado' => ['Previsto × realizado', 'Quanto foi lançado para o mês e quanto de fato entrou ou saiu.'],
        'despesas-categoria' => ['Despesas por categoria', 'Distribuição das despesas por categoria e subcategoria.'],
        'receitas-categoria' => ['Receitas por categoria', 'De onde vêm as receitas.'],
        'fornecedores' => ['Fornecedores', 'Quanto foi gasto com cada fornecedor ou prestador.'],
        'clientes' => ['Clientes', 'Quanto cada cliente gerou de receita e quanto ainda deve.'],
        'contratos' => ['Contratos', 'Receita faturada, custo com prestadores e margem de cada contrato.'],
        'centros-custo' => ['Centros de custo', 'Despesas rateadas por centro de custo.'],
        'cartoes' => ['Cartões', 'Faturas por cartão: total, pago e em aberto.'],
    ];

    public function __construct(private Planejamento $pl) {}

    /**
     * @return array{titulo: string, descricao: string, colunas: list<array{0: string, 1: string}>, linhas: list<list<mixed>>, total: ?list<mixed>, grafico: ?array}
     */
    public function gerar(string $slug, CarbonImmutable $de, CarbonImmutable $ate, string $regime = 'competencia'): array
    {
        [$titulo, $descricao] = self::LISTA[$slug];
        $r = match ($slug) {
            'dre' => $this->dre($de, $ate, $regime),
            'fluxo-caixa' => $this->fluxo($de, $ate),
            'previsto-realizado' => $this->previstoRealizado($de, $ate),
            'despesas-categoria' => $this->porCategoria('pagar', $de, $ate, $regime),
            'receitas-categoria' => $this->porCategoria('receber', $de, $ate, $regime),
            'fornecedores' => $this->porContraparte('pagar', $de, $ate),
            'clientes' => $this->porContraparte('receber', $de, $ate),
            'contratos' => $this->contratos($de, $ate),
            'centros-custo' => $this->centros($de, $ate),
            'cartoes' => $this->cartoes($de, $ate),
        };

        return $r + ['titulo' => $titulo, 'descricao' => $descricao, 'total' => null, 'grafico' => null];
    }

    /** Meses do período (1º dia de cada). @return list<CarbonImmutable> */
    public function meses(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $out = [];
        for ($m = $de->startOfMonth(); $m->lte($ate); $m = $m->addMonthNoOverflow()) {
            $out[] = $m;
        }

        return $out;
    }

    // ---------------- DRE ----------------

    /** Valor por categoria e mês no regime escolhido. @return Collection<int, array{categoria: ?CategoriaFinanceira, tipo: string, mes: string, valor: int}> */
    private function lancamentos(CarbonImmutable $de, CarbonImmutable $ate, string $regime, ?string $tipo = null): Collection
    {
        if ($regime === 'caixa') {
            return Movimento::with('parcela.titulo.categoria.pai.pai', 'estorno')->where('tipo', 'baixa')
                ->whereDate('data', '>=', $de->toDateString())->whereDate('data', '<=', $ate->toDateString())->get()->reject->estornado()
                ->filter(fn ($m) => ! $tipo || $m->parcela->titulo->tipo === $tipo)
                ->map(fn ($m) => ['categoria' => $m->parcela->titulo->categoria, 'tipo' => $m->parcela->titulo->tipo, 'mes' => $m->data->format('Y-m'),
                    'valor' => $m->valor_centavos, 'contraparte' => $m->parcela->titulo->contraparte])->values();
        }

        return Titulo::with('categoria.pai.pai')->whereNull('cancelado_em')->when($tipo, fn ($q) => $q->where('tipo', $tipo))
            ->whereDate('competencia', '>=', $de->startOfMonth()->toDateString())->whereDate('competencia', '<=', $ate->toDateString())->get()
            ->map(fn ($t) => ['categoria' => $t->categoria, 'tipo' => $t->tipo, 'mes' => $t->competencia->format('Y-m'), 'valor' => $t->valor_total_centavos,
                'contraparte' => $t->contraparte])->values();
    }

    /**
     * Estrutura da DRE gerencial. Linhas de grupo (com detalhamento por categoria) e linhas de resultado.
     *
     * @return array{meses: list<CarbonImmutable>, dre: list<array{tipo: string, rotulo: string, valores: list<int>, total: int, nivel: int}>}
     */
    public function dreEstrutura(CarbonImmutable $de, CarbonImmutable $ate, string $regime): array
    {
        $meses = $this->meses($de, $ate);
        $chaves = array_map(fn ($m) => $m->format('Y-m'), $meses);
        $lanc = $this->lancamentos($de, $ate, $regime);
        $grupoDe = fn ($l) => $l['categoria']?->grupoDre() ?? ($l['tipo'] === 'receber' ? 'receita_bruta' : 'despesas_operacionais');
        $porGrupo = $lanc->groupBy($grupoDe);
        $soma = fn (string $g) => array_map(fn ($k) => (int) $porGrupo->get($g, collect())->where('mes', $k)->sum('valor'), $chaves);
        $linha = fn (string $tipo, string $rotulo, array $v, int $nivel = 0) => ['tipo' => $tipo, 'rotulo' => $rotulo, 'valores' => $v, 'total' => array_sum($v), 'nivel' => $nivel];
        $combina = fn (array ...$partes) => array_map(fn (...$xs) => array_sum($xs), ...$partes);
        $neg = fn (array $v) => array_map(fn ($x) => -$x, $v);
        $grupo = function (string $g) use ($porGrupo, $chaves, $linha, $soma) {
            $out = [$linha('grupo', CategoriaFinanceira::GRUPOS_DRE[$g][0], $soma($g))];
            foreach ($porGrupo->get($g, collect())->groupBy(fn ($l) => $l['categoria']?->caminho() ?? 'Sem categoria')->sortKeys() as $cat => $ls) {
                $out[] = $linha('detalhe', $cat, array_map(fn ($k) => (int) $ls->where('mes', $k)->sum('valor'), $chaves), 1);
            }

            return $out;
        };

        $rb = $soma('receita_bruta');
        $rl = $combina($rb, $neg($soma('deducoes')));
        $lb = $combina($rl, $neg($soma('custos')));
        $ro = $combina($lb, $neg($soma('despesas_operacionais')));
        $rf = $combina($soma('receitas_financeiras'), $neg($soma('despesas_financeiras')), $soma('outras_receitas'), $neg($soma('outras_despesas')));
        $rai = $combina($ro, $rf);
        $rlq = $combina($rai, $neg($soma('impostos_lucro')));

        $dre = array_merge(
            $grupo('receita_bruta'), $grupo('deducoes'), [$linha('resultado', '(=) Receita líquida', $rl)],
            $grupo('custos'), [$linha('resultado', '(=) Lucro bruto', $lb)],
            $grupo('despesas_operacionais'), [$linha('resultado', '(=) Resultado operacional', $ro)],
            $grupo('receitas_financeiras'), $grupo('despesas_financeiras'), $grupo('outras_receitas'), $grupo('outras_despesas'),
            [$linha('resultado', '(=) Resultado antes de IRPJ/CSLL', $rai)],
            $grupo('impostos_lucro'), [$linha('final', '(=) Resultado líquido', $rlq)],
        );
        // Grupos sem nenhum valor (e sem detalhe) não poluem a DRE.
        $dre = array_values(array_filter($dre, fn ($l) => $l['tipo'] !== 'grupo' || $l['total'] !== 0 || in_array($l['rotulo'], ['Receita bruta'], true)));

        return ['meses' => $meses, 'dre' => $dre, 'receita_liquida' => array_sum($rl), 'margem' => array_sum($rl) ? (int) round(array_sum($rlq) * 100 / array_sum($rl)) : null];
    }

    private function dre(CarbonImmutable $de, CarbonImmutable $ate, string $regime): array
    {
        $e = $this->dreEstrutura($de, $ate, $regime);
        $colunas = array_merge([['Linha', 'texto']], array_map(fn ($m) => [$m->format('m/Y'), 'dinheiro'], $e['meses']), [['Total', 'dinheiro']]);

        return ['colunas' => $colunas, 'linhas' => array_map(fn ($l) => array_merge([($l['nivel'] ? '   ' : '').$l['rotulo']], $l['valores'], [$l['total']]), $e['dre']),
            'estrutura' => $e];
    }

    // ---------------- Demais relatórios ----------------

    private function fluxo(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $l = $this->lancamentos($de, $ate, 'caixa');
        $linhas = [];
        $acum = 0;
        foreach ($this->meses($de, $ate) as $m) {
            $k = $m->format('Y-m');
            $e = (int) $l->where('mes', $k)->where('tipo', 'receber')->sum('valor');
            $s = (int) $l->where('mes', $k)->where('tipo', 'pagar')->sum('valor');
            $acum += $e - $s;
            $linhas[] = [$m->format('m/Y'), $e, $s, $e - $s, $acum];
        }

        return ['colunas' => [['Mês', 'texto'], ['Entradas', 'dinheiro'], ['Saídas', 'dinheiro'], ['Resultado do mês', 'dinheiro'], ['Acumulado no período', 'dinheiro']],
            'linhas' => $linhas, 'total' => ['Total', array_sum(array_column($linhas, 1)), array_sum(array_column($linhas, 2)), array_sum(array_column($linhas, 3)), $acum],
            'grafico' => ['tipo' => 'colunas', 'periodos' => array_column($linhas, 0),
                'series' => [['nome' => 'Entradas', 'serie' => 'receita', 'valores' => array_column($linhas, 1)], ['nome' => 'Saídas', 'serie' => 'despesa', 'valores' => array_column($linhas, 2)]]]];
    }

    private function previstoRealizado(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $tits = Titulo::with('parcelas.movimentos.estornoDe')->whereNull('cancelado_em')
            ->whereDate('competencia', '>=', $de->startOfMonth()->toDateString())->whereDate('competencia', '<=', $ate->toDateString())->get();
        $linhas = [];
        foreach ($this->meses($de, $ate) as $m) {
            $doMes = $tits->filter(fn ($t) => $t->competencia->isSameMonth($m));
            $rp = (int) $doMes->where('tipo', 'receber')->sum('valor_total_centavos');
            $rr = (int) $doMes->where('tipo', 'receber')->sum(fn ($t) => $t->liquidado());
            $dp = (int) $doMes->where('tipo', 'pagar')->sum('valor_total_centavos');
            $dr = (int) $doMes->where('tipo', 'pagar')->sum(fn ($t) => $t->liquidado());
            $linhas[] = [$m->format('m/Y'), $rp, $rr, $rp ? round($rr * 100 / $rp) : 0, $dp, $dr, $dp ? round($dr * 100 / $dp) : 0];
        }

        return ['colunas' => [['Competência', 'texto'], ['Receita prevista', 'dinheiro'], ['Receita realizada', 'dinheiro'], ['% recebido', 'pct'],
            ['Despesa prevista', 'dinheiro'], ['Despesa realizada', 'dinheiro'], ['% pago', 'pct']], 'linhas' => $linhas,
            'grafico' => ['tipo' => 'colunas', 'periodos' => array_column($linhas, 0), 'series' => [
                ['nome' => 'Receita prevista', 'serie' => 'comparativo', 'valores' => array_column($linhas, 1)], ['nome' => 'Receita realizada', 'serie' => 'receita', 'valores' => array_column($linhas, 2)]]]];
    }

    private function porCategoria(string $tipo, CarbonImmutable $de, CarbonImmutable $ate, string $regime): array
    {
        $l = $this->lancamentos($de, $ate, $regime, $tipo);
        $total = (int) $l->sum('valor');
        $linhas = $l->groupBy(fn ($x) => $x['categoria']?->caminho() ?? 'Sem categoria')
            ->map(fn ($g, $k) => [$k, (int) $g->sum('valor'), $total ? round($g->sum('valor') * 100 / $total, 1) : 0, $g->count()])
            ->sortByDesc(1)->values()->all();

        return ['colunas' => [['Categoria', 'texto'], ['Valor', 'dinheiro'], ['% do total', 'pct'], ['Lançamentos', 'numero']], 'linhas' => $linhas,
            'total' => ['Total', $total, $total ? 100 : 0, $l->count()],
            'grafico' => ['tipo' => 'barras', 'serie' => $tipo === 'pagar' ? 'despesa' : 'receita', 'itens' => array_map(fn ($x) => ['rotulo' => $x[0], 'valor' => $x[1]], array_slice($linhas, 0, 12))]];
    }

    private function porContraparte(string $tipo, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $tits = Titulo::with('parcelas.movimentos.estornoDe')->whereNull('cancelado_em')->where('tipo', $tipo)
            ->whereDate('competencia', '>=', $de->startOfMonth()->toDateString())->whereDate('competencia', '<=', $ate->toDateString())->get();
        $linhas = $tits->groupBy(fn ($t) => $t->contraparte ?: 'Não informado')
            ->map(fn ($g, $k) => [$k, (int) $g->sum('valor_total_centavos'), (int) $g->sum(fn ($t) => $t->liquidado()), (int) $g->sum(fn ($t) => $t->saldo()),
                (int) $g->sum(fn ($t) => $t->parcelas->filter->vencida()->sum(fn ($p) => $p->saldo())), $g->count()])
            ->sortByDesc(1)->values()->all();
        $rec = $tipo === 'receber';

        return ['colunas' => [[$rec ? 'Cliente' : 'Fornecedor', 'texto'], ['Lançado', 'dinheiro'], [$rec ? 'Recebido' : 'Pago', 'dinheiro'], ['Em aberto', 'dinheiro'],
            ['Vencido', 'dinheiro'], ['Lançamentos', 'numero']], 'linhas' => $linhas,
            'total' => ['Total', ...array_map(fn ($i) => array_sum(array_column($linhas, $i)), [1, 2, 3, 4, 5])],
            'grafico' => ['tipo' => 'barras', 'serie' => $rec ? 'receita' : 'despesa', 'itens' => array_map(fn ($x) => ['rotulo' => $x[0], 'valor' => $x[1]], array_slice($linhas, 0, 10))]];
    }

    /** Receita faturada (faturas) − custo com prestadores (cobranças) por contrato, na competência. */
    private function contratos(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $titulo = fn (string $classe) => Titulo::whereNull('cancelado_em')->where('origem_type', (new $classe)->getMorphClass())
            ->whereDate('competencia', '>=', $de->startOfMonth()->toDateString())->whereDate('competencia', '<=', $ate->toDateString())->get();
        $receitas = $titulo(Fatura::class)->groupBy(fn ($t) => Fatura::find($t->origem_id)?->contrato_id);
        $custos = $titulo(Cobranca::class)->groupBy(fn ($t) => Cobranca::find($t->origem_id)?->contrato_id);
        $linhas = Contrato::with('cliente')->orderBy('numero')->get()->map(function ($c) use ($receitas, $custos) {
            $r = (int) $receitas->get($c->id, collect())->sum('valor_total_centavos');
            $k = (int) $custos->get($c->id, collect())->sum('valor_total_centavos');

            return [$c->numero.' — '.$c->descricao, $c->cliente->razao_social ?? '—', $r, $k, $r - $k, $r ? round(($r - $k) * 100 / $r, 1) : 0];
        })->filter(fn ($l) => $l[2] || $l[3])->values()->all();

        return ['colunas' => [['Contrato', 'texto'], ['Cliente', 'texto'], ['Receita', 'dinheiro'], ['Custo com prestadores', 'dinheiro'], ['Margem', 'dinheiro'], ['Margem %', 'pct']],
            'linhas' => $linhas, 'total' => ['Total', '', ...array_map(fn ($i) => array_sum(array_column($linhas, $i)), [2, 3, 4]), null]];
    }

    private function centros(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $agg = [];
        foreach ($this->meses($de, $ate) as $m) {
            foreach ($this->pl->porCentro($m) as $c) {
                $agg[$c['rotulo']] = ($agg[$c['rotulo']] ?? 0) + $c['valor'];
            }
        }
        arsort($agg);
        $total = array_sum($agg);
        $linhas = array_map(fn ($k, $v) => [$k, $v, $total ? round($v * 100 / $total, 1) : 0], array_keys($agg), $agg);

        return ['colunas' => [['Centro de custo', 'texto'], ['Despesas', 'dinheiro'], ['% do total', 'pct']], 'linhas' => $linhas, 'total' => ['Total', $total, $total ? 100 : 0],
            'grafico' => ['tipo' => 'barras', 'serie' => 'despesa', 'itens' => array_map(fn ($l) => ['rotulo' => $l[0], 'valor' => $l[1]], $linhas)]];
    }

    private function cartoes(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $linhas = FaturaCartao::with('cartao', 'parcelas.movimentos.estornoDe', 'encargos')
            ->whereDate('vencimento', '>=', $de->toDateString())->whereDate('vencimento', '<=', $ate->toDateString())->orderBy('vencimento')->get()
            ->filter(fn ($f) => $f->total() > 0)
            ->map(fn ($f) => [$f->cartao->rotulo(), $f->rotulo(), $f->vencimento->format('d/m/Y'), $f->total(), $f->total() - $f->saldo(), $f->saldo(),
                $f->encargos?->valor_total_centavos ?? 0])->values()->all();

        return ['colunas' => [['Cartão', 'texto'], ['Fatura', 'texto'], ['Vencimento', 'texto'], ['Compras', 'dinheiro'], ['Pago', 'dinheiro'], ['Em aberto', 'dinheiro'], ['Encargos', 'dinheiro']],
            'linhas' => $linhas, 'total' => ['Total', '', '', ...array_map(fn ($i) => array_sum(array_column($linhas, $i)), [3, 4, 5, 6])]];
    }
}
