@extends('layouts.app')
@section('titulo', 'Cartões')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Cartões' => null]" titulo="Cartões corporativos"
  sub="Limite, faturas e compras parceladas. Pagar a fatura quita as compras dela — a despesa não é lançada de novo." />

<div class="tabela"><table>
  <thead><tr><th>Cartão</th><th>Fechamento / vencimento</th><th class="n">Limite</th><th class="n">Em aberto</th><th class="n">Disponível</th><th>Uso do limite</th><th>Responsável</th></tr></thead>
  <tbody>
  @forelse ($cartoes as $c)
    @php($uso = $c->limite_centavos > 0 ? min(100, round($emAberto[$c->id] * 100 / $c->limite_centavos)) : 0)
    <tr @if (! $c->ativo) style="color:var(--muted)" @endif>
      <td><a href="{{ route('cartoes.show', $c) }}">{{ $c->rotulo() }}</a><br><span class="sub" style="font-size:12.5px">{{ $c->instituicao ?? '—' }}@if (! $c->ativo) · inativo @endif</span></td>
      <td>dia {{ $c->dia_fechamento }} / dia {{ $c->dia_vencimento }}</td>
      <td class="n">{{ $c->limite_centavos ? brl($c->limite_centavos) : '—' }}</td>
      <td class="n">{{ brl($emAberto[$c->id]) }}</td>
      <td class="n" @if ($c->limite_centavos && $c->limiteDisponivel() < 0) style="color:var(--bad)" @endif>{{ $c->limite_centavos ? brl($c->limite_centavos - $emAberto[$c->id]) : '—' }}</td>
      <td style="min-width:120px">@if ($c->limite_centavos)<div class="progresso serie-pendencia" role="img" aria-label="{{ $uso }}% do limite"><span style="width:{{ $uso }}%"></span></div><span class="sub" style="font-size:12px">{{ $uso }}%</span>@else — @endif</td>
      <td>{{ $c->responsavel?->name ?? '—' }}</td>
    </tr>
  @empty
    <tr><td colspan="7" class="vazio">Nenhum cartão cadastrado.</td></tr>
  @endforelse
  </tbody>
</table></div>

<h2>Faturas</h2>
<div class="tabela"><table>
  <thead><tr><th>Cartão</th><th>Fatura</th><th>Fecha</th><th>Vence</th><th class="n">Total</th><th class="n">Em aberto</th><th>Situação</th></tr></thead>
  <tbody>
  @forelse ($faturas as $f)
    <tr><td>{{ $f->cartao->rotulo() }}</td><td><a href="{{ route('cartoes.fatura', $f) }}">{{ $f->rotulo() }}</a></td>
      <td>{{ $f->fechamento->format('d/m/Y') }}</td><td>{{ $f->vencimento->format('d/m/Y') }}</td>
      <td class="n">{{ brl($f->total()) }}</td><td class="n">{{ brl($f->saldo()) }}</td><td>@include('cartoes._situacao')</td></tr>
  @empty
    <tr><td colspan="7" class="vazio">Nenhuma fatura com compras. Registre uma compra com a forma "Cartão de crédito".</td></tr>
  @endforelse
  </tbody>
</table></div>

@if ($opera)
<details class="painel" style="margin-top:20px" @if ($cartoes->isEmpty() || $errors->any()) open @endif>
  <summary><strong>Cadastrar cartão</strong></summary>
  <form method="post" action="{{ route('cartoes.store') }}" style="margin-top:12px">@csrf
    <div class="grade">
      <div class="campo"><label for="nome">Nome</label><input id="nome" name="nome" value="{{ old('nome') }}" required placeholder="Ex.: Itaú Empresas"></div>
      <div class="campo"><label for="instituicao">Instituição</label><input id="instituicao" name="instituicao" value="{{ old('instituicao') }}"></div>
      <div class="campo"><label for="final">Últimos 4 dígitos</label><input id="final" name="final" value="{{ old('final') }}" inputmode="numeric" maxlength="4" pattern="\d{4}"></div>
      <div class="campo"><label for="limite">Limite (R$)</label><input id="limite" name="limite" value="{{ old('limite') }}" inputmode="decimal" placeholder="10.000,00"></div>
    </div>
    <div class="grade">
      <div class="campo"><label for="dia_fechamento">Dia de fechamento</label><input id="dia_fechamento" type="number" min="1" max="28" name="dia_fechamento" value="{{ old('dia_fechamento', 3) }}" required></div>
      <div class="campo"><label for="dia_vencimento">Dia de vencimento</label><input id="dia_vencimento" type="number" min="1" max="28" name="dia_vencimento" value="{{ old('dia_vencimento', 10) }}" required></div>
      <div class="campo"><label for="conta_pagamento_id">Conta que paga a fatura</label><select id="conta_pagamento_id" name="conta_pagamento_id"><option value="">—</option>
        @foreach ($contas as $ct)<option value="{{ $ct->id }}">{{ $ct->nome }}</option>@endforeach</select></div>
      <div class="campo"><label for="responsavel_id">Responsável</label><select id="responsavel_id" name="responsavel_id"><option value="">—</option>
        @foreach ($responsaveis as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
    </div>
    <p class="sub" style="font-size:13px">Compras feitas no dia do fechamento ou depois entram na fatura seguinte. Não guardamos o número completo do cartão, CVV nem senha.</p>
    <button>Cadastrar</button>
  </form>
</details>
@endif
@endsection
