@extends('layouts.app')
@section('titulo', 'Google Agenda')
@section('conteudo')
<x-cabecalho :trilha="['Configurações' => route('configuracoes'), 'Integrações' => null, 'Google Agenda' => null]" titulo="Google Agenda"
  sub="Seus vencimentos no Google Agenda, com os lembretes que você escolher. Sincronização em um sentido: o Augi atualiza o Google; mudanças feitas no Google não alteram o financeiro." />

@if (! $configurado)
  <div class="aviso info"><strong>Integração ainda não habilitada nesta instalação.</strong>
    O administrador precisa cadastrar as credenciais OAuth do Google (veja docs/google-agenda.md). Enquanto isso, use o botão
    “Adicionar ao Google Agenda” em cada lançamento para salvar o evento manualmente, e os lembretes internos do Augi.</div>
@elseif (! $c)
  <div class="duas">
    <form method="post" action="{{ route('integracoes.google.conectar') }}" class="painel">@csrf
      <strong>Conectar minha conta Google</strong>
      <p class="sub" style="font-size:13.5px">Escolha onde os vencimentos devem aparecer:</p>
      <label class="opcao-radio"><input type="radio" name="modo" value="dedicada" checked>
        <span><strong>Agenda separada “{{ \App\Services\GoogleAgenda::NOME_AGENDA }}”</strong> (recomendado)<br>
        <span class="sub" style="font-size:12.5px">O Augi só consegue ver e alterar a agenda que ele mesmo cria. Não acessa seus outros compromissos.</span></span></label>
      <label class="opcao-radio"><input type="radio" name="modo" value="existente">
        <span><strong>Uma agenda que já existe</strong><br>
        <span class="sub" style="font-size:12.5px">Pede permissão para criar eventos nas suas agendas. Use se preferir ver tudo junto.</span></span></label>
      <button style="margin-top:10px">Conectar com o Google</button>
    </form>
    <div class="painel">
      <strong>Como funciona</strong>
      <ul style="margin:8px 0 0;padding-left:18px;color:var(--muted);font-size:13.5px;line-height:1.6">
        <li>Cada conta a pagar (ou parcela) vira <strong>um evento</strong>, com até 5 lembretes.</li>
        <li>Mudou o vencimento? O <strong>mesmo evento</strong> é atualizado. Pagou? Os alertas param.</li>
        <li>Por padrão, o evento tem título discreto, <strong>sem nome nem valor</strong>; o detalhe fica no Augi.</li>
        <li>A conexão é sua e só desta empresa: não dá acesso a dados de outras empresas.</li>
        <li>Se o Google falhar, os lembretes internos do Augi continuam funcionando.</li>
      </ul>
    </div>
  </div>
@else
  @if ($c->status !== 'ativa')
    <div class="aviso bad"><strong>Conexão interrompida:</strong> {{ $c->ultimo_erro }} Os lembretes internos continuam. Desconecte e conecte de novo.</div>
  @elseif ($c->ultimo_erro)
    <div class="aviso info"><strong>Último problema:</strong> {{ $c->ultimo_erro }}</div>
  @endif
  <section class="cards" aria-label="Situação">
    <div class="card serie-saldo"><div class="rot">Conta Google</div><div class="num texto">{{ $c->email_google ?? '—' }}</div><div class="det">{{ $c->status === 'ativa' ? 'conectada' : 'interrompida' }}</div></div>
    <div class="card"><div class="rot">Agenda</div><div class="num texto">{{ $c->calendar_nome ?? '—' }}</div><div class="det">{{ $c->modo === 'dedicada' ? 'separada, criada pelo Augi' : 'existente' }}</div></div>
    <div class="card"><div class="rot">Eventos sincronizados</div><div class="num">{{ $eventos }}</div><div class="det">{{ $comErro ? $comErro.' com erro (refeito automaticamente)' : 'sem erros' }}</div></div>
    <div class="card"><div class="rot">Última sincronização</div><div class="num texto">{{ $c->sincronizado_em?->format('d/m/Y H:i') ?? '—' }}</div><div class="det">automática a cada 15 min</div></div>
  </section>
  <div class="duas" style="margin-top:16px">
    <form method="post" action="{{ route('integracoes.google.salvar') }}" class="painel">@csrf @method('put')
      <strong>O que sincronizar</strong>
      <div style="display:grid;gap:6px;margin:10px 0">
        @php($s = $c->sincronizar ?? [])
        <label class="marcar"><input type="checkbox" name="pagar" value="1" @checked($s['pagar'] ?? false)> Contas a pagar</label>
        <label class="marcar"><input type="checkbox" name="receber" value="1" @checked($s['receber'] ?? false)> Recebimentos previstos</label>
        <label class="marcar"><input type="checkbox" name="cartao" value="1" @checked($s['cartao'] ?? false)> Faturas de cartão</label>
        <label class="marcar"><input type="checkbox" name="titulos_discretos" value="1" @checked($c->titulos_discretos)> Títulos discretos (sem nomes nem valores no Google)</label>
      </div>
      @if ($c->modo === 'existente' && $agendas->isNotEmpty())
        <div class="campo"><label for="calendar_id">Agenda</label><select id="calendar_id" name="calendar_id">
          @foreach ($agendas as $a)<option value="{{ $a['id'] }}" @selected($a['id'] === $c->calendar_id)>{{ $a['nome'] }}</option>@endforeach</select></div>
      @endif
      <div class="campo"><label for="ao_quitar">Quando a conta for paga</label><select id="ao_quitar" name="ao_quitar">
        <option value="remover" @selected($c->ao_quitar === 'remover')>Remover o evento</option>
        <option value="marcar" @selected($c->ao_quitar === 'marcar')>Manter o evento, marcado como quitado e sem alertas</option></select></div>
      <button>Salvar</button>
    </form>
    <div class="painel">
      <strong>Ações</strong>
      <form method="post" action="{{ route('integracoes.google.sincronizar') }}" style="margin:10px 0">@csrf<button class="sec">Sincronizar agora</button></form>
      <p class="sub" style="font-size:13px">Quando avisar é definido em <a href="{{ route('configuracoes.vencimentos') }}">Lembretes de vencimento</a> (ou na aba Lembretes de cada lançamento).</p>
      <form method="post" action="{{ route('integracoes.google.desconectar') }}" onsubmit="return confirm('Desconectar o Google Agenda?')" style="margin-top:14px">@csrf @method('delete')
        <label class="marcar"><input type="checkbox" name="limpar" value="1" checked> Apagar do Google os eventos criados pelo Augi</label>
        <div style="margin-top:8px"><button class="perigo">Desconectar e revogar acesso</button></div>
      </form>
    </div>
  </div>
@endif
@endsection
