@extends('layouts.app')
@section('titulo', 'Cobrança '.$cobranca->prestador->nome)
@section('conteudo')
@php

  $financeiro = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro);
  $S = \App\Enums\StatusCobranca::class;
@endphp
<a class="voltar" href="{{ ctx()->ehPrestador() ? route('painel') : route('cobrancas.index', ['competencia' => $cobranca->competencia->format('Y-m')]) }}">← Voltar</a>
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
      @if (! $cobranca->notaFiscal->temXml() && (ctx()->ehPrestador() || $financeiro))
        <form method="post" action="{{ route('cobrancas.xml', $cobranca) }}" enctype="multipart/form-data" class="campo" style="margin-top:14px">
          @csrf
          <label for="xml_anexo">Anexar o XML desta nota</label>
          <div style="display:flex;gap:8px"><input id="xml_anexo" type="file" name="xml" accept=".xml,text/xml,application/xml" required><button class="sec">Anexar</button></div>
        </form>
      @endif
    @endif

    @if ($cobranca->conferencia && ! ctx()->ehPrestador())
      <h2>Conferência automática</h2>
      @php($rotNat = ['fiscal' => 'fiscal', 'comercial' => 'comercial', 'verificacao' => 'verificação', 'aviso' => 'aviso'])
      <ul class="check">
        @foreach ($cobranca->conferencia as $i)
          @php($nat = $i['natureza'] ?? 'fiscal')
          <li>
            <span class="ic" style="color:var(--{{ $i['ok'] ? 'ok' : (in_array($nat, ['fiscal', 'comercial']) ? 'bad' : 'warn') }})">{{ $i['ok'] ? '✓' : (in_array($nat, ['fiscal', 'comercial']) ? '✗' : '!') }}</span>
            <span><strong>{{ $i['item'] }}</strong> <span class="tag rotulo">{{ $rotNat[$nat] }}</span><br><span class="sub">{{ $i['detalhe'] }}</span></span>
          </li>
        @endforeach
      </ul>
    @endif

    @if ($financeiro && $cobranca->status === $S::EmConferencia)
      <h2>Decisão do financeiro</h2>
      @if ($cobranca->invalidaFiscalmente())
        <div class="aviso bad"><strong>Nota inválida para esta cobrança.</strong> Não passou em: {{ mb_strtolower(implode('; ', $cobranca->falhas('fiscal'))) }}.
          Não pode ser aprovada, nem com justificativa: rejeite e peça uma nova nota.</div>
      @else
        <form method="post" action="{{ route('cobrancas.aprovar', $cobranca) }}" class="campo">
          @csrf
          @if ($cobranca->temDivergenciaComercial())
            <div class="aviso info"><strong>Divergência comercial.</strong> Não passou em: {{ mb_strtolower(implode('; ', $cobranca->falhas('comercial'))) }}.
              @unless (ctx()->tem(\App\Enums\Papel::Admin)) Só o administrador pode aprovar com ressalva. @endunless</div>
          @elseif ($cobranca->naoVerificada())
            <div class="aviso info"><strong>Nota não verificada:</strong> só a chave foi informada. Se aprovar, a pendência do XML continua no fechamento.</div>
          @endif
          @unless ($cobranca->conferenciaOk())
            @if (! $cobranca->temDivergenciaComercial() || ctx()->tem(\App\Enums\Papel::Admin))
              <div class="campo"><label for="justificativa">Justificativa (fica no histórico)</label><textarea id="justificativa" name="justificativa" rows="2" required>{{ old('justificativa') }}</textarea></div>
              <button>Aprovar com ressalva</button>
            @endif
          @else
            <button>Aprovar para pagamento</button>
          @endunless
        </form>
      @endif
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
      @php($ret = $cobranca->notaFiscal && $cobranca->notaFiscal->valor_liquido_centavos !== null ? max(0, $cobranca->notaFiscal->valor_servicos_centavos - $cobranca->notaFiscal->valor_liquido_centavos) : 0)
      @php($jaPago = $titulo?->liquidado() ?? 0)
      @if ($jaPago > 0)<p class="sub">Já abatido: {{ brl($jaPago) }} · saldo {{ brl($titulo->saldo()) }} · <a href="{{ route('financeiro.titulo', $titulo) }}">ver baixas</a></p>@endif
      <form method="post" action="{{ route('cobrancas.pagar', $cobranca) }}" class="grade">
        @csrf <input type="hidden" name="chave" value="{{ $chave }}">
        <div><label for="conta_id">Conta</label><select id="conta_id" name="conta_id">
          @forelse ($contas as $c)<option value="{{ $c->id }}">{{ $c->nome }}</option>@empty<option value="">Conta principal (será criada)</option>@endforelse</select></div>
        <div><label for="valor">Valor pago</label><input id="valor" name="valor" value="{{ old('valor', valor_campo($jaPago > 0 ? $titulo->saldo() : $cobranca->valor_centavos - $ret)) }}" inputmode="decimal" required></div>
        <div><label for="retencao">Retenções</label><input id="retencao" name="retencao" value="{{ old('retencao', $jaPago > 0 ? '0,00' : valor_campo($ret)) }}" inputmode="decimal"></div>
        <div><label for="data">Data</label><input id="data" type="date" name="data" value="{{ old('data', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
        <div style="align-self:end"><button>Registrar</button></div>
      </form>
      <p class="sub">Sugestão: líquido da nota; a retenção (valor do serviço − líquido) abate a cobrança sem sair da conta. Pagamento parcial mantém o saldo em aberto.</p>
    @endif
  </div>
</div>

@if ($eventos->isNotEmpty() && ! ctx()->ehPrestador())
  <h2>Histórico</h2>
  <div class="tabela"><table>
    <thead><tr><th>Quando</th><th>Quem</th><th>O quê</th><th>Detalhe</th></tr></thead>
    <tbody>
    @foreach ($eventos as $e)
      <tr><td>{{ $e->created_at->format('d/m/Y H:i') }}</td><td>{{ $e->user?->name ?? 'Sistema' }}</td><td>{{ $e->rotulo() }}</td>
        <td>{{ collect($e->dados ?? [])->except('chave')->map(fn ($v, $k) => $k === 'valor' ? brl((int) $v) : ($k === 'origem' ? ($v === 'xml' ? 'com XML' : 'só a chave') : $v))->join(' · ') }}</td></tr>
    @endforeach
    </tbody>
  </table></div>
@endif
@endsection
