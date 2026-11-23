<?php

namespace App\Services;

use App\Models\ContaFinanceira;
use App\Models\Parcela;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Posição financeira e projeção de caixa (FIN-10, "Meu Caixa").
 *
 * Responde: o que posso fazer com o dinheiro de hoje sem comprometer os próximos vencimentos?
 *  - Projeta DIA A DIA, pela data de cada parcela (um recebimento depois de um pagamento não "cobre" o pagamento).
 *  - Contas a pagar vencidas entram hoje (já deveriam ter saído).
 *  - Contas a receber vencidas ficam de fora por padrão (não há data confiável para entrarem) e aparecem à parte.
 *  - Parcelas de cartão aparecem como UMA saída por fatura, no vencimento da fatura.
 */
final class PosicaoFinanceira
{
    /** Parcelas com saldo em aberto, de títulos não cancelados. */
    public function abertas(): Collection
    {
        return Parcela::with(['titulo' => fn ($q) => $q->withCount('parcelas'), 'movimentos.estornoDe', 'fatura.cartao'])
            ->whereHas('titulo', fn ($q) => $q->whereNull('cancelado_em'))->get()
            ->filter(fn (Parcela $p) => $p->saldo() > 0)->values();
    }

    /**
     * Compromissos até $ate (inclusive), agrupando parcelas de cartão por fatura.
     *
     * @return Collection<int, array{data: CarbonImmutable, vencimento: CarbonImmutable, tipo: string, descricao: string, contraparte: ?string, valor: int, url: string, atrasado: bool, cartao: bool}>
     */
    public function compromissos(CarbonImmutable $ate, bool $incluirVencidosReceber = false, ?Collection $abertas = null): Collection
    {
        $hoje = CarbonImmutable::today();
        $abertas ??= $this->abertas();
        $eventos = collect();

        foreach ($abertas->whereNull('fatura_cartao_id') as $p) {
            $venc = CarbonImmutable::instance($p->vencimento);
            if ($venc->gt($ate)) {
                continue;
            }
            $receber = $p->titulo->receber();
            $atrasado = $venc->lt($hoje);
            if ($atrasado && $receber && ! $incluirVencidosReceber) {
                continue;
            }
            $n = (int) $p->titulo->parcelas_count;
            $eventos->push([
                'data' => $atrasado ? $hoje : $venc, 'vencimento' => $venc, 'tipo' => $receber ? 'receber' : 'pagar',
                'descricao' => $p->titulo->descricao.($n > 1 ? " ({$p->numero}/{$n})" : ''), 'contraparte' => $p->titulo->contraparte,
                'valor' => $p->saldo(), 'url' => route('financeiro.titulo', $p->titulo), 'atrasado' => $atrasado, 'cartao' => false,
            ]);
        }
        foreach ($abertas->whereNotNull('fatura_cartao_id')->groupBy('fatura_cartao_id') as $ps) {
            $f = $ps->first()->fatura;
            $venc = CarbonImmutable::instance($f->vencimento);
            if ($venc->gt($ate)) {
                continue;
            }
            $atrasado = $venc->lt($hoje);
            $eventos->push([
                'data' => $atrasado ? $hoje : $venc, 'vencimento' => $venc, 'tipo' => 'pagar',
                'descricao' => 'Fatura '.$f->cartao->nome.' — '.$f->rotulo(), 'contraparte' => $f->cartao->instituicao,
                'valor' => (int) $ps->sum(fn ($p) => $p->saldo()), 'url' => route('cartoes.fatura', $f), 'atrasado' => $atrasado, 'cartao' => true,
            ]);
        }

        return $eventos->sortBy(fn ($e) => [$e['data']->toDateString(), $e['tipo'] === 'pagar' ? 0 : 1])->values();
    }

    /**
     * Projeção diária do saldo.
     *
     * @param  list<int>|null  $contaIds  contas consideradas no saldo de partida (padrão: todas as ativas)
     * @param  array{valor: int, data: string, tipo: string}|null  $simulacao  gasto/entrada hipotético — não grava nada
     */
    public function projetar(int $dias = 30, ?array $contaIds = null, bool $incluirVencidosReceber = false, ?array $simulacao = null): array
    {
        $hoje = CarbonImmutable::today();
        $ate = $hoje->addDays($dias);
        $contas = ContaFinanceira::where('ativa', true)->when($contaIds, fn ($q) => $q->whereIn('id', $contaIds))->orderBy('nome')->get();
        $saldoAtual = (int) $contas->sum(fn (ContaFinanceira $c) => $c->saldo());
        $abertas = $this->abertas();
        $eventos = $this->compromissos($ate, $incluirVencidosReceber, $abertas);

        if ($simulacao && $simulacao['valor'] > 0) {
            $d = CarbonImmutable::parse($simulacao['data']);
            $eventos->push(['data' => $d->lt($hoje) ? $hoje : $d, 'vencimento' => $d, 'tipo' => $simulacao['tipo'] === 'receber' ? 'receber' : 'pagar',
                'descricao' => 'Simulação (não gravada)', 'contraparte' => null, 'valor' => $simulacao['valor'], 'url' => '#simular', 'atrasado' => false,
                'cartao' => false, 'simulado' => true]);
        }

        $porDia = $eventos->groupBy(fn ($e) => $e['data']->toDateString());
        $saldo = $saldoAtual;
        $serie = [];
        $min = ['valor' => $saldoAtual, 'data' => $hoje];
        $negativo = null;
        for ($d = $hoje; $d->lte($ate); $d = $d->addDay()) {
            $doDia = $porDia->get($d->toDateString(), collect());
            $ent = (int) $doDia->where('tipo', 'receber')->sum('valor');
            $sai = (int) $doDia->where('tipo', 'pagar')->sum('valor');
            $saldo += $ent - $sai;
            $serie[] = ['data' => $d, 'entradas' => $ent, 'saidas' => $sai, 'saldo' => $saldo];
            if ($saldo < $min['valor']) {
                $min = ['valor' => $saldo, 'data' => $d];
            }
            if ($saldo < 0 && ! $negativo) {
                $negativo = ['data' => $d, 'deficit' => -$saldo];
            }
        }

        $vencidosReceber = $abertas->filter(fn (Parcela $p) => $p->titulo->receber() && $p->vencimento->lt($hoje))->sum(fn ($p) => $p->saldo());
        $cartoes = (int) $abertas->whereNotNull('fatura_cartao_id')->sum(fn ($p) => $p->saldo());
        $aReceber = (int) $eventos->where('tipo', 'receber')->reject(fn ($e) => $e['simulado'] ?? false)->sum('valor');
        $aPagar = (int) $eventos->where('tipo', 'pagar')->reject(fn ($e) => $e['simulado'] ?? false)->sum('valor');

        return [
            'hoje' => $hoje, 'ate' => $ate, 'dias' => $dias, 'contas' => $contas,
            'saldo_atual' => $saldoAtual,
            'a_receber' => $aReceber, 'a_pagar' => $aPagar,
            // Sem nenhuma entrada: quanto sobra depois de pagar tudo do período.
            'apos_pagar' => $saldoAtual - $aPagar,
            'saldo_final' => $saldo,
            'minimo' => $min,
            'negativo' => $negativo,
            // Quanto pode sair hoje sem deixar o saldo negativo em nenhum dia do período.
            'livre' => max(0, $min['valor']),
            'vencidos_receber' => (int) $vencidosReceber,
            'cartoes' => $cartoes,
            'eventos' => $eventos, 'serie' => $serie,
            'simulacao' => $simulacao,
        ];
    }

    /** Saldo confirmado pelo banco (último OFX com LEDGERBAL) e a diferença para o registrado naquela data. */
    public function confirmados(Collection $contas): Collection
    {
        return $contas->filter(fn (ContaFinanceira $c) => $c->saldo_extrato_em)->map(fn (ContaFinanceira $c) => [
            'conta' => $c, 'valor' => $c->saldo_extrato_centavos, 'em' => $c->saldo_extrato_em,
            'diferenca' => $c->saldo($c->saldo_extrato_em) - $c->saldo_extrato_centavos,
        ])->values();
    }
}
