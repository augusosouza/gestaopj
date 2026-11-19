@extends('layouts.app')
@section('titulo', 'Fatura '.$fatura->contrato->numero)
@section('conteudo')
@php($pode = auth()->user()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<p><a href="{{ route('faturas.index', ['competencia' => $fatura->competencia->format('Y-m')]) }}">← A receber</a></p>
<h1>Fatura {{ $fatura->contrato->numero }} · {{ $fatura->competencia->format('m/Y') }}</h1>
<p class="sub">{{ $fatura->contrato->cliente->razao_social }} · @include('partials.status', ['status' => $fatura->status])</p>

<div class="duas">
  <div class="painel">
    <dl class="dados">
      <dt>Prestador (emitente)</dt><dd>{{ $fatura->contrato->empresa->razao_social }}<br><span class="mono">{{ doc($fatura->contrato->empresa->cnpj) }}</span></dd>
      <dt>Tomador</dt><dd>{{ $fatura->contrato->cliente->razao_social }}<br><span class="mono">{{ doc($fatura->contrato->cliente->documento) }}</span></dd>
      <dt>Serviço</dt><dd>{{ $fatura->contrato->descricao }}@if ($fatura->contrato->codigo_servico) · cTribNac {{ $fatura->contrato->codigo_servico }}@endif</dd>
      <dt>Valor</dt><dd>{{ brl($fatura->valor_centavos) }}</dd>
      <dt>Vencimento</dt><dd>{{ $fatura->vencimento->format('d/m/Y') }} @if ($fatura->vencida())<span class="tag bad">Vencida</span>@endif</dd>
      @if ($fatura->recebida_em)
        <dt>Recebido</dt><dd>{{ brl($fatura->valor_recebido_centavos) }} em {{ $fatura->recebida_em->format('d/m/Y') }}
          @if ($fatura->valor_recebido_centavos !== $fatura->valor_centavos)<span class="tag warn">Difere em {{ brl($fatura->valor_recebido_centavos - $fatura->valor_centavos) }}</span>@endif</dd>
      @endif
    </dl>
  </div>

  <div class="painel">
    @if ($fatura->notaFiscal)
      <h2 style="margin-top:0">NFS-e vinculada</h2>
      @include('notas._resumo', ['nota' => $fatura->notaFiscal])
    @elseif ($pode)
      <h2 style="margin-top:0">Vincular NFS-e emitida</h2>
      <p class="sub">Emita a nota no Emissor Nacional com os dados ao lado e envie o XML. O sistema confere emitente, tomador, valor e competência.</p>
      <form method="post" action="{{ route('faturas.nota', $fatura) }}" enctype="multipart/form-data">
        @csrf
        <div class="campo"><label for="xml">XML da NFS-e</label><input id="xml" type="file" name="xml" accept=".xml,text/xml,application/xml" required></div>
        <button>Vincular nota</button>
      </form>
    @else
      <p class="sub">Nota ainda não emitida.</p>
    @endif

    @if ($pode && $fatura->status === \App\Enums\StatusFatura::Emitida)
      <h2>Registrar recebimento</h2>
      <form method="post" action="{{ route('faturas.receber', $fatura) }}" class="grade">
        @csrf
        <div><label for="valor">Valor recebido</label><input id="valor" name="valor" value="{{ old('valor', valor_campo($fatura->valor_centavos)) }}" inputmode="decimal" required></div>
        <div><label for="data">Data</label><input id="data" type="date" name="data" value="{{ old('data', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
        <div style="align-self:end"><button>Registrar</button></div>
      </form>
    @endif
  </div>
</div>
@endsection
