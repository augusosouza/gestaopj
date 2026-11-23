@extends('layouts.app')
@section('titulo', $c->descricao)
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@php($url = fn ($a) => route('compras.show', [$c, 'aba' => $a]))
@php($movs = $t->parcelas->flatMap->movimentos->sortBy('id'))
@php($pagas = $t->parcelas->filter(fn ($p) => $p->saldo() === 0)->count())
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Compras' => route('compras.index'), $c->descricao => null]" :titulo="$c->descricao"
  :sub="($c->fornecedor ?? 'Fornecedor não informado').' · '.$c->data_compra->format('d/m/Y').' · '.($c->categoria?->nome ?? 'sem categoria')">
  <x-slot:acoes><a class="botao sec" href="{{ route('financeiro.titulo', $t) }}">Ver no financeiro</a></x-slot:acoes>
</x-cabecalho>
@if ($c->cancelada())<div class="aviso info">Cancelada em {{ $c->cancelada_em->format('d/m/Y') }}: {{ $c->motivo_cancelamento }}</div>@endif

<section class="cards" aria-label="Valores da compra">
  <div class="card"><div class="rot">Total contratado</div><div class="num">{{ brl($c->total_centavos) }}</div>
    <div class="det">{{ brl($c->valor_bruto_centavos) }}@if ($c->desconto_centavos) − desc. {{ brl($c->desconto_centavos) }}@endif @if ($c->juros_centavos) + juros {{ brl($c->juros_centavos) }}@endif</div></div>
  <div class="card"><div class="rot">Pago</div><div class="num">{{ brl($t->liquidado()) }}</div>
    <div class="progresso serie-despesa" role="img" aria-label="{{ round($t->liquidado() * 100 / max(1, $c->total_centavos)) }}% quitado"><span style="width:{{ round($t->liquidado() * 100 / max(1, $c->total_centavos)) }}%"></span></div>
    <div class="det">{{ $pagas }} de {{ $t->parcelas->count() }} parcela(s)</div></div>
  <div class="card"><div class="rot">Restante</div><div class="num">{{ brl($c->cancelada() ? 0 : $t->saldo()) }}</div><div class="det">{{ $t->vencido() ? 'há parcela vencida' : 'em dia' }}</div></div>
  <div class="card"><div class="rot">Forma</div><div class="num texto">{{ $c->noCartao() ? $c->cartao?->rotulo() : \App\Models\Compra::MODALIDADES[$c->modalidade] }}</div>
    <div class="det">{{ $c->noCartao() ? 'paga pelas faturas' : ($c->conta?->nome ?? 'conta não definida') }}</div></div>
</section>
<div style="margin-top:20px"></div>
<x-abas :itens="[
  ['Informações', $url('informacoes'), $aba === 'informacoes'],
  ['Itens', $url('itens'), $aba === 'itens', $c->itens->count() ?: null],
  ['Parcelas', $url('parcelas'), $aba === 'parcelas', $t->parcelas->count()],
  ['Pagamentos', $url('pagamentos'), $aba === 'pagamentos', $movs->count()],
  ['Histórico', $url('historico'), $aba === 'historico'],
]" />

@if ($aba === 'informacoes')
  <div class="duas">
    <div class="painel"><dl class="dados">
      <dt>Fornecedor</dt><dd>{{ $c->fornecedor ?? '—' }}@if ($c->fornecedor_documento) · {{ \App\Support\Documento::formatar($c->fornecedor_documento) }}@endif</dd>
      <dt>Data da compra</dt><dd>{{ $c->data_compra->format('d/m/Y') }}</dd>
      <dt>Categoria</dt><dd>{{ $c->categoria?->nome ?? '—' }}</dd>
      <dt>Entrada</dt><dd>{{ $c->entrada_centavos ? brl($c->entrada_centavos) : '—' }}</dd>
      <dt>Documento</dt><dd>{{ trim(($c->nota_tipo ?? '').' '.($c->nota_numero ?? '')) ?: '—' }}@if ($c->nota_chave)<br><code style="font-size:12px">{{ $c->nota_chave }}</code>@endif</dd>
      <dt>Garantia</dt><dd>@if ($c->garantia_ate){{ $c->garantia_ate->format('d/m/Y') }} @if ($c->garantia_ate->isPast())<span class="tag">vencida</span>@else<span class="tag ok">vigente</span>@endif @else — @endif</dd>
      <dt>Observação</dt><dd>{{ $c->observacao ?? '—' }}</dd>
    </dl></div>
    <div class="painel">
      <strong>Como esta compra aparece no financeiro</strong>
      <p class="sub" style="font-size:13.5px;margin:0 0 10px">Ela gerou <strong>um único lançamento a pagar</strong> com {{ $t->parcelas->count() }} parcela(s).
        @if ($c->noCartao()) As parcelas entram nas faturas do cartão; pagar a fatura quita as parcelas, sem lançar a despesa de novo. @endif
        Os pagamentos ficam registrados no lançamento.</p>
      @if ($opera && ! $c->cancelada() && $t->liquidado() === 0)
        <form method="post" action="{{ route('compras.cancelar', $c) }}" style="display:flex;gap:8px;margin-top:12px">@csrf
          <input name="motivo" required placeholder="Motivo do cancelamento" aria-label="Motivo do cancelamento"><button class="perigo">Cancelar compra</button></form>
      @elseif (! $c->cancelada() && $t->liquidado() > 0)
        <p class="sub" style="font-size:12.5px">Para cancelar, estorne antes os pagamentos registrados. Devoluções parciais: registre um desconto/abatimento nas parcelas em aberto.</p>
      @endif
    </div>
  </div>
@elseif ($aba === 'itens')
  <div class="tabela"><table>
    <thead><tr><th>Item</th><th class="n">Quantidade</th><th class="n">Valor unitário</th><th class="n">Total</th></tr></thead>
    <tbody>
    @forelse ($c->itens as $i)
      <tr><td>{{ $i->descricao }}</td><td class="n">{{ rtrim(rtrim(number_format((float) $i->quantidade, 3, ',', '.'), '0'), ',') }}</td>
        <td class="n">{{ brl($i->valor_unitario_centavos) }}</td><td class="n">{{ brl($i->total_centavos) }}</td></tr>
    @empty
      <tr><td colspan="4" class="vazio">Compra registrada sem detalhamento de itens.</td></tr>
    @endforelse
    </tbody>
  </table></div>
@elseif ($aba === 'parcelas')
  <div class="tabela"><table>
    <thead><tr><th>Parcela</th><th>Vencimento</th><th class="n">Valor</th><th class="n">Saldo</th><th>Situação</th><th>Fatura</th></tr></thead>
    <tbody>
    @foreach ($t->parcelas as $p)
      <tr><td>{{ $p->numero }}/{{ $t->parcelas->count() }}@if ($c->entrada_centavos && $p->numero === 1) · entrada @endif</td><td>{{ $p->vencimento->format('d/m/Y') }}</td>
        <td class="n">{{ brl($p->valor_centavos) }}</td><td class="n">{{ brl($p->saldo()) }}</td>
        <td>@if ($p->saldo() === 0)<span class="tag ok">Paga</span>@elseif ($p->vencida())<span class="tag bad">Vencida</span>@elseif ($p->abatido() > 0)<span class="tag info">Parcial</span>@else<span class="tag warn">A pagar</span>@endif</td>
        <td>@if ($p->fatura)<a href="{{ route('cartoes.fatura', $p->fatura) }}">{{ $p->fatura->rotulo() }}</a>@else — @endif</td></tr>
    @endforeach
    </tbody>
  </table></div>
  @if ($opera && ! $c->noCartao() && $t->saldo() > 0)<p style="margin-top:10px"><a class="botao" href="{{ route('financeiro.titulo', [$t, 'aba' => 'pagamentos']) }}#registrar">Registrar pagamento</a></p>@endif
@elseif ($aba === 'pagamentos')
  <div class="tabela"><table>
    <thead><tr><th>Data</th><th>Parcela</th><th>Movimento</th><th>Conta</th><th class="n">Valor</th></tr></thead>
    <tbody>
    @forelse ($movs as $m)
      <tr @if ($m->tipo === 'estorno' || $m->estornado()) style="color:var(--muted)" @endif><td>{{ $m->data->format('d/m/Y') }}</td><td>{{ $m->parcela->numero }}</td>
        <td>{{ $m->tipo === 'estorno' ? 'Estorno' : 'Pagamento' }}@if ($m->estornado()) <span class="tag">estornado</span>@endif @if ($m->observacao)<br><span style="font-size:12.5px">{{ $m->observacao }}</span>@endif</td>
        <td>{{ $m->conta->nome }}</td><td class="n">{{ $m->tipo === 'estorno' ? '−' : '' }}{{ brl($m->valor_centavos) }}</td></tr>
    @empty
      <tr><td colspan="5" class="vazio">Nenhum pagamento registrado.</td></tr>
    @endforelse
    </tbody>
  </table></div>
@else
  @include('partials.historico', ['eventos' => $historico])
@endif
@endsection
