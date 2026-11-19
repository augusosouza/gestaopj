@extends('layouts.app')
@section('titulo', 'Cobrança '.$cobranca->prestador->nome)
@section('conteudo')
@php
  $u = auth()->user();
  $financeiro = $u->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro);
  $S = \App\Enums\StatusCobranca::class;
@endphp
<p><a href="{{ $u->ehPrestador() ? route('painel') : route('cobrancas.index', ['competencia' => $cobranca->competencia->format('Y-m')]) }}">← Voltar</a></p>
<h1>{{ $cobranca->prestador->nome }} · {{ $cobranca->competencia->format('m/Y') }}</h1>
<p class="sub">Contrato {{ $cobranca->contrato->numero }} · @include('partials.status', ['status' => $cobranca->status])</p>

@if ($cobranca->status === $S::Rejeitada)
  <div class="aviso bad"><strong>Nota rejeitada:</strong> {{ $cobranca->motivo_rejeicao }}</div>
@endif

<div class="duas">
  <div class="painel">
    <h2 style="margin-top:0">Dados para emissão da NFS-e</h2>
    <dl class="dados">
      <dt>Prestador (emitente)</dt><dd>{{ $cobranca->prestador->nome }}<br><span class="mono">{{ doc($cobranca->prestador->documento) }}</span></dd>
      <dt>Tomador</dt><dd>{{ $cobranca->contrato->empresa->razao_social }}<br><span class="mono">{{ doc($cobranca->contrato->empresa->cnpj) }}</span></dd>
      <dt>Competência</dt><dd>{{ $cobranca->competencia->format('m/Y') }}</dd>
      <dt>Valor do serviço</dt><dd><strong>{{ brl($cobranca->valor_centavos) }}</strong></dd>
      <dt>Referência</dt><dd>{{ $cobranca->alocacao->descricao ?? $cobranca->contrato->descricao }}</dd>
      @if ($cobranca->paga_em)
        <dt>Pagamento</dt><dd>{{ brl($cobranca->valor_pago_centavos) }} em {{ $cobranca->paga_em->format('d/m/Y') }}</dd>
      @endif
    </dl>

    @if ($cobranca->status->aceitaNota())
      <h2>Enviar nota</h2>
      @if ($cobranca->prestador->tipo->emiteNfse())
        <form method="post" action="{{ route('cobrancas.nota', $cobranca) }}" enctype="multipart/form-data">
          @csrf
          <div class="campo"><label for="xml">XML da NFS-e (recomendado)</label><input id="xml" type="file" name="xml" accept=".xml,text/xml,application/xml"></div>
          <div class="campo"><label for="chave_acesso">ou chave de acesso (50 dígitos)</label><input id="chave_acesso" name="chave_acesso" value="{{ old('chave_acesso') }}" inputmode="numeric" maxlength="60" class="mono"></div>
          <p class="sub">Com o XML, valor, tomador e competência são conferidos automaticamente. Só com a chave, a conferência fica pendente.</p>
          <button>Enviar para conferência</button>
        </form>
      @else
        <div class="aviso info">Prestador pessoa física: a documentação segue o fluxo definido com a contabilidade (fora do piloto).</div>
      @endif
    @endif
  </div>

  <div class="painel">
    @if ($cobranca->notaFiscal)
      <h2 style="margin-top:0">Nota enviada</h2>
      @include('notas._resumo', ['nota' => $cobranca->notaFiscal])
    @endif

    @if ($cobranca->conferencia && ! $u->ehPrestador())
      <h2>Conferência automática</h2>
      <ul class="check">
        @foreach ($cobranca->conferencia as $i)
          <li>
            <span class="ic" style="color:var(--{{ $i['ok'] ? 'ok' : ($i['bloqueante'] ? 'bad' : 'warn') }})">{{ $i['ok'] ? '✓' : ($i['bloqueante'] ? '✗' : '!') }}</span>
            <span><strong>{{ $i['item'] }}</strong>@unless ($i['bloqueante']) <span class="sub">(aviso)</span>@endunless<br><span class="sub">{{ $i['detalhe'] }}</span></span>
          </li>
        @endforeach
      </ul>
    @endif

    @if ($financeiro && $cobranca->status === $S::EmConferencia)
      <h2>Decisão do financeiro</h2>
      <form method="post" action="{{ route('cobrancas.aprovar', $cobranca) }}" class="campo">
        @csrf
        @unless ($cobranca->conferenciaOk())
          <div class="campo"><label for="justificativa">Justificativa para aprovar com ressalva</label><textarea id="justificativa" name="justificativa" rows="2" required>{{ old('justificativa') }}</textarea></div>
        @endunless
        <button>{{ $cobranca->conferenciaOk() ? 'Aprovar para pagamento' : 'Aprovar com ressalva' }}</button>
      </form>
      <form method="post" action="{{ route('cobrancas.rejeitar', $cobranca) }}">
        @csrf
        <div class="campo"><label for="motivo">Motivo da rejeição (o prestador verá)</label><textarea id="motivo" name="motivo" rows="2" required>{{ old('motivo') }}</textarea></div>
        <button class="perigo">Rejeitar nota</button>
      </form>
    @endif

    @if ($financeiro && $cobranca->status === $S::Aprovada)
      <h2 style="margin-top:0">Registrar pagamento</h2>
      <p class="sub">Aprovada por {{ $cobranca->aprovadaPor?->name }} em {{ $cobranca->aprovada_em?->format('d/m/Y H:i') }}.
        @if ($cobranca->prestador->chave_pix) PIX: <span class="mono">{{ $cobranca->prestador->chave_pix }}</span>@endif</p>
      <form method="post" action="{{ route('cobrancas.pagar', $cobranca) }}" class="grade">
        @csrf
        <div><label for="valor">Valor pago</label><input id="valor" name="valor" value="{{ old('valor', valor_campo($cobranca->notaFiscal?->valor_liquido_centavos ?? $cobranca->valor_centavos)) }}" inputmode="decimal" required></div>
        <div><label for="data">Data</label><input id="data" type="date" name="data" value="{{ old('data', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
        <div style="align-self:end"><button>Registrar</button></div>
      </form>
      <p class="sub">O valor sugerido é o líquido da nota (após retenções), quando houver XML.</p>
    @endif
  </div>
</div>

@if ($eventos->isNotEmpty() && ! $u->ehPrestador())
  <h2>Histórico</h2>
  <div class="tabela"><table>
    <thead><tr><th>Quando</th><th>Quem</th><th>O quê</th><th>Detalhe</th></tr></thead>
    <tbody>
    @foreach ($eventos as $e)
      <tr><td>{{ $e->created_at->format('d/m/Y H:i') }}</td><td>{{ $e->user?->name ?? 'Sistema' }}</td><td>{{ $e->acao }}</td>
        <td>{{ collect($e->dados ?? [])->map(fn ($v, $k) => $k === 'valor' ? "valor: ".brl((int) $v) : "$k: $v")->join(' · ') }}</td></tr>
    @endforeach
    </tbody>
  </table></div>
@endif
@endsection
