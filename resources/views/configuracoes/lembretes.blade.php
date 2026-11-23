@extends('layouts.app')
@section('titulo', 'Lembretes bancários')
@section('conteudo')
<x-cabecalho :trilha="['Configurações' => route('configuracoes'), 'Financeiro' => null, 'Lembretes bancários' => null]" titulo="Lembretes bancários"
  sub="Quando cobrar a atualização do extrato de cada conta. A conta só fica em dia pelo período que o extrato cobre." />
<div class="tabela"><table>
  <thead><tr><th>Conta</th><th>Regra</th><th>Responsável</th><th>Extrato até</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  @forelse ($contas as $c)
    <tr><td>{{ $c->nome }}</td>
      <td>{{ $c->lembrete_ativo ? ucfirst($c->regraLembrete()) : 'Desligado' }}@if ($c->lembrete_ativo && $c->lembrete_antecedencia)<br><span class="sub" style="font-size:12.5px">avisa {{ $c->lembrete_antecedencia }} dia(s) antes · repete a cada {{ $c->lembrete_repetir_dias }} dia(s)</span>@endif</td>
      <td>{{ $c->responsavel?->name ?? 'Administradores e financeiro' }}</td>
      <td>{{ $c->extrato_ate?->format('d/m/Y') ?? '—' }}</td>
      <td>@include('financeiro._situacao_conta', ['s' => $situacoes[$c->id]])</td>
      <td><a href="{{ route('financeiro.conta', [$c, 'aba' => 'lembrete']) }}">Configurar</a></td></tr>
  @empty
    <tr><td colspan="6" class="vazio">Nenhuma conta ativa. <a href="{{ route('financeiro.contas') }}">Cadastre uma conta</a>.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
