@extends('layouts.app')
@section('titulo', $conta->nome)
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Contas e bancos' => route('financeiro.contas'), $conta->nome => null]"
  :titulo="$conta->nome" :sub="trim(($conta->banco ?? '').' '.($conta->identificacao ?? '')) ?: null">
  <x-slot:acoes>
    <a class="botao sec" href="{{ route('financeiro.conciliacao', $conta) }}">Conciliação</a>
    @if ($opera)<a class="botao" href="{{ route('financeiro.conciliacao', $conta) }}#importar">Importar extrato</a>@endif
  </x-slot:acoes>
</x-cabecalho>

@if ($s['estado'] === 'desatualizado')
  <div class="aviso bad acao-alerta">
    <div><strong>Extrato bancário pendente.</strong> Esta conta tem extrato só {{ $conta->extrato_ate ? 'até '.$conta->extrato_ate->format('d/m/Y') : '— nenhum importado' }}.
      Pela regra ({{ $conta->regraLembrete() }}), o extrato deve cobrir até <strong>{{ $s['exigido_ate']->format('d/m/Y') }}</strong>.</div>
    @if ($opera)<div class="acoes">
      <a class="botao" href="{{ route('financeiro.conciliacao', $conta) }}#importar">Importar extrato</a>
      <form method="post" action="{{ route('financeiro.conta_dispensar', $conta) }}">@csrf<input type="hidden" name="adiar" value="1"><button class="sec">Adiar 3 dias</button></form>
    </div>@endif
  </div>
@endif

<x-abas :itens="[
  ['Resumo', route('financeiro.conta', $conta), $aba === 'resumo'],
  ['Lembrete de extrato', route('financeiro.conta', [$conta, 'aba' => 'lembrete']), $aba === 'lembrete'],
  ['Importações', route('financeiro.conta', [$conta, 'aba' => 'importacoes']), $aba === 'importacoes', $importacoes->count()],
]" />

@if ($aba === 'resumo')
  <section class="cards">
    <div class="card"><div class="rot">Saldo no sistema</div><div class="num">{{ brl($saldo) }}</div><div class="det">hoje</div></div>
    <div class="card"><div class="rot">Extrato coberto até</div><div class="num texto">{{ $conta->extrato_ate?->format('d/m/Y') ?? '—' }}</div><div class="det">{{ $conta->extrato_importado_em ? 'importado em '.$conta->extrato_importado_em->format('d/m/Y H:i') : 'nenhuma importação' }}</div></div>
    <div class="card"><div class="rot">Situação do extrato</div><div class="num texto">{{ $s['rotulo'] }}</div><div class="det">{{ $s['proximo_prazo'] ? 'próximo prazo '.$s['proximo_prazo']->format('d/m H:i') : 'lembrete desligado' }}</div></div>
    <div class="card"><div class="rot">Conciliação</div><div class="num">{{ $s['a_conciliar'] }}</div><div class="det">{{ $s['antigas'] }} com mais de 7 dias · <a href="{{ route('financeiro.conciliacao', $conta) }}">conciliar</a></div></div>
  </section>
  @if ($conta->lembrete_dispensado_ate)
    <p class="sub" style="margin-top:12px">Última dispensa: até {{ $conta->lembrete_dispensado_ate->format('d/m/Y') }} — {{ $conta->lembrete_dispensa_motivo }}</p>
  @endif
  @if ($opera)
    <form method="post" action="{{ route('financeiro.conta_alternar', $conta) }}" style="margin-top:20px">@csrf @method('patch')
      <button class="link">{{ $conta->ativa ? 'Desativar esta conta (o histórico continua disponível)' : 'Reativar esta conta' }}</button></form>
  @endif

@elseif ($aba === 'lembrete')
  <div class="duas">
    <form method="post" action="{{ route('financeiro.conta_lembrete', $conta) }}" class="painel">
      @csrf @method('put')
      <label style="display:flex;gap:8px;align-items:center;color:var(--text);margin-bottom:14px"><input type="checkbox" name="lembrete_ativo" value="1" @checked($conta->lembrete_ativo) @disabled(! $opera)> <strong>Lembrete de atualização ativo</strong></label>
      <div class="grade">
        <div class="campo"><label for="freq">Frequência</label><select id="freq" name="lembrete_frequencia" @disabled(! $opera)>
          @foreach (\App\Models\ContaFinanceira::FREQUENCIAS as $k => $r)<option value="{{ $k }}" @selected($conta->lembrete_frequencia === $k)>{{ $r }}</option>@endforeach</select></div>
        <div class="campo"><label for="dia">Dia <span style="color:var(--faint)">(semana: 1=seg … 7=dom · mês: 1–28)</span></label>
          <input id="dia" type="number" min="1" max="28" name="lembrete_dia" value="{{ $conta->lembrete_dia }}" @disabled(! $opera)></div>
        <div class="campo"><label for="hora">Horário</label><input id="hora" type="time" name="lembrete_hora" value="{{ $conta->lembrete_hora }}" @disabled(! $opera)></div>
        <div class="campo"><label for="resp">Responsável</label><select id="resp" name="lembrete_responsavel_id" @disabled(! $opera)>
          <option value="">Administradores e financeiro</option>
          @foreach ($responsaveis as $u)<option value="{{ $u->id }}" @selected($conta->lembrete_responsavel_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
        <div class="campo"><label for="ant">Avisar antes (dias)</label><input id="ant" type="number" min="0" max="10" name="lembrete_antecedencia" value="{{ $conta->lembrete_antecedencia }}" @disabled(! $opera)></div>
        <div class="campo"><label for="rep">Se atrasar, repetir a cada (dias)</label><input id="rep" type="number" min="1" max="14" name="lembrete_repetir_dias" value="{{ $conta->lembrete_repetir_dias }}" @disabled(! $opera)></div>
      </div>
      @if ($opera)<button>Salvar lembrete</button>@endif
    </form>
    <div>
      <div class="painel">
        <strong>Como funciona</strong>
        <ul style="margin:8px 0 0;padding-left:18px;color:var(--muted);font-size:13.5px;line-height:1.6">
          <li>No prazo (ex.: toda segunda às 09:00), o extrato importado deve cobrir <strong style="color:var(--text)">até o dia anterior</strong>.</li>
          <li>Enviar um arquivo antigo <strong style="color:var(--text)">não</strong> atualiza a conta: vale o período que o extrato cobre.</li>
          <li>Enquanto estiver atrasado, a notificação se repete no intervalo configurado.</li>
          <li>Conciliação pendente é um alerta separado: os dados chegaram, mas ainda não foram conferidos.</li>
          <li>Canal: notificação interna. E-mail entra quando o envio estiver configurado no servidor.</li>
        </ul>
      </div>
      @if ($opera && $conta->lembrete_ativo)
      <form method="post" action="{{ route('financeiro.conta_dispensar', $conta) }}" class="painel" style="margin-top:12px">
        @csrf
        <strong>Dispensar lembrete temporariamente</strong>
        <p class="sub" style="margin:6px 0;font-size:13.5px">Ex.: conta sem movimento no período. Fica registrado na auditoria.</p>
        <div class="grade"><div><label for="ate">Até</label><input id="ate" type="date" name="ate" min="{{ now()->toDateString() }}" required></div>
          <div><label for="motivo">Motivo</label><input id="motivo" name="motivo" required></div></div>
        <button class="sec" style="margin-top:10px">Dispensar</button>
      </form>
      @endif
    </div>
  </div>

@else
  <div class="tabela"><table>
    <thead><tr><th>Importado em</th><th>Arquivo</th><th>Período coberto</th><th class="n">Novos</th><th class="n">Já existiam</th><th>Por</th></tr></thead>
    <tbody>
    @forelse ($importacoes as $i)
      <tr><td>{{ $i->created_at->format('d/m/Y H:i') }}</td><td>{{ $i->arquivo }}</td>
        <td>{{ $i->periodo_inicio?->format('d/m/Y') ?? '?' }} a {{ $i->periodo_fim?->format('d/m/Y') ?? '?' }}</td>
        <td class="n">{{ $i->linhas_novas }}</td><td class="n">{{ $i->linhas_repetidas }}</td><td>{{ $i->enviadoPor?->name ?? '—' }}</td></tr>
    @empty
      <tr><td colspan="6" class="vazio">Nenhum extrato importado.</td></tr>
    @endforelse
    </tbody>
  </table></div>
@endif
@endsection
