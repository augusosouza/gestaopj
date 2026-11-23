<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\Evento;
use App\Models\FaturaCartao;
use App\Models\Parcela;
use App\Models\SugestaoDispensada;
use App\Models\Titulo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Motor de sugestões (FIN-12) — regras fixas sobre dados reais, sem IA generativa.
 *
 * Cada sugestão traz: motivo, valores, origem e uma ação. A "chave" identifica
 * a situação; dispensar ou adiar uma chave não esconde situações novas
 * (ex.: outro atraso do mesmo cliente gera outra chave).
 */
final class Sugestoes
{
    public const TIPOS = [
        'caixa' => 'Saldo projetado insuficiente', 'inadimplencia' => 'Cliente com atraso', 'recorrente' => 'Atraso recorrente',
        'parcial' => 'Recebimento parcial', 'sem_categoria' => 'Lançamento sem categoria', 'duplicado' => 'Pagamento possivelmente duplicado',
        'fatura' => 'Fatura acima do normal', 'parcelados' => 'Compromisso com parcelamentos', 'bancos' => 'Extrato e conciliação', 'orcamento' => 'Orçamento',
    ];

    public function __construct(private Financeiro $fin, private PosicaoFinanceira $posicao, private Recebimentos $receb) {}

    /** @return Collection<int, array{chave: string, tipo: string, nivel: string, titulo: string, motivo: string, acao: string, url: string}> */
    public function gerar(bool $incluirDispensadas = false): Collection
    {
        $s = collect();
        $hoje = CarbonImmutable::today();

        // 1. Caixa: algum dia dos próximos 30 fica negativo?
        $proj = $this->posicao->projetar(30);
        if ($proj['negativo']) {
            $s->push($this->item('caixa:'.$proj['negativo']['data']->toDateString(), 'caixa', 'bad',
                'Saldo projetado negativo em '.$proj['negativo']['data']->format('d/m'),
                'Com o saldo de hoje ('.brl($proj['saldo_atual']).') e os vencimentos previstos, faltam '.brl($proj['negativo']['deficit'])
                .' nesse dia. Revise datas de pagamento, antecipe recebimentos ou reforce a conta.', 'Abrir Meu Caixa', route('financeiro.caixa')));
        }

        // 2. Clientes com atraso e 3. atraso recorrente
        $vencidas = $this->receb->vencidas();
        foreach ($this->receb->porCliente($vencidas) as $c) {
            $s->push($this->item('inadimplencia:'.$c['chave'].':'.$c['itens']->pluck('parcela.id')->sort()->implode('-'), 'inadimplencia',
                $c['maior_atraso'] > 15 ? 'bad' : 'warn',
                "{$c['cliente']} possui ".brl($c['total']).' vencidos há '.$c['maior_atraso'].' dia(s)',
                $c['qtd'].' parcela(s) em atraso. Deseja revisar a cobrança?', 'Ver inadimplência', route('financeiro.inadimplencia', ['cliente' => $c['cliente']])));
        }
        foreach ($this->receb->atrasosRecorrentes() as $c) {
            $s->push($this->item('recorrente:'.$c['chave'].':'.$hoje->format('Y-m'), 'recorrente', 'warn',
                "{$c['cliente']} atrasou os últimos {$c['atrasos']} vencimentos",
                'Avalie revisar as condições de pagamento (prazo, forma de cobrança ou garantias).', 'Ver recebimentos',
                route('financeiro.index', ['tipo' => 'receber', 'q' => $c['cliente'], 'periodo' => 'todos'])));
        }

        // 4. Recebimento parcial
        foreach ($vencidas->merge($this->parciaisNoPrazo())->where('parcial', true)->unique(fn ($v) => $v['parcela']->id) as $v) {
            $p = $v['parcela'];
            $s->push($this->item('parcial:'.$p->id.':'.$p->abatido(), 'parcial', 'info',
                "{$v['cliente']} pagou ".brl($p->abatido()).' de '.brl($p->valor_centavos),
                'Restam '.brl($p->saldo()).' na parcela '.$p->numero.' de "'.$p->titulo->descricao.'".', 'Abrir lançamento', route('financeiro.titulo', $p->titulo)));
        }

        // 5. Sem categoria
        $semCat = Titulo::whereNull('cancelado_em')->whereNull('categoria_id')->count();
        if ($semCat > 0) {
            $s->push($this->item('sem_categoria:'.$semCat, 'sem_categoria', 'info', "{$semCat} lançamento(s) sem categoria",
                'Sem categoria, os gastos não aparecem nas análises nem nos orçamentos.', 'Classificar',
                route('financeiro.index', ['periodo' => 'todos', 'categoria' => 'nenhuma'])));
        }

        // 6. Possível duplicidade: mesma contraparte, mesmo valor, competências até 3 dias de distância (criação).
        $pagar = Titulo::whereNull('cancelado_em')->where('tipo', 'pagar')->whereNotNull('contraparte')->where('created_at', '>=', $hoje->subDays(60))->orderBy('id')->get();
        foreach ($pagar->groupBy(fn ($t) => mb_strtolower(trim($t->contraparte)).'|'.$t->valor_total_centavos) as $grupo) {
            $grupo = $grupo->values();
            for ($i = 1; $i < $grupo->count(); $i++) {
                [$a, $b] = [$grupo[$i - 1], $grupo[$i]];
                if ($a->created_at->diffInDays($b->created_at) <= 3 && ! ($a->origem_type && $a->origem_type === $b->origem_type && $a->origem_type !== (new Compra)->getMorphClass())) {
                    $s->push($this->item("duplicado:{$a->id}-{$b->id}", 'duplicado', 'warn',
                        'Possível pagamento duplicado: '.$a->contraparte.' · '.brl($a->valor_total_centavos),
                        'Dois lançamentos iguais registrados em '.$a->created_at->format('d/m').' e '.$b->created_at->format('d/m').' (#'.$a->id.' e #'.$b->id.'). Confira se não é o mesmo.',
                        'Conferir', route('financeiro.titulo', $b)));
                }
            }
        }

        // 7. Fatura de cartão acima da média das três anteriores (+30%).
        foreach (FaturaCartao::with('cartao', 'parcelas.movimentos.estornoDe')->whereNull('paga_em')->whereDate('vencimento', '>=', $hoje)
            ->whereDate('vencimento', '<=', $hoje->addDays(40))->get() as $f) {
            $anteriores = FaturaCartao::with('parcelas')->where('cartao_id', $f->cartao_id)->whereDate('referencia', '<', $f->referencia)
                ->orderByDesc('referencia')->limit(3)->get()->map->total()->filter();
            if ($anteriores->count() >= 2 && $f->total() > 1.3 * $anteriores->avg()) {
                $s->push($this->item("fatura:{$f->id}:{$f->total()}", 'fatura', 'warn',
                    'Fatura '.$f->cartao->nome.' '.$f->rotulo().': '.brl($f->total()),
                    'Está '.round(($f->total() / $anteriores->avg() - 1) * 100).'% acima da média das anteriores ('.brl((int) $anteriores->avg()).'). Veja as compras que a compõem.',
                    'Abrir fatura', route('cartoes.fatura', $f)));
            }
        }

        // 8. Parcelamentos: quanto já está comprometido nos próximos 6 meses.
        $futuro = $this->posicao->abertas()->filter(fn (Parcela $p) => $p->titulo->origem_type === (new Compra)->getMorphClass()
            && $p->vencimento->gt($hoje) && $p->vencimento->lte($hoje->addMonths(6)));
        if ($futuro->count() >= 3) {
            $s->push($this->item('parcelados:'.$hoje->format('Y-m').':'.$futuro->sum(fn ($p) => $p->saldo()), 'parcelados', 'info',
                'Compras parceladas comprometem '.brl((int) $futuro->sum(fn ($p) => $p->saldo())).' nos próximos 6 meses',
                $futuro->count().' parcela(s) de '.$futuro->pluck('titulo_id')->unique()->count().' compra(s). Considere isso antes de novas compras a prazo.',
                'Ver parcelamentos', route('compras.index', ['aba' => 'parcelamentos'])));
        }

        // 10. Orçamentos do mês: perto do limite (≥ 90%) ou ultrapassados.
        foreach (app(Planejamento::class)->acompanhamento($hoje->startOfMonth()) as $o) {
            if ($o['estado'] === 'ok') {
                continue;
            }
            $s->push($this->item('orcamento:'.$o['orcamento']->id.':'.$o['estado'], 'orcamento', $o['estado'] === 'excedido' ? 'bad' : 'warn',
                ($o['estado'] === 'excedido' ? 'Orçamento ultrapassado: ' : 'Orçamento perto do limite: ').$o['rotulo'],
                'Comprometido '.brl($o['comprometido']).' de '.brl($o['orcado']).' ('.$o['pct'].'%)'.($o['disponivel'] < 0 ? ', '.brl(-$o['disponivel']).' acima do limite.' : '.'),
                'Ver planejamento', route('planejamento.index')));
        }

        // 9. Extratos e conciliação (mesmas regras dos lembretes bancários).
        foreach ($this->fin->acoesNecessarias() as $a) {
            if (str_starts_with($a['titulo'], 'Extrato') || str_starts_with($a['titulo'], 'Conciliação')) {
                $s->push($this->item('bancos:'.md5($a['titulo'].$a['texto']), 'bancos', $a['tipo'], $a['titulo'], $a['texto'], $a['acao'], $a['url']));
            }
        }

        if ($incluirDispensadas) {
            return $s->values();
        }
        $dispensadas = SugestaoDispensada::where(fn ($q) => $q->whereNull('ate')->orWhereDate('ate', '>=', $hoje))->pluck('chave')->flip();

        return $s->reject(fn ($x) => $dispensadas->has($x['chave']))
            ->sortBy(fn ($x) => ['bad' => 0, 'warn' => 1, 'info' => 2][$x['nivel']] ?? 3)->values();
    }

    /** Dispensa para sempre ($dias = null) ou adia por $dias. Fica registrado na auditoria. */
    public function dispensar(string $chave, ?int $dias, ?string $motivo): SugestaoDispensada
    {
        $d = SugestaoDispensada::updateOrCreate(['chave' => mb_substr($chave, 0, 160)],
            ['ate' => $dias ? CarbonImmutable::today()->addDays($dias)->toDateString() : null, 'motivo' => $motivo, 'user_id' => auth()->id()]);
        Evento::registrar($dias ? 'sugestao.adiada' : 'sugestao.dispensada', $d, ['chave' => $chave, 'dias' => $dias, 'motivo' => $motivo]);

        return $d;
    }

    /** Parcelas a receber parcialmente pagas que ainda não venceram. */
    private function parciaisNoPrazo(): Collection
    {
        return Parcela::with('titulo', 'movimentos.estornoDe')
            ->whereHas('titulo', fn ($q) => $q->where('tipo', 'receber')->whereNull('cancelado_em'))
            ->whereDate('vencimento', '>=', CarbonImmutable::today())->get()
            ->filter(fn (Parcela $p) => $p->saldo() > 0 && $p->abatido() > 0)
            ->map(fn (Parcela $p) => ['parcela' => $p, 'parcial' => true, 'cliente' => $p->titulo->contraparte ?: 'Cliente']);
    }

    private function item(string $chave, string $tipo, string $nivel, string $titulo, string $motivo, string $acao, string $url): array
    {
        return compact('chave', 'tipo', 'nivel', 'titulo', 'motivo', 'acao', 'url');
    }
}
