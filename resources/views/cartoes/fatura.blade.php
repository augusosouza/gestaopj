@extends('layouts.app')
@section('titulo', 'Fatura '.$f->rotulo())
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Cartões' => route('cartoes.index'), $f->cartao->rotulo() => route('cartoes.show', $f->cartao), 'Fatura '.$f->rotulo() => null]"
  :titulo="'Fatura '.$f->rotulo()" :sub="$f->cartao->rotulo().' · fecha '.$f->fechamento->format('d/m/Y').' · vence '.$f->vencimento->format('d/m/Y')">
  <div style="margin-top:6px">@include('cartoes._situacao')</div>
  <x-slot:acoes>
    @if ($anterior)<a class="botao sec" href="{{ route('cartoes.fatura', $anterior) }}">‹ {{ $anterior->rotulo() }}</a>@endif
    @if ($seguinte)<a class="botao sec" href="{{ route('cartoes.fatura', $seguinte) }}">{{ $seguinte->rotulo() }} ›</a>@endif
  </x-slot:acoes>
</x-cabecalho>
<section class="cards" aria-label="Valores da fatura">
  <div class="card"><div class="rot">Total das compras</div><div class="num">{{ brl($f->total()) }}</div><div class="det">{{ $f->parcelas->count() }} lançamento(s)</div></div>
  <div class="card"><div class="rot">Em aberto</div><div class="num">{{ brl($f->saldo()) }}</div><div class="det">{{ $f->saldo() ? 'a pagar' : 'nada a pagar' }}</div></div>
  <div class="card"><div class="rot">Encargos</div><div class="num">{{ $f->encargos ? brl($f->encargos->valor_total_centavos) : '—' }}</div><div class="det">juros, IOF, multa (lançados à parte)</div></div>
  <div class="card"><div class="rot">Paga em</div><div class="num texto">{{ $f->paga_em?->format('d/m/Y') ?? '—' }}</div><div class="det">{{ $f->paga_em ? 'pagamento registrado' : '' }}</div></div>
</section>

<h2>O que compõe esta fatura</h2>
<div class="tabela"><table>
  <thead><tr><th>Data da compra</th><th>Compra</th><th>Parcela</th><th class="n">Valor</th><th>Situação</th></tr></thead>
  <tbody>
  @forelse ($f->parcelas as $p)
    @php($compra = $p->titulo->origem)
    <tr><td>{{ $compra?->data_compra?->format('d/m/Y') ?? '—' }}</td>
      <td>@if ($compra instanceof \App\Models\Compra)<a href="{{ route('compras.show', $compra) }}">{{ $compra->descricao }}</a><br><span class="sub" style="font-size:12.5px">{{ $compra->fornecedor ?? '' }}</span>@else{{ $p->titulo->descricao }}@endif</td>
      <td>{{ $p->numero }}/{{ $p->titulo->parcelas()->count() }}</td>
      <td class="n">{{ brl($p->valor_centavos) }}</td>
      <td>@if ($p->saldo() === 0)<span class="tag ok">Paga</span>@else<span class="tag warn">Em aberto</span>@endif</td></tr>
  @empty
    <tr><td colspan="5" class="vazio">Nenhuma compra nesta fatura.</td></tr>
  @endforelse
  </tbody>
  @if ($f->encargos)
    <tfoot><tr><td></td><td><a href="{{ route('financeiro.titulo', $f->encargos) }}">{{ $f->encargos->descricao }}</a></td><td></td><td class="n">{{ brl($f->encargos->valor_total_centavos) }}</td><td><span class="tag ok">Paga</span></td></tr></tfoot>
  @endif
</table></div>

@if ($opera && $f->saldo() > 0)
  @if ($f->fechamento->gt(now()->startOfDay()))
    <div class="aviso info" id="pagar" style="margin-top:14px">A fatura ainda está aberta: fecha em {{ $f->fechamento->format('d/m/Y') }}. O pagamento fica disponível depois do fechamento.</div>
  @else
    <form method="post" action="{{ route('cartoes.pagar', $f) }}" class="painel" id="pagar" style="margin-top:14px">@csrf
      <input type="hidden" name="chave" value="{{ $chave }}">
      <strong>Pagar fatura · {{ brl($f->saldo()) }}</strong>
      <div class="grade" style="margin-top:10px">
        <div class="campo"><label for="conta_id">Conta</label><select id="conta_id" name="conta_id" required>
          @foreach ($contas as $c)<option value="{{ $c->id }}" @selected($f->cartao->conta_pagamento_id === $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
        <div class="campo"><label for="data">Data do pagamento</label><input id="data" type="date" name="data" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
        <div class="campo"><label for="encargos">Encargos (juros, IOF, multa)</label><input id="encargos" name="encargos" inputmode="decimal" placeholder="0,00"></div>
      </div>
      <p class="sub" style="font-size:13px">Cada compra da fatura é quitada. Encargos viram uma despesa própria em "Juros e encargos financeiros" — nunca somam ao valor das compras. Pagamento parcial e parcelamento do saldo da fatura ainda não estão disponíveis.</p>
      <button>Registrar pagamento da fatura</button>
    </form>
  @endif
@endif
@endsection
