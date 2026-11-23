@extends('layouts.app')
@section('titulo', 'Contas')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<h1>Contas e conciliação</h1>
<p class="sub">Saldo calculado a partir do saldo inicial e das baixas. Importe o extrato do banco para conferir lançamento por lançamento.</p>
@include('financeiro._abas')
<div class="duas">
  <div class="tabela"><table>
    <thead><tr><th>Conta</th><th class="n">Saldo atual</th><th class="n">A conciliar</th><th></th></tr></thead>
    <tbody>
    @forelse ($contas as $c)
      <tr @unless ($c->ativa) style="color:var(--muted)" @endunless>
        <td>{{ $c->nome }} <span class="tag rotulo">{{ \App\Models\ContaFinanceira::TIPOS[$c->tipo] ?? $c->tipo }}</span>@unless ($c->ativa)<span class="tag">inativa</span>@endunless
          <br><span class="sub" style="font-size:12.5px">{{ trim(($c->banco ?? '').' '.($c->identificacao ?? '')) ?: '—' }}</span></td>
        <td class="n">{{ brl($c->saldo()) }}</td>
        <td class="n">{{ $pendentes[$c->id] ?? 0 }}</td>
        <td><a href="{{ route('financeiro.conciliacao', $c) }}">Conciliar</a>
          @if ($opera)<form method="post" action="{{ route('financeiro.conta_alternar', $c) }}" style="display:inline">@csrf @method('patch')<button class="link">{{ $c->ativa ? 'desativar' : 'reativar' }}</button></form>@endif</td>
      </tr>
    @empty
      <tr><td colspan="4" class="vazio">Nenhuma conta. Cadastre a conta bancária da empresa.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  @if ($opera)
  <form method="post" action="{{ route('financeiro.conta_salvar') }}" class="painel">
    @csrf
    <strong>Nova conta</strong>
    <div class="grade" style="margin-top:8px">
      <div class="campo"><label for="nome">Nome</label><input id="nome" name="nome" required placeholder="Banco X — PJ"></div>
      <div class="campo"><label for="tipo">Tipo</label><select id="tipo" name="tipo">@foreach (\App\Models\ContaFinanceira::TIPOS as $k => $r)<option value="{{ $k }}">{{ $r }}</option>@endforeach</select></div>
      <div class="campo"><label for="banco">Banco (opcional)</label><input id="banco" name="banco"></div>
      <div class="campo"><label for="identificacao">Ag./conta, só final (opcional)</label><input id="identificacao" name="identificacao" placeholder="ag 0001 · cc ••••-7"></div>
      <div class="campo"><label for="saldo_inicial">Saldo inicial (R$)</label><input id="saldo_inicial" name="saldo_inicial" inputmode="decimal" placeholder="0,00"></div>
      <div class="campo"><label for="saldo_inicial_em">Saldo em</label><input id="saldo_inicial_em" type="date" name="saldo_inicial_em" value="{{ now()->startOfMonth()->toDateString() }}" required></div>
    </div>
    <p class="sub" style="font-size:13px">Não guardamos senha nem dados de acesso ao banco: a conta serve para organizar saldos e conciliar extratos.</p>
    <button>Cadastrar</button>
  </form>
  @endif
</div>
@endsection
