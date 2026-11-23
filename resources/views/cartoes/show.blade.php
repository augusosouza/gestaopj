@extends('layouts.app')
@section('titulo', $cartao->rotulo())
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@php($url = fn ($a) => route('cartoes.show', [$cartao, 'aba' => $a]))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Cartões' => route('cartoes.index'), $cartao->rotulo() => null]" :titulo="$cartao->rotulo()"
  :sub="($cartao->instituicao ?? 'Cartão').' · fecha dia '.$cartao->dia_fechamento.' · vence dia '.$cartao->dia_vencimento.($cartao->ativo ? '' : ' · inativo')">
  <x-slot:acoes>@if ($opera && $cartao->ativo)<a class="botao" href="{{ route('compras.create', ['modalidade' => 'cartao']) }}">Nova compra no cartão</a>@endif</x-slot:acoes>
</x-cabecalho>
<section class="cards" aria-label="Limite">
  <div class="card"><div class="rot">Limite</div><div class="num">{{ $cartao->limite_centavos ? brl($cartao->limite_centavos) : '—' }}</div><div class="det">informado por você</div></div>
  <div class="card"><div class="rot">Em aberto</div><div class="num">{{ brl($emAberto) }}</div><div class="det">todas as faturas não pagas</div></div>
  <div class="card"><div class="rot">Disponível</div><div class="num">{{ $cartao->limite_centavos ? brl($cartao->limite_centavos - $emAberto) : '—' }}</div><div class="det">limite − em aberto</div></div>
  <div class="card"><div class="rot">Conta de pagamento</div><div class="num texto">{{ $cartao->contaPagamento?->nome ?? '—' }}</div><div class="det">responsável: {{ $cartao->responsavel?->name ?? '—' }}</div></div>
</section>
<div style="margin-top:20px"></div>
<x-abas :itens="[['Faturas', $url('faturas'), $aba === 'faturas', $faturas->count()], ['Compras', $url('compras'), $aba === 'compras'], ['Dados do cartão', $url('dados'), $aba === 'dados']]" />

@if ($aba === 'faturas')
  <div class="tabela"><table>
    <thead><tr><th>Fatura</th><th>Fecha</th><th>Vence</th><th class="n">Total</th><th class="n">Em aberto</th><th>Situação</th></tr></thead>
    <tbody>
    @forelse ($faturas as $f)
      <tr><td><a href="{{ route('cartoes.fatura', $f) }}">{{ $f->rotulo() }}</a></td><td>{{ $f->fechamento->format('d/m/Y') }}</td><td>{{ $f->vencimento->format('d/m/Y') }}</td>
        <td class="n">{{ brl($f->total()) }}</td><td class="n">{{ brl($f->saldo()) }}</td><td>@include('cartoes._situacao')</td></tr>
    @empty
      <tr><td colspan="6" class="vazio">Nenhuma fatura ainda.</td></tr>
    @endforelse
    </tbody>
  </table></div>
@elseif ($aba === 'compras')
  @include('compras._tabela', ['lista' => $compras])
@else
  <div class="duas">
    <div class="painel"><dl class="dados">
      <dt>Nome</dt><dd>{{ $cartao->nome }}</dd><dt>Instituição</dt><dd>{{ $cartao->instituicao ?? '—' }}</dd>
      <dt>Final</dt><dd>{{ $cartao->final ?? '—' }}</dd><dt>Fechamento</dt><dd>dia {{ $cartao->dia_fechamento }}</dd><dt>Vencimento</dt><dd>dia {{ $cartao->dia_vencimento }}</dd>
    </dl></div>
    @if ($opera)
    <form method="post" action="{{ route('cartoes.atualizar', $cartao) }}" class="painel">@csrf @method('patch')
      <strong>Alterar</strong>
      <div class="campo"><label for="limite">Limite (R$)</label><input id="limite" name="limite" inputmode="decimal" value="{{ valor_campo($cartao->limite_centavos) }}"></div>
      <div class="campo"><label for="conta_pagamento_id">Conta que paga a fatura</label><select id="conta_pagamento_id" name="conta_pagamento_id"><option value="">—</option>
        @foreach ($contas as $ct)<option value="{{ $ct->id }}" @selected($cartao->conta_pagamento_id === $ct->id)>{{ $ct->nome }}</option>@endforeach</select></div>
      <div class="campo"><label for="responsavel_id">Responsável</label><select id="responsavel_id" name="responsavel_id"><option value="">—</option>
        @foreach ($responsaveis as $u)<option value="{{ $u->id }}" @selected($cartao->responsavel_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button>Salvar</button><button class="sec" name="alternar" value="1">{{ $cartao->ativo ? 'Desativar cartão' : 'Reativar cartão' }}</button></div>
    </form>
    @endif
  </div>
@endif
@endsection
