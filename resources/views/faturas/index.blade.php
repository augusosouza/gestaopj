@extends('layouts.app')
@section('titulo', 'A receber')
@section('conteudo')
<x-cabecalho :trilha="['Operação' => null, 'Faturas' => null]" titulo="Contas a receber" sub="Faturas dos clientes e NFS-e emitidas pela empresa.">
  <x-slot:acoes>@include('partials.competencia')</x-slot:acoes>
</x-cabecalho>
<form method="get" action="{{ route('faturas.index') }}" class="painel lista-filtros">
  <input type="hidden" name="competencia" value="{{ $competencia->format('Y-m') }}">
  <div class="lista-filtros-principais">
    <div class="campo lista-pesquisa"><label for="fatura-q">Número, contrato ou cliente</label><input type="search" id="fatura-q" name="q" maxlength="100" value="{{ $filtros['q'] ?? '' }}"></div>
    <div class="campo"><label for="fatura-situacao">Situação</label><select id="fatura-situacao" name="situacao">
      <option value="">Todas</option>
      @foreach (\App\Enums\StatusFatura::cases() as $status)<option value="{{ $status->value }}" @selected(($filtros['situacao'] ?? '') === $status->value)>{{ $status->rotulo() }}</option>@endforeach
    </select></div>
  </div>
  <div class="acoes"><button class="sec">Filtrar</button><a href="{{ route('faturas.index', ['competencia' => $competencia->format('Y-m')]) }}">Limpar filtros</a></div>
</form>
@include('partials.visoes-salvas', ['modulo' => 'faturas', 'filtrosVisao' => array_filter($filtros) + ['competencia' => $competencia->format('Y-m')]])
<div class="lista-barra"><p class="sub">{{ $faturas->total() }} faturas com os filtros aplicados</p><x-densidade tabela="lista-faturas" /></div>
@php($exportaLote = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@if ($exportaLote)
  <form method="post" action="{{ route('faturas.lote.previa') }}" id="faturas-lote">@csrf</form>
  @if ($faturas->count())<p><button type="submit" class="sec" form="faturas-lote">Conferir exportação das selecionadas</button></p>@endif
@endif
<div class="tabela" id="lista-faturas">
  <table>
    <thead><tr>@if ($exportaLote)<th scope="col">Seleção</th>@endif<th>Contrato</th><th>Cliente</th><th class="n">Valor</th><th>Vencimento</th><th>NFS-e</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($faturas as $f)
      <tr>
        @if ($exportaLote)<td><input type="checkbox" form="faturas-lote" name="faturas[]" value="{{ $f->id }}" aria-label="Selecionar fatura {{ $f->id }}"></td>@endif
        <td>{{ $f->contrato->numero }}</td>
        <td>{{ $f->contrato->cliente->razao_social }}</td>
        <td class="n">{{ brl($f->valor_centavos) }}</td>
        <td>{{ $f->vencimento->format('d/m/Y') }} @if ($f->vencida())<span class="tag bad">Vencida</span>@endif</td>
        <td>{{ $f->notaFiscal?->numero ? 'Nº '.$f->notaFiscal->numero : '—' }}</td>
        <td>@include('partials.status', ['status' => $f->status])</td>
        <td><a href="{{ route('faturas.show', $f) }}">Abrir</a></td>
      </tr>
    @empty
      <tr><td colspan="{{ $exportaLote ? 8 : 7 }}" class="vazio">Nenhuma fatura nesta competência.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
{{ $faturas->links() }}
@endsection
