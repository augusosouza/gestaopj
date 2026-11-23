@extends('layouts.app')
@section('titulo', 'Contas e bancos')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Contas e bancos' => null]" titulo="Contas e bancos"
  sub="Saldo calculado pelo saldo inicial e pelas baixas. O extrato só conta como atualizado pelo período que ele cobre." />

<div class="tabela"><table>
  <thead><tr><th>Conta</th><th class="n">Saldo no sistema</th><th>Extrato coberto até</th><th>Situação</th><th>Lembrete</th><th></th></tr></thead>
  <tbody>
  @forelse ($contas as $c)
    @php($s = $situacoes[$c->id])
    <tr @unless ($c->ativa) style="color:var(--muted)" @endunless>
      <td><a href="{{ route('financeiro.conta', $c) }}">{{ $c->nome }}</a> <span class="tag rotulo">{{ \App\Models\ContaFinanceira::TIPOS[$c->tipo] ?? $c->tipo }}</span>@unless ($c->ativa)<span class="tag">inativa</span>@endunless
        <br><span class="sub" style="font-size:12.5px">{{ trim(($c->banco ?? '').' '.($c->identificacao ?? '')) ?: '—' }}</span></td>
      <td class="n">{{ brl($c->saldo()) }}</td>
      <td>{{ $c->extrato_ate?->format('d/m/Y') ?? 'nenhum extrato' }}@if ($s['exigido_ate'] && $s['estado'] === 'desatualizado')<br><span style="font-size:12.5px;color:var(--bad)">exigido até {{ $s['exigido_ate']->format('d/m/Y') }}</span>@endif</td>
      <td>@include('financeiro._situacao_conta')</td>
      <td style="font-size:13px">{{ $c->lembrete_ativo ? ucfirst($c->regraLembrete()) : '—' }}</td>
      <td style="white-space:nowrap"><a href="{{ route('financeiro.conciliacao', $c) }}">Conciliar</a> · <a href="{{ route('financeiro.conta', $c) }}">Detalhes</a></td>
    </tr>
  @empty
    <tr><td colspan="6" class="vazio">Nenhuma conta. Cadastre a conta bancária da empresa.</td></tr>
  @endforelse
  </tbody>
</table></div>

@if ($opera)
<details class="painel" style="margin-top:16px" @if ($contas->isEmpty()) open @endif>
  <summary class="link-sum" style="font-size:14px;color:var(--text)">+ Nova conta</summary>
  <form method="post" action="{{ route('financeiro.conta_salvar') }}" style="margin-top:12px">
    @csrf
    <div class="grade">
      <div class="campo"><label for="nome">Nome</label><input id="nome" name="nome" required placeholder="Banco X — PJ"></div>
      <div class="campo"><label for="tipo">Tipo</label><select id="tipo" name="tipo">@foreach (\App\Models\ContaFinanceira::TIPOS as $k => $r)<option value="{{ $k }}">{{ $r }}</option>@endforeach</select></div>
      <div class="campo"><label for="banco">Banco (opcional)</label><input id="banco" name="banco"></div>
      <div class="campo"><label for="identificacao">Ag./conta, só final (opcional)</label><input id="identificacao" name="identificacao" placeholder="ag 0001 · cc ••••-7"></div>
      <div class="campo"><label for="saldo_inicial">Saldo inicial (R$)</label><input id="saldo_inicial" name="saldo_inicial" inputmode="decimal" placeholder="0,00"></div>
      <div class="campo"><label for="saldo_inicial_em">Saldo em</label><input id="saldo_inicial_em" type="date" name="saldo_inicial_em" value="{{ now()->startOfMonth()->toDateString() }}" required></div>
    </div>
    <p class="sub" style="font-size:13px">Não guardamos senha nem acesso ao banco. Depois de cadastrar, configure o lembrete de atualização do extrato.</p>
    <button>Cadastrar</button>
  </form>
</details>
@endif
@endsection
