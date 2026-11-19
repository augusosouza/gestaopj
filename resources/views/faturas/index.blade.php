@extends('layouts.app')
@section('titulo', 'A receber')
@section('conteudo')
<div class="barra">
  <div><h1>Contas a receber</h1><p class="sub" style="margin:0">Faturas dos clientes e NFS-e emitidas pela empresa</p></div>
  @include('partials.competencia')
</div>
<div class="tabela">
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
