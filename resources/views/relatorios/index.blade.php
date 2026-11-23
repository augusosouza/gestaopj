@extends('layouts.app')
@section('titulo', 'Relatórios')
@section('conteudo')
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Relatórios' => null]" titulo="Relatórios"
  sub="Relatórios gerenciais a partir dos mesmos lançamentos e pagamentos das telas. Todos exportam para Excel e CSV." />
<div class="relatorios-grade">
  @foreach ($lista as $slug => [$titulo, $desc])
    <a href="{{ route('relatorios.show', $slug) }}" class="painel relatorio-card"><strong>{{ $titulo }}</strong><span class="sub" style="margin:4px 0 0;font-size:13px">{{ $desc }}</span></a>
  @endforeach
  <a href="{{ route('financeiro.inadimplencia') }}" class="painel relatorio-card"><strong>Inadimplência</strong><span class="sub" style="margin:4px 0 0;font-size:13px">Atrasos por cliente e faixa de dias.</span></a>
  <a href="{{ route('compras.index', ['aba' => 'parcelamentos']) }}" class="painel relatorio-card"><strong>Compras e parcelamentos</strong><span class="sub" style="margin:4px 0 0;font-size:13px">Progresso de cada compra parcelada.</span></a>
  <a href="{{ route('planejamento.index') }}" class="painel relatorio-card"><strong>Orçamento × realizado</strong><span class="sub" style="margin:4px 0 0;font-size:13px">Orçado, comprometido e disponível por categoria e centro.</span></a>
  <a href="{{ route('planejamento.index', ['aba' => 'comparativo']) }}" class="painel relatorio-card"><strong>Comparativo mensal</strong><span class="sub" style="margin:4px 0 0;font-size:13px">Despesas por categoria mês a mês, com tendência.</span></a>
</div>
@endsection
