@extends('layouts.app')
@section('titulo', 'Fatura '.$fatura->contrato->numero)
@section('conteudo')
@php($pode = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<x-cabecalho :trilha="['Faturas' => route('faturas.index', ['competencia' => $fatura->competencia->format('Y-m')]), 'Detalhe' => null]"
  :titulo="'Fatura '.$fatura->contrato->numero.' · '.$fatura->competencia->format('m/Y')" :sub="$fatura->contrato->cliente->razao_social">
  @include('partials.status', ['status' => $fatura->status])
</x-cabecalho>
<div class="ficha-secoes">
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
      @if ($titulo->liquidado() > 0)<p class="sub">Já recebido: {{ brl($titulo->liquidado()) }} · saldo {{ brl($titulo->saldo()) }} · <a href="{{ route('financeiro.titulo', $titulo) }}">ver baixas</a></p>@endif
      <form method="post" action="{{ route('faturas.receber', $fatura) }}" class="grade">
        @csrf <input type="hidden" name="chave" value="{{ $chave }}">
        <div><label for="conta_id">Conta</label><select id="conta_id" name="conta_id">
          @forelse ($contas as $c)<option value="{{ $c->id }}">{{ $c->nome }}</option>@empty<option value="">Conta principal (será criada)</option>@endforelse</select></div>
        <div><label for="valor">Valor recebido</label><input id="valor" name="valor" value="{{ old('valor', valor_campo($titulo->saldo())) }}" inputmode="decimal" required></div>
        <div><label for="data">Data</label><input id="data" type="date" name="data" value="{{ old('data', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
        <div style="align-self:end"><button>Registrar</button></div>
      </form>
    @endif
  </div>
</div>
<section class="painel" aria-labelledby="envios-fatura">
  <h2 id="envios-fatura">Envios por e-mail</h2>
  @if ($pode && $fatura->status !== \App\Enums\StatusFatura::Cancelada)
    @if (filter_var($fatura->contrato->cliente->email, FILTER_VALIDATE_EMAIL))
      <p>Destinatário de cobrança: <strong>{{ $fatura->contrato->cliente->email }}</strong></p>
      <p class="sub">O e-mail inclui o número, valor e vencimento da fatura. Sem anexos.</p>
      <form method="post" action="{{ route('faturas.enviar', $fatura) }}" data-evitar-reenvio="Enviando…">
        @csrf
        <input type="hidden" name="chave" value="{{ $chave }}">
        <input type="hidden" name="destinatario" value="{{ $fatura->contrato->cliente->email }}">
        <button type="submit">Enviar resumo da fatura</button>
      </form>
    @else
      <p>Cadastre um e-mail de cobrança válido para enviar o resumo da fatura.</p>
    @endif
  @endif
  @forelse ($envios as $evento)
    <p><time datetime="{{ $evento->created_at->toIso8601String() }}">{{ $evento->created_at->format('d/m/Y H:i') }}</time> · {{ $evento->rotulo() }}</p>
    @include('faturas.motivo-envio')
  @empty
    <p class="sub">Nenhuma tentativa de envio registrada.</p>
  @endforelse
</section>
</div>
@endsection
