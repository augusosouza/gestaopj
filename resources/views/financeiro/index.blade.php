@extends('layouts.app')
@section('titulo', $tipo === 'receber' ? 'Contas a receber' : ($tipo === 'pagar' ? 'Contas a pagar' : 'Lançamentos'))
@section('conteudo')
@php
  $opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro);
  $titulo = $tipo === 'receber' ? 'Contas a receber' : ($tipo === 'pagar' ? 'Contas a pagar' : 'Lançamentos');
  $q = fn (array $mudar) => request()->fullUrlWithQuery($mudar);
  $total = $titulos->reject->cancelado()->sum('valor_total_centavos');
  $saldo = $titulos->reject->cancelado()->sum(fn ($t) => $t->saldo());
@endphp
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), $titulo => null]" :titulo="$titulo"
  :sub="($todos ? 'Todas as competências' : 'Competência '.$competencia->translatedFormat('F \d\e Y')).' · '.$titulos->count().' lançamento(s) · total '.brl($total).' · em aberto '.brl($saldo)">
  <x-slot:acoes>
    @unless ($todos)@include('partials.competencia')@endunless
    @if ($opera)<a class="botao" href="{{ route('financeiro.novo', ['tipo' => $tipo === 'receber' ? 'receber' : 'pagar']) }}">+ Novo lançamento</a>@endif
  </x-slot:acoes>
</x-cabecalho>
<x-abas :itens="[
  ['Todos', $q(['tipo' => null]), $tipo === null],
  ['Receitas', $q(['tipo' => 'receber']), $tipo === 'receber'],
  ['Despesas', $q(['tipo' => 'pagar']), $tipo === 'pagar'],
]" />

<form method="get" class="filtros-barra" role="search">
  @if ($tipo)<input type="hidden" name="tipo" value="{{ $tipo }}">@endif
  <input type="hidden" name="competencia" value="{{ $competencia->format('Y-m') }}">
  <input type="search" name="q" value="{{ $busca }}" placeholder="Buscar descrição, fornecedor, documento…" aria-label="Buscar">
  <select name="situacao" aria-label="Situação">
    <option value="">Qualquer situação</option>
    @foreach (['aberto' => 'Em aberto', 'parcial' => 'Parcial', 'vencido' => 'Vencidos', 'liquidado' => 'Liquidados', 'cancelado' => 'Cancelados'] as $k => $r)
      <option value="{{ $k }}" @selected($situacao === $k)>{{ $r }}</option>@endforeach
  </select>
  <select name="categoria" aria-label="Categoria">
    <option value="">Todas as categorias</option>
    @foreach ($categorias->when($tipo, fn ($c) => $c->where('tipo', $tipo === 'receber' ? 'receita' : 'despesa')) as $c)
      <option value="{{ $c->id }}" @selected($categoriaId === $c->id)>{{ $c->nome }}</option>@endforeach
  </select>
  <select name="periodo" aria-label="Período">
    <option value="">Só esta competência</option>
    <option value="todos" @selected($todos)>Todas as competências</option>
  </select>
  <button class="sec">Filtrar</button>
  @if ($busca || $situacao || $categoriaId || $todos)<a href="{{ route('financeiro.index', array_filter(['tipo' => $tipo, 'competencia' => $competencia->format('Y-m')])) }}" class="limpar">Limpar filtros</a>@endif
</form>

<div class="tabela"><table>
  <thead><tr><th>Lançamento</th><th>Categoria</th><th>Competência</th><th>Vencimento</th><th class="n">Valor</th><th class="n">Em aberto</th><th>Situação</th></tr></thead>
  <tbody>
  @forelse ($titulos as $t)
    <tr class="linha-link">
      <td><a href="{{ route('financeiro.titulo', $t) }}">{{ $t->descricao }}</a> @unless ($tipo)<span class="tag rotulo">{{ $t->receber() ? 'Receita' : 'Despesa' }}</span>@endunless
        @if ($t->contraparte)<br><span class="sub" style="font-size:12.5px">{{ $t->contraparte }}</span>@endif</td>
      <td>{{ $t->categoria?->nome ?? '—' }}</td>
      <td>{{ $t->competencia->format('m/Y') }}</td>
      <td>{{ $t->parcelas->first()?->vencimento->format('d/m/Y') }}@if ($t->parcelas->count() > 1)<span class="sub" style="font-size:12.5px"> · {{ $t->parcelas->count() }}x</span>@endif</td>
      <td class="n">{{ brl($t->valor_total_centavos) }}</td>
      <td class="n">{{ $t->cancelado() ? '—' : brl($t->saldo()) }}</td>
      <td>@include('financeiro._situacao')</td>
    </tr>
  @empty
    <tr><td colspan="7" class="vazio">Nenhum lançamento com esses filtros.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
