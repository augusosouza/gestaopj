@extends('layouts.app')
@php($R = $tipo === 'receber')
@php($T = $R ? ['titulo' => 'Contas a receber', 'serie' => 'receita', 'verbo' => 'Receber', 'pago' => 'Recebido', 'quem' => 'Cliente', 'nova' => '+ Nova receita']
  : ['titulo' => 'Contas a pagar', 'serie' => 'despesa', 'verbo' => 'Pagar', 'pago' => 'Pago', 'quem' => 'Fornecedor', 'nova' => '+ Nova conta'])
@section('titulo', $T['titulo'])
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@php($q = request()->except(['aba', 'mes']))
@php($rota = $R ? 'contas_receber.index' : 'contas_pagar.index')
@php($url = fn ($a, $extra = []) => route($rota, array_merge($q, ['aba' => $a], $extra)))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), $T['titulo'] => null]" :titulo="$T['titulo']"
  :sub="$R ? 'Recebimentos previstos, atrasos, cobranças e renegociações com clientes.' : 'Todos os compromissos de pagamento: contas, parcelas de compras e faturas de cartão.'">
  <x-slot:acoes>
    @include('partials.exportar', ['relatorio' => $R ? 'inadimplencia' : 'lancamentos', 'extra' => $R ? [] : ['tipo' => 'pagar', 'periodo' => 'todos', 'situacao' => 'aberto']])
    @if ($opera)<a class="botao" href="{{ route('financeiro.novo', ['tipo' => $tipo]) }}">{{ $T['nova'] }}</a>@endif
  </x-slot:acoes>
</x-cabecalho>

@if ($R)
<section class="cards" aria-label="Indicadores">
  <div class="card serie-comparativo"><div class="rot">Previsto no mês</div><div class="num">{{ brl($ind['previsto_mes']) }}</div><div class="det">recebido + a receber com vencimento no mês</div></div>
  <div class="card serie-receita"><div class="rot">Recebido no mês</div><div class="num">{{ brl($ind['pago_mes']) }}</div><div class="det"><a href="{{ $url('pagas') }}">ver recebimentos</a></div></div>
  <div class="card serie-receita"><div class="rot">Em aberto</div><div class="num">{{ brl($ind['aberto']) }}</div><div class="det">{{ $ind['qtd'] }} parcela(s) · 7 dias: {{ brl($ind['sete']) }}</div></div>
  <div class="card serie-pendencia"><div class="rot">Atrasado</div><div class="num" @if ($ind['atrasado']) style="color:var(--bad)" @endif>{{ brl($ind['atrasado']) }}</div>
    <div class="det">{{ $ind['inadimplentes'] }} cliente(s) · <a href="{{ $url('vencidas') }}">cobrar</a></div></div>
</section>
@if ($ind['renegociado'])<p class="sub" style="font-size:13px;margin:8px 0 0">Renegociado neste mês: {{ brl($ind['renegociado']) }} · <a href="{{ $url('renegociacoes') }}">ver acordos</a></p>@endif
@else
<section class="cards cards-6" aria-label="Indicadores">
  <div class="card serie-despesa"><div class="rot">Total em aberto</div><div class="num">{{ brl($ind['aberto']) }}</div><div class="det">{{ $ind['qtd'] }} compromisso(s)</div></div>
  <div class="card serie-pendencia"><div class="rot">Vencido</div><div class="num" @if ($ind['atrasado']) style="color:var(--bad)" @endif>{{ brl($ind['atrasado']) }}</div>
    <div class="det">{{ $ind['qtd_atrasado'] }} conta(s) · <a href="{{ $url('vencidas') }}">ver</a></div></div>
  <div class="card serie-pendencia"><div class="rot">Vence hoje</div><div class="num">{{ brl($ind['hoje']) }}</div><div class="det">{{ $hoje->format('d/m') }}</div></div>
  <div class="card serie-despesa"><div class="rot">Próximos 7 dias</div><div class="num">{{ brl($ind['sete']) }}</div><div class="det"><a href="{{ $url('aberto', ['proximos' => 7]) }}">ver</a></div></div>
  <div class="card serie-despesa"><div class="rot">Próximos 30 dias</div><div class="num">{{ brl($ind['trinta']) }}</div><div class="det"><a href="{{ $url('aberto', ['proximos' => 30]) }}">ver</a></div></div>
  <div class="card serie-saldo"><div class="rot">Pago neste mês</div><div class="num">{{ brl($ind['pago_mes']) }}</div><div class="det"><a href="{{ $url('pagas') }}">ver pagamentos</a></div></div>
</section>
@endif
<div style="margin-top:16px"></div>
<x-abas :itens="collect($abas)->map(fn ($r, $k) => [$r, $url($k), $aba === $k])->values()->all()" />

@if (in_array($aba, ['aberto', 'vencidas', 'parcelamentos', 'pagas'], true))
<form method="get" class="filtros-pagar" aria-label="Filtros">
  <input type="hidden" name="aba" value="{{ $aba }}">
  <div class="campo"><label for="f-de">{{ $aba === 'pagas' ? $T['pago'].' de' : 'Vence de' }}</label><input id="f-de" type="date" name="de" value="{{ request('de') }}"></div>
  <div class="campo"><label for="f-ate">até</label><input id="f-ate" type="date" name="ate" value="{{ request('ate') }}"></div>
  <div class="campo"><label for="f-cat">Categoria</label><select id="f-cat" name="categoria"><option value="">Todas</option>
    @foreach ($categorias as $c)<option value="{{ $c->id }}" @selected(($f['categoria'] ?? null) === $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
  <div class="campo"><label for="f-forn">{{ $T['quem'] }} ou descrição</label><input id="f-forn" name="fornecedor" value="{{ $f['fornecedor'] ?? '' }}"></div>
  <div class="campo"><label for="f-conta">Conta</label><select id="f-conta" name="conta"><option value="">Todas</option>
    @foreach ($contas as $c)<option value="{{ $c->id }}" @selected(($f['conta'] ?? null) === $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
  <div class="campo"><label for="f-min">Valor de</label><input id="f-min" name="min" inputmode="decimal" value="{{ request('min') }}" placeholder="0,00"></div>
  <div class="campo"><label for="f-max">até</label><input id="f-max" name="max" inputmode="decimal" value="{{ request('max') }}" placeholder="0,00"></div>
  @if ($aba === 'aberto')
    <div class="campo"><label for="f-sit">Situação</label><select id="f-sit" name="situacao"><option value="">Todas</option>
      @foreach (['vencido' => 'Vencidas', 'a_vencer' => 'A vencer', 'parcial' => 'Pagas em parte'] as $k => $v)<option value="{{ $k }}" @selected(($f['situacao'] ?? null) === $k)>{{ $v }}</option>@endforeach</select></div>
  @endif
  <div class="acoes" style="align-self:end"><button class="sec">Filtrar</button>@if (count(array_diff_key($f, ['situacao' => $aba === 'vencidas'])))<a href="{{ route($rota, ['aba' => $aba]) }}" class="sub">limpar</a>@endif</div>
</form>
@if ($aba === 'aberto')
  <nav class="filtros" aria-label="Próximos dias" style="margin-top:-4px">
    @foreach ([7, 15, 30] as $d)<a href="{{ $url('aberto', ['proximos' => $d, 'de' => null, 'ate' => null]) }}" @class(['ativo' => ($f['proximos'] ?? null) === $d])>Próximos {{ $d }} dias</a>@endforeach
  </nav>
@endif
@endif

@if ($aba === 'visao')
  @if ($caixa['negativo'] && ! $R)
    <div class="aviso bad" style="margin-top:4px"><strong>Alerta de caixa:</strong> com o saldo de hoje e os vencimentos previstos, falta {{ brl($caixa['negativo']['deficit']) }} em {{ $caixa['negativo']['data']->format('d/m/Y') }}.
      <a href="{{ route('financeiro.caixa') }}">Abrir Meu Caixa</a></div>
  @endif
  <div class="duas">
    <section class="painel">
      @if ($R && $atrasadosTop->isNotEmpty())
        <strong>Atrasados</strong>
        @foreach ($atrasadosTop as $l)
          <div class="venc-linha"><div><a href="{{ $l['url'] }}">{{ $l['descricao'] }}</a><div class="sub" style="margin:0;font-size:12.5px">{{ $l['fornecedor'] ?? '—' }} · venceu {{ $l['vencimento']->format('d/m/Y') }} ({{ (int) $l['vencimento']->diffInDays($hoje) }} dia(s))</div></div>
            <div class="n"><strong>{{ brl($l['saldo']) }}</strong><br><a href="{{ $l['url'] }}?aba=cobranca" style="font-size:12.5px">cobrar</a></div></div>
        @endforeach
        <div style="margin-top:16px"></div>
      @endif
      <strong>Próximos vencimentos</strong>
      @forelse ($proximos as $l)
        <div class="venc-linha">
          <div><a href="{{ $l['url'] }}">{{ $l['descricao'] }}</a><div class="sub" style="margin:0;font-size:12.5px">{{ $l['vencimento']->format('d/m/Y') }}@if ($l['n']) · {{ $l['tipo'] === 'fatura' ? $l['n'] : 'Parcela '.$l['n'] }}@endif @if ($l['forma']) · {{ $l['forma'] }}@endif</div></div>
          <div class="n"><strong>{{ brl($l['saldo']) }}</strong>@if ($l['atrasado'])<br><span class="tag bad">vencida</span>@elseif ($l['vencimento']->isToday())<br><span class="tag warn">hoje</span>@endif</div>
        </div>
      @empty
        <p class="vazio">Nada a vencer.</p>
      @endforelse
      @if ($proximos->count())<p style="margin:10px 0 0"><a href="{{ $url('aberto') }}">Ver todas em aberto</a></p>@endif
    </section>
    <x-grafico.barras titulo="Em aberto por categoria" :serie="$T['serie']" vazio="Nada em aberto."
      :itens="$categoriasTop->map(fn ($c) => ['rotulo' => $c['rotulo'], 'valor' => $c['valor']])->all()" />
  </div>
  <x-grafico.colunas :titulo="($R ? 'A receber' : 'A pagar').' por semana'" sub="próximas 8 semanas (vencidas entram na primeira)" style="margin-top:16px" :periodos="array_column($semanas, 'rotulo')"
    :series="[['nome' => $R ? 'A receber' : 'A pagar', 'serie' => $T['serie'], 'valores' => array_column($semanas, 'valor')]]" />

@elseif ($aba === 'aberto' || $aba === 'vencidas')
  <div class="tabela"><table>
    <thead><tr><th>Vencimento</th><th>{{ $R ? 'Receita' : 'Conta' }}</th><th>{{ $T['quem'] }}</th><th>Categoria</th><th class="n">Saldo</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($linhas as $l)
      <tr>
        <td>{{ $l['vencimento']->format('d/m/Y') }}</td>
        <td><a href="{{ $l['url'] }}">{{ $l['descricao'] }}</a>@if ($l['n'])<br><span class="sub" style="font-size:12.5px">{{ $l['tipo'] === 'fatura' ? $l['n'] : 'Parcela '.$l['n'] }}@if ($l['forma']) · {{ $l['forma'] }}@endif</span>@endif</td>
        <td>{{ $l['fornecedor'] ?? '—' }}</td><td>{{ $l['categoria'] ?? '—' }}</td>
        <td class="n">{{ brl($l['saldo']) }}@if ($l['parcial'])<br><span class="sub" style="font-size:12px">de {{ brl($l['valor']) }}</span>@endif</td>
        <td>@if ($l['atrasado'])<span class="tag bad">{{ $R ? 'Atrasado' : 'Vencida' }}</span><br><span class="sub" style="font-size:12px">{{ (int) $l['vencimento']->diffInDays($hoje) }} dia(s)</span>@elseif ($l['parcial'])<span class="tag info">Paga em parte</span>@else<span class="tag warn">A vencer</span>@endif</td>
        <td>@if ($opera)
          @if ($l['tipo'] === 'fatura')<a class="botao sec" href="{{ $l['url'] }}#pagar">Pagar fatura</a>
          @else
            @if ($R && $l['atrasado'])<a class="botao sec" href="{{ $l['url'] }}?aba=cobranca" style="margin-bottom:4px">Cobrar</a>@endif
            <details class="pagar-rapido"><summary class="botao sec">{{ $T['verbo'] }}</summary>
              <form method="post" action="{{ route('financeiro.baixar', $l['parcela']) }}">@csrf
                <input type="hidden" name="chave" value="{{ $chave }}-{{ $l['parcela']->id }}">
                <label>Conta<select name="conta_id" required>@foreach ($contas->where('ativa', true) as $c)<option value="{{ $c->id }}" @selected($l['conta_id'] === $c->id)>{{ $c->nome }}</option>@endforeach</select></label>
                <label>Data<input type="date" name="data" value="{{ $hoje->toDateString() }}" max="{{ $hoje->toDateString() }}" required></label>
                <label>Valor {{ $R ? 'recebido' : 'pago' }}<input name="valor" inputmode="decimal" value="{{ valor_campo($l['saldo']) }}" required></label>
                <button>Registrar</button>
                <span class="sub" style="font-size:12px">Valor menor que o saldo = {{ $R ? 'recebimento' : 'pagamento' }} parcial; o restante continua em aberto.</span>
              </form>
            </details>
          @endif
        @endif</td>
      </tr>
    @empty
      <tr><td colspan="7" class="vazio">{{ $aba === 'vencidas' ? 'Nada vencido. 🎉' : 'Nada em aberto' }}{{ count(array_diff_key($f, ['situacao' => 1])) ? ' com esses filtros' : '' }}</td></tr>
    @endforelse
    </tbody>
    @if ($linhas->count())<tfoot><tr><td colspan="4">{{ $linhas->count() }} compromisso(s)</td><td class="n"><strong>{{ brl((int) $linhas->sum('saldo')) }}</strong></td><td colspan="2"></td></tr></tfoot>@endif
  </table></div>

@elseif ($aba === 'parcelamentos')
  <div class="tabela"><table>
    <thead><tr><th>Obrigação</th><th>Progresso</th><th class="n">Pago</th><th class="n">Restante</th><th>Próxima parcela</th></tr></thead>
    <tbody>
    @forelse ($parcelados as $t)
      @php($pagas = $t->parcelas->filter(fn ($p) => $p->saldo() === 0)->count())
      @php($prox = $t->parcelas->first(fn ($p) => $p->saldo() > 0))
      <tr><td><a href="{{ route('financeiro.titulo', $t) }}">{{ $t->descricao }}</a><br><span class="sub" style="font-size:12.5px">{{ $t->contraparte ?? '—' }} · {{ $t->categoria?->nome ?? 'sem categoria' }}</span></td>
        <td style="min-width:170px">{{ $pagas }} de {{ $t->parcelas->count() }} parcelas
          <div class="progresso serie-despesa" role="img" aria-label="{{ $pagas }} de {{ $t->parcelas->count() }} pagas"><span style="width:{{ round($t->liquidado() * 100 / max(1, $t->valor_total_centavos)) }}%"></span></div></td>
        <td class="n">{{ brl($t->liquidado()) }}</td><td class="n">{{ brl($t->saldo()) }}</td>
        <td>{{ $prox ? $prox->vencimento->format('d/m/Y').' · '.brl($prox->saldo()) : '—' }}</td></tr>
    @empty
      <tr><td colspan="5" class="vazio">Nenhuma conta parcelada em aberto.</td></tr>
    @endforelse
    </tbody>
  </table></div>

@elseif ($aba === 'calendario')
  @php($semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'])
  @php($meses = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'])
  <div class="mes" style="margin-bottom:12px">
    <a href="{{ $url('calendario', ['mes' => $mes->subMonthNoOverflow()->format('Y-m')]) }}" aria-label="Mês anterior">‹</a>
    <strong>{{ $meses[(int) $mes->format('n')] }} de {{ $mes->format('Y') }}</strong>
    <a href="{{ $url('calendario', ['mes' => $mes->addMonthNoOverflow()->format('Y-m')]) }}" aria-label="Próximo mês">›</a>
  </div>
  <div class="calendario" role="table" aria-label="Vencimentos do mês">
    <div class="cal-cab" role="row">@foreach ($semana as $s)<span role="columnheader">{{ $s }}</span>@endforeach</div>
    <div class="cal-grade">
    @for ($d = $mes->startOfWeek(\Carbon\CarbonInterface::SUNDAY); $d->lte($mes->endOfMonth()->endOfWeek(\Carbon\CarbonInterface::SATURDAY)); $d = $d->addDay())
      @php($itens = $porDia->get($d->toDateString(), collect()))
      <div @class(['cal-dia', 'fora' => $d->month !== $mes->month, 'hoje' => $d->isToday()]) role="cell">
        <span class="cal-num">{{ $d->day }}</span>
        @foreach ($itens->take(3) as $l)
          <a href="{{ $l['url'] }}" class="cal-item @if ($l['atrasado']) atrasado @endif" title="{{ $l['descricao'] }} — {{ brl($l['saldo']) }}">{{ \Illuminate\Support\Str::limit($l['descricao'], 18) }}<b>{{ brl($l['saldo']) }}</b></a>
        @endforeach
        @if ($itens->count() > 3)<span class="cal-qtd">+{{ $itens->count() - 3 }} · {{ brl((int) $itens->sum('saldo')) }}</span>@endif
      </div>
    @endfor
    </div>
  </div>
  <p class="sub" style="font-size:12.5px;margin-top:8px">Total do mês: {{ brl((int) $porDia->flatten(1)->sum('saldo')) }}. Faturas de cartão aparecem uma vez, no vencimento da fatura.</p>

@elseif ($aba === 'cobrancas')
  @include('contas._cobrancas')
@elseif ($aba === 'renegociacoes')
  <div class="tabela"><table>
    <thead><tr><th>Data</th><th>Receita</th><th>Parcelas (de → para)</th><th class="n">Saldo renegociado</th><th>Motivo / condições</th><th>Por</th></tr></thead>
    <tbody>
    @forelse ($registros as $r)
      <tr><td>{{ $r->data->format('d/m/Y') }}</td><td><a href="{{ route('financeiro.titulo', [$r->titulo_id, 'aba' => 'cobranca']) }}">{{ $r->titulo->descricao }}</a><br><span class="sub" style="font-size:12.5px">{{ $r->titulo->contraparte }}</span></td>
        <td>@foreach ($r->detalhes['parcelas'] ?? [] as $d)<div style="font-size:13px">{{ $d['parcela'] }}: {{ $d['de'] }} → <strong>{{ $d['para'] }}</strong></div>@endforeach</td>
        <td class="n">{{ brl($r->detalhes['valor'] ?? 0) }}</td><td>{{ $r->mensagem }}@if ($r->resposta)<br><span class="sub" style="font-size:12.5px">{{ $r->resposta }}</span>@endif</td><td>{{ $r->criadoPor?->name }}</td></tr>
    @empty
      <tr><td colspan="6" class="vazio">Nenhuma renegociação registrada.</td></tr>
    @endforelse
    </tbody>
  </table></div>
@else
  <div class="tabela"><table>
    <thead><tr><th>Data</th><th>{{ $R ? 'Receita' : 'Conta paga' }}</th><th>{{ $T['quem'] }}</th><th>Conta bancária</th><th class="n">Valor</th><th>Por</th></tr></thead>
    <tbody>
    @forelse ($pagas as $p)
      @php($m = $p['mov'])
      <tr><td>{{ $m->data->format('d/m/Y') }}</td><td><a href="{{ route('financeiro.titulo', $m->parcela->titulo_id) }}">{{ $m->parcela->titulo->descricao }}</a></td>
        <td>{{ $m->parcela->titulo->contraparte ?? '—' }}</td><td>{{ $m->conta->nome }}</td><td class="n">{{ brl($m->valor_centavos) }}</td><td>{{ $m->criadoPor?->name ?? 'Sistema' }}</td></tr>
    @empty
      <tr><td colspan="6" class="vazio">Nenhum {{ $R ? 'recebimento' : 'pagamento' }} no período.</td></tr>
    @endforelse
    </tbody>
    @if ($pagas->count())<tfoot><tr><td colspan="4">{{ $pagas->count() }} {{ $R ? 'recebimento(s)' : 'pagamento(s)' }}</td><td class="n"><strong>{{ brl((int) $pagas->sum('valor')) }}</strong></td><td></td></tr></tfoot>@endif
  </table></div>
@endif
@endsection
