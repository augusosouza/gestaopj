@extends('layouts.app')
@section('titulo', 'A receber')
@section('conteudo')
<x-cabecalho :trilha="['Operação' => null, 'Faturas' => null]" titulo="Contas a receber" sub="Faturas dos clientes e NFS-e emitidas pela empresa.">
  <x-slot:acoes>@include('partials.competencia')</x-slot:acoes>
</x-cabecalho>
<div class="lista-barra"><p class="sub">{{ $faturas->count() }} faturas na competência</p><x-densidade tabela="lista-faturas" /></div>
<div class="tabela" id="lista-faturas">
  <table>
    <thead><tr><th>Contrato</th><th>Cliente</th><th class="n">Valor</th><th>Vencimento</th><th>NFS-e</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($faturas as $f)
      <tr>
        <td>{{ $f->contrato->numero }}</td>
        <td>{{ $f->contrato->cliente->razao_social }}</td>
        <td class="n">{{ brl($f->valor_centavos) }}</td>
        <td>{{ $f->vencimento->format('d/m/Y') }} @if ($f->vencida())<span class="tag bad">Vencida</span>@endif</td>
        <td>{{ $f->notaFiscal?->numero ? 'Nº '.$f->notaFiscal->numero : '—' }}</td>
        <td>@include('partials.status', ['status' => $f->status])</td>
        <td><a href="{{ route('faturas.show', $f) }}">Abrir</a></td>
      </tr>
    @empty
      <tr><td colspan="7" class="vazio">Nenhuma fatura nesta competência.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
