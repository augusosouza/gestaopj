@extends('layouts.app')
@section('titulo', 'Minhas cobranças')
@section('conteudo')
@php($p = $prestador)
<h1>Olá, {{ $p?->nome ?? auth()->user()->name }}</h1>
<p class="sub">Para cada mês: emita a NFS-e no Emissor Nacional tendo como tomador o CNPJ informado abaixo e envie aqui o XML da nota (ou a chave de acesso).</p>
@if (! $p)
  <div class="aviso bad">Seu acesso ainda não está vinculado a um cadastro de prestador. Fale com a empresa.</div>
@endif
<div class="tabela">
  <table>
    <thead><tr><th>Competência</th><th>Contrato</th><th>Tomador da nota</th><th class="n">Valor da nota</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($cobrancas as $c)
      <tr>
        <td>{{ $c->competencia->format('m/Y') }}</td>
        <td>{{ $c->contrato->numero }}</td>
        <td>{{ $c->contrato->empresa->razao_social }}<br><span class="mono">{{ doc($c->contrato->empresa->cnpj) }}</span></td>
        <td class="n">{{ brl($c->valor_centavos) }}</td>
        <td>@include('partials.status', ['status' => $c->status])
          @if ($c->paga_em)<div class="sub" style="margin:4px 0 0">Pago em {{ $c->paga_em->format('d/m/Y') }}</div>@endif</td>
        <td><a href="{{ route('cobrancas.show', $c) }}">{{ $c->status->aceitaNota() ? 'Enviar nota' : 'Detalhes' }}</a></td>
      </tr>
    @empty
      <tr><td colspan="6" class="vazio">Nenhuma cobrança ainda.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
