@extends('layouts.app')
@section('titulo', 'Contabilidade '.$competencia->format('m/Y'))
@section('conteudo')
@php
  $P = \App\Enums\Papel::class; $E = \App\Enums\EtapaContabil::class; $S = \App\Enums\StatusSolicitacao::class;
  $empresa = ctx()->tem($P::Admin, $P::Financeiro);
  $contador = ctx()->tem($P::Analista, $P::Revisor);
  $revisor = ctx()->tem($P::Revisor);
  $aberta = ! $cc->fechada();
  $mes = $competencia->format('Y-m');
@endphp
<a class="voltar" href="{{ route('contabil.index') }}">← Contabilidade</a>
<div class="barra">
  <div>
    <h1>Fechamento contábil</h1>
    <p class="sub">{{ ctx()->empresa()->razao_social }} @if ($cc->versao_atual) · versão {{ $cc->versao_atual }} @endif</p>
  </div>
  <nav class="mes" aria-label="Competência">
    <a href="{{ route('contabil.show', $competencia->subMonthNoOverflow()->format('Y-m')) }}" aria-label="Mês anterior">‹</a>
    <strong>{{ $competencia->translatedFormat('F \d\e Y') }}</strong>
    <a href="{{ route('contabil.show', $competencia->addMonthNoOverflow()->format('Y-m')) }}" aria-label="Próximo mês">›</a>
  </nav>
</div>

<ol class="etapas" aria-label="Etapas do fechamento">
  @foreach ($E::cases() as $e)
    <li @class(['feita' => $e->ordem() < $cc->etapa->ordem(), 'atual' => $e === $cc->etapa])>{{ $e->rotulo() }}</li>
  @endforeach
</ol>

@if (! $aberta)
  <div class="aviso ok">Competência <strong>fechada</strong> (versão {{ $cc->versao_atual }}). Nada pode ser enviado ou alterado sem reabertura pelo revisor do escritório.</div>
@elseif ($empresa && $cc->enviada_em)
  <div class="aviso info">Envio concluído em {{ $cc->enviada_em->format('d/m/Y H:i') }} por {{ $cc->enviadaPor?->name }}. Você ainda pode mandar documentos até o fechamento.</div>
@endif

<section class="cards" aria-label="Resumo do mês">
  <div class="card"><div class="rot">Faturado</div><div class="num">{{ brl($r['faturado']) }}</div><div class="det">{{ $r['faturas']->whereNotNull('nota_fiscal_id')->count() }} de {{ $r['faturas']->count() }} com NFS-e</div></div>
  <div class="card"><div class="rot">Recebido</div><div class="num">{{ brl($r['recebido']) }}</div><div class="det"><a href="{{ route('faturas.index', ['competencia' => $mes]) }}">ver faturas</a></div></div>
  <div class="card"><div class="rot">Custo com prestadores</div><div class="num">{{ brl($r['custo_total']) }}</div><div class="det">{{ brl($r['pago_prestadores']) }} pago · <a href="{{ route('cobrancas.index', ['competencia' => $mes]) }}">ver</a></div></div>
  <div class="card"><div class="rot">Documentos enviados</div><div class="num">{{ $cc->documentos->count() }}</div><div class="det">{{ $cc->pendentes() }} solicitação(ões) pendente(s)</div></div>
</section>

<div class="duas" style="margin-top:24px">
  {{-- Documentos --}}
  <div>
    <h2 style="margin-top:0">Documentos do mês</h2>
    <div class="tabela"><table>
      <thead><tr><th>Arquivo</th><th>Tipo</th><th>Enviado</th></tr></thead>
      <tbody>
      @forelse ($cc->documentos as $d)
        <tr>
          <td><a href="{{ route('contabil.documento', $d) }}">{{ $d->nome_original }}</a><br><span class="sub" style="font-size:12.5px">{{ $d->descricao ?? '' }} {{ $d->tamanhoLegivel() }}</span></td>
          <td>{{ $d->categoria->rotulo() }}</td>
          <td>{{ $d->created_at->format('d/m H:i') }}<br><span class="sub" style="font-size:12.5px">{{ $d->enviadoPor?->name }}</span></td>
        </tr>
      @empty
        <tr><td colspan="3" class="vazio">Nenhum documento enviado ainda.</td></tr>
      @endforelse
      </tbody>
    </table></div>

    @if ($empresa && $aberta)
      <form method="post" action="{{ route('contabil.enviar_documento', $mes) }}" enctype="multipart/form-data" class="painel" style="margin-top:12px">
        @csrf
        <strong>Enviar documento</strong>
        <div class="grade" style="margin-top:8px">
          <div><label for="categoria">Tipo</label><select id="categoria" name="categoria">
            @foreach ($categorias as $cat)<option value="{{ $cat->value }}" @selected(old('categoria') === $cat->value)>{{ $cat->rotulo() }}</option>@endforeach
          </select></div>
          <div><label for="descricao">Descrição (opcional)</label><input id="descricao" name="descricao" value="{{ old('descricao') }}" placeholder="Ex.: extrato Banco X"></div>
        </div>
        <div class="campo" style="margin-top:10px"><label for="arquivo">Arquivo (até 10 MB: PDF, XML, planilha, OFX, imagem ou ZIP)</label><input id="arquivo" type="file" name="arquivo" required></div>
        <button>Enviar</button>
      </form>
      <form method="post" action="{{ route('contabil.enviar', $mes) }}" style="margin-top:12px">
        @csrf
        <button class="sec">{{ $cc->enviada_em ? 'Avisar o contador novamente' : 'Concluí o envio deste mês' }}</button>
      </form>
    @endif
  </div>

  {{-- Solicitações --}}
  <div>
    <h2 style="margin-top:0">Solicitações do contador</h2>
    @forelse ($cc->solicitacoes->sortBy(fn ($s) => [$s->status->pendente() ? 0 : 1, $s->id]) as $s)
      <div class="painel solicitacao" style="margin-bottom:10px">
        <div class="opcao">
          <strong>{{ $s->titulo }}</strong>
          <span class="tag {{ $s->status === $S::Aberta ? 'warn' : ($s->status === $S::Respondida ? 'info' : ($s->status === $S::Aceita ? 'ok' : '')) }}">{{ $s->status->rotulo() }}</span>
        </div>
        <p class="sub" style="margin:4px 0 0;font-size:13px">por {{ $s->criadaPor?->name ?? '—' }} em {{ $s->created_at->format('d/m') }}
          @if ($s->prazo) · prazo {{ $s->prazo->format('d/m/Y') }} @if ($s->atrasada())<span class="tag bad">Atrasada</span>@endif @endif</p>
        @if ($s->descricao)<p style="white-space:pre-line;margin:8px 0 0">{{ $s->descricao }}</p>@endif
        @if ($s->resposta || $s->documentos->isNotEmpty())
          <div class="resposta">
            <span class="sub" style="font-size:12.5px">Resposta de {{ $s->respondidaPor?->name }} em {{ $s->respondida_em?->format('d/m H:i') }}</span>
            @if ($s->resposta)<p style="white-space:pre-line;margin:4px 0 0">{{ $s->resposta }}</p>@endif
            @foreach ($s->documentos as $d)<div><a href="{{ route('contabil.documento', $d) }}">{{ $d->nome_original }}</a></div>@endforeach
          </div>
        @endif

        @if ($aberta && $empresa && $s->status === $S::Aberta)
          <form method="post" action="{{ route('contabil.responder', $s) }}" enctype="multipart/form-data" style="margin-top:10px">
            @csrf
            <label for="resp{{ $s->id }}">Responder</label>
            <textarea id="resp{{ $s->id }}" name="resposta" rows="2"></textarea>
            <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap"><input type="file" name="arquivo" style="flex:1;min-width:180px" aria-label="Anexo"><button>Enviar resposta</button></div>
          </form>
        @endif
        @if ($aberta && $contador && $s->status === $S::Respondida)
          <div class="acoes" style="margin-top:10px">
            <form method="post" action="{{ route('contabil.aceitar', $s) }}">@csrf<button>Aceitar</button></form>
            <form method="post" action="{{ route('contabil.devolver', $s) }}" style="display:flex;gap:6px;flex:1">@csrf
              <input name="motivo" placeholder="O que falta?" required aria-label="Motivo da devolução"><button class="sec">Devolver</button></form>
          </div>
        @endif
        @if ($aberta && $contador && $s->status->pendente())
          <form method="post" action="{{ route('contabil.cancelar', $s) }}" style="margin-top:6px">@csrf<button class="link">cancelar solicitação</button></form>
        @endif
      </div>
    @empty
      <p class="vazio painel">Nenhuma solicitação neste mês.</p>
    @endforelse

    @if ($contador && $aberta)
      <form method="post" action="{{ route('contabil.solicitar', $mes) }}" class="painel" style="margin-top:12px">
        @csrf
        <strong>Pedir documento ou informação</strong>
        <div class="campo" style="margin-top:8px"><label for="titulo">O que é preciso</label><input id="titulo" name="titulo" required placeholder="Ex.: Extrato do Banco X de outubro"></div>
        <div class="campo"><label for="desc_sol">Detalhes (opcional)</label><textarea id="desc_sol" name="descricao" rows="2"></textarea></div>
        <div style="display:flex;gap:8px;align-items:end"><div><label for="prazo">Prazo (opcional)</label><input id="prazo" type="date" name="prazo" min="{{ now()->toDateString() }}"></div><button>Enviar à empresa</button></div>
      </form>
    @endif
  </div>
</div>

@if ($contador)
  <h2>Andamento</h2>
  <div class="painel">
    @if ($aberta)
      <div class="acoes" style="justify-content:space-between;align-items:end">
        <form method="post" action="{{ route('contabil.etapa', $mes) }}" style="display:flex;gap:8px;align-items:end">
          @csrf
          <div><label for="etapa">Mover para</label><select id="etapa" name="etapa">
            @foreach ([$E::Coleta, $E::Conferencia, $E::Revisao] as $e)<option value="{{ $e->value }}" @selected($e === $cc->etapa)>{{ $e->rotulo() }}</option>@endforeach
          </select></div>
          <button class="sec">Atualizar etapa</button>
        </form>
        @if ($revisor)
          <form method="post" action="{{ route('contabil.fechar', $mes) }}" style="display:flex;gap:8px;align-items:end;flex:1;justify-content:flex-end">
            @csrf
            <div style="flex:1;max-width:340px"><label for="observacao">Observação do fechamento (opcional)</label><input id="observacao" name="observacao"></div>
            <button @disabled($cc->etapa !== $E::Revisao || $cc->pendentes() > 0)>Fechar competência</button>
          </form>
        @endif
      </div>
      <p class="sub" style="margin:10px 0 0;font-size:13px">
        @if ($revisor) Para fechar: etapa “Em revisão” e nenhuma solicitação pendente. O fechamento grava um pacote imutável (planilhas, XMLs, documentos e hashes).
        @else O analista conduz até “Em revisão”; fechar e reabrir são do revisor do escritório. @endif
      </p>
    @elseif ($revisor)
      <form method="post" action="{{ route('contabil.reabrir', $mes) }}" style="display:flex;gap:8px;align-items:end">
        @csrf
        <div style="flex:1"><label for="justificativa">Justificativa para reabrir (fica registrada)</label><input id="justificativa" name="justificativa" required placeholder="Ex.: nota substituída após o fechamento"></div>
        <button class="perigo">Reabrir</button>
      </form>
    @else
      <p class="sub" style="margin:0">Competência fechada. Só o revisor pode reabrir.</p>
    @endif
  </div>
@endif

@if ($cc->versoes->isNotEmpty())
  <h2>Versões do fechamento</h2>
  <div class="tabela"><table>
    <thead><tr><th>Versão</th><th>Fechada em</th><th>Por</th><th class="n">Faturado</th><th class="n">Documentos</th><th>SHA-256</th><th></th></tr></thead>
    <tbody>
    @foreach ($cc->versoes->sortByDesc('versao') as $v)
      <tr>
        <td>v{{ $v->versao }} @if ($v->versao === $cc->versao_atual && $cc->fechada())<span class="tag ok">vigente</span>@endif</td>
        <td>{{ $v->created_at->format('d/m/Y H:i') }}</td><td>{{ $v->fechadoPor?->name }}</td>
        <td class="n">{{ brl($v->resumo['faturado'] ?? null) }}</td><td class="n">{{ $v->resumo['documentos'] ?? 0 }}</td>
        <td class="mono" title="{{ $v->sha256 }}">{{ substr($v->sha256, 0, 12) }}…</td>
        <td><a href="{{ route('contabil.versao', $v) }}">Baixar .zip</a></td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
@endif

@if ($historico->isNotEmpty())
  <h2>Histórico</h2>
  <div class="tabela"><table>
    <thead><tr><th>Quando</th><th>Quem</th><th>O quê</th><th>Detalhe</th></tr></thead>
    <tbody>
    @foreach ($historico as $e)
      <tr><td>{{ $e->created_at->format('d/m/Y H:i') }}</td><td>{{ $e->user?->name ?? 'Sistema' }}</td><td>{{ $e->rotulo() }}</td>
        <td>@if (isset($e->dados['de'], $e->dados['para'])){{ $e->dados['de'] }} → {{ $e->dados['para'] }}
          @else{{ collect($e->dados ?? [])->map(fn ($v, $k) => $k === 'versao' ? 'v'.$v : $v)->join(' · ') }}@endif</td></tr>
    @endforeach
    </tbody>
  </table></div>
@endif
@endsection
