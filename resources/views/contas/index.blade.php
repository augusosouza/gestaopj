@extends('layouts.app')
@php
  $R = $tipo === 'receber';
  $T = $R ? ['titulo' => 'Contas a receber', 'serie' => 'receita', 'verbo' => 'Receber', 'pago' => 'Recebido', 'quem' => 'Cliente', 'nova' => 'Nova receita']
    : ['titulo' => 'Contas a pagar', 'serie' => 'despesa', 'verbo' => 'Pagar', 'pago' => 'Pago', 'quem' => 'Fornecedor', 'nova' => 'Nova conta'];
  $opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro);
  $contasAtivas = $contas->where('ativa', true);
  $q = request()->except(['aba', 'mes', 'page']);
  $rota = $R ? 'contas_receber.index' : 'contas_pagar.index';
  $url = fn ($a, $extra = []) => route($rota, array_merge($q, ['aba' => $a], $extra));
@endphp
@section('titulo', $T['titulo'])
@section('conteudo')
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), $T['titulo'] => null]" :titulo="$T['titulo']"
  :sub="$R ? 'Recebimentos previstos, atrasos, cobranças e renegociações com clientes.' : 'Todos os compromissos de pagamento: contas, parcelas de compras e faturas de cartão.'">
  <x-slot:acoes>
    @include('partials.exportar', ['recolher' => true, 'relatorio' => $R ? 'inadimplencia' : 'lancamentos', 'extra' => $R ? [] : ['tipo' => 'pagar', 'periodo' => 'todos', 'situacao' => 'aberto']])
    @if ($opera)<a class="botao" href="{{ route('financeiro.novo', ['tipo' => $tipo]) }}">@include('partials.icone', ['nome' => 'mais']) {{ $T['nova'] }}</a>@endif
  </x-slot:acoes>
</x-cabecalho>

@if ($R)
<section class="cards contas-indicadores" aria-label="Indicadores">
  <div class="card serie-comparativo"><div class="rot">Previsto no mês</div><div class="num">{{ brl($ind['previsto_mes']) }}</div><div class="det">recebido + a receber com vencimento no mês</div></div>
  <div class="card serie-receita"><div class="rot">Recebido no mês</div><div class="num">{{ brl($ind['pago_mes']) }}</div><div class="det"><a href="{{ $url('pagas') }}">ver recebimentos</a></div></div>
  <div class="card serie-receita"><div class="rot">Em aberto</div><div class="num">{{ brl($ind['aberto']) }}</div><div class="det">{{ $ind['qtd'] }} parcela(s) · 7 dias: {{ brl($ind['sete']) }}</div></div>
  <div class="card serie-pendencia"><div class="rot">Atrasado</div><div class="num" @if ($ind['atrasado']) style="color:var(--bad)" @endif>{{ brl($ind['atrasado']) }}</div>
    <div class="det">{{ $ind['inadimplentes'] }} cliente(s) · <a href="{{ $url('vencidas') }}">cobrar</a></div></div>
</section>
@if ($ind['renegociado'])<p class="sub" style="font-size:13px;margin:8px 0 0">Renegociado neste mês: {{ brl($ind['renegociado']) }} · <a href="{{ $url('renegociacoes') }}">ver acordos</a></p>@endif
@else
<section class="cards cards-6 contas-indicadores" aria-label="Indicadores">
  <div class="card serie-despesa"><div class="rot">Total em aberto</div><div class="num">{{ brl($ind['aberto']) }}</div><div class="det">{{ $ind['qtd'] }} compromisso(s)</div></div>
  <div class="card serie-pendencia"><div class="rot">Vencido</div><div class="num" @if ($ind['atrasado']) style="color:var(--bad)" @endif>{{ brl($ind['atrasado']) }}</div>
    <div class="det">{{ $ind['qtd_atrasado'] }} conta(s) · <a href="{{ $url('vencidas') }}">ver</a></div></div>
  <div class="card serie-pendencia"><div class="rot">Vence hoje</div><div class="num">{{ brl($ind['hoje']) }}</div><div class="det">{{ $hoje->format('d/m') }}</div></div>
  <div class="card serie-despesa"><div class="rot">Próximos 7 dias</div><div class="num">{{ brl($ind['sete']) }}</div><div class="det"><a href="{{ $url('aberto', ['proximos' => 7]) }}">ver</a></div></div>
  <div class="card serie-despesa"><div class="rot">Próximos 30 dias</div><div class="num">{{ brl($ind['trinta']) }}</div><div class="det"><a href="{{ $url('aberto', ['proximos' => 30]) }}">ver</a></div></div>
  <div class="card serie-saldo"><div class="rot">Pago neste mês</div><div class="num">{{ brl($ind['pago_mes']) }}</div><div class="det"><a href="{{ $url('pagas') }}">ver pagamentos</a></div></div>
</section>
@endif
<x-abas :itens="collect($abas)->map(fn ($r, $k) => [$r, $url($k), $aba === $k])->values()->all()" />

@if (in_array($aba, ['aberto', 'vencidas', 'parcelamentos', 'pagas'], true))
@php
  $filtrosAtivos = array_filter([
    'fornecedor' => $f['fornecedor'] ?? null,
    'de' => isset($f['de']) ? 'De '.$f['de']->format('d/m/Y') : null,
    'ate' => isset($f['ate']) && ! isset($f['proximos']) ? 'Até '.$f['ate']->format('d/m/Y') : null,
    'proximos' => isset($f['proximos']) ? 'Próximos '.$f['proximos'].' dias' : null,
    'situacao' => $aba !== 'vencidas' ? (['vencido' => 'Vencidas', 'a_vencer' => 'A vencer', 'parcial' => 'Pagas em parte'][$f['situacao'] ?? ''] ?? null) : null,
    'categoria' => isset($f['categoria']) ? 'Categoria: '.($categorias->find($f['categoria'])?->nome ?? 'não disponível') : null,
    'competencia' => isset($f['competencia']) ? 'Competência: '.$f['competencia'] : null,
    'centro' => isset($f['centro']) ? 'Centro: '.($centros->find($f['centro'])?->nome ?? 'não disponível') : null,
    'conta' => isset($f['conta']) ? 'Conta: '.($contas->find($f['conta'])?->nome ?? 'não disponível') : null,
    'min' => isset($f['min']) ? 'Valor mínimo: '.brl($f['min']) : null,
    'max' => isset($f['max']) ? 'Valor máximo: '.brl($f['max']) : null,
  ], fn ($valor) => $valor !== null);
@endphp
<section class="painel contas-filtros" aria-label="Consultar contas">
<form method="get" class="filtros-pagar" aria-label="Filtros">
  <input type="hidden" name="aba" value="{{ $aba }}">
  <div class="campo"><label for="f-forn">{{ $T['quem'] }} ou descrição</label><input id="f-forn" name="fornecedor" value="{{ $f['fornecedor'] ?? '' }}"></div>
  <div class="campo"><label for="f-de">{{ $aba === 'pagas' ? $T['pago'].' de' : 'Vence de' }}</label><input id="f-de" type="date" name="de" value="{{ ($f['de'] ?? null)?->toDateString() ?? '' }}"></div>
  <div class="campo"><label for="f-ate">Até</label><input id="f-ate" type="date" name="ate" value="{{ is_string(request('ate')) ? request('ate') : '' }}"></div>
  @if ($aba === 'aberto')
    <div class="campo"><label for="f-sit">Situação</label><select id="f-sit" name="situacao"><option value="">Todas</option>
      @foreach (['vencido' => 'Vencidas', 'a_vencer' => 'A vencer', 'parcial' => 'Pagas em parte'] as $k => $v)<option value="{{ $k }}" @selected(($f['situacao'] ?? null) === $k)>{{ $v }}</option>@endforeach</select></div>
  @endif
  <details class="filtros-avancados" @if (array_intersect_key($f, array_flip(['categoria', 'competencia', 'centro', 'conta', 'min', 'max']))) open @endif>
    <summary>Filtros avançados</summary>
    <div class="grade">
      <div class="campo"><label for="f-cat">Categoria</label><select id="f-cat" name="categoria"><option value="">Todas</option>
        @foreach ($categorias as $c)<option value="{{ $c->id }}" @selected(($f['categoria'] ?? null) === $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
      <div class="campo"><label for="f-comp">Competência</label><input id="f-comp" type="month" name="competencia" value="{{ $f['competencia'] ?? '' }}"></div>
      <div class="campo"><label for="f-centro">Centro de custo</label><select id="f-centro" name="centro"><option value="">Todos</option>
        @foreach ($centros as $centro)<option value="{{ $centro->id }}" @selected(($f['centro'] ?? null) === $centro->id)>{{ $centro->nome }}</option>@endforeach</select></div>
      <div class="campo"><label for="f-conta">Conta</label><select id="f-conta" name="conta"><option value="">Todas</option>
        @foreach ($contas as $c)<option value="{{ $c->id }}" @selected(($f['conta'] ?? null) === $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
      <div class="campo"><label for="f-min">Valor de</label><input id="f-min" name="min" inputmode="decimal" value="{{ is_string(request('min')) ? request('min') : '' }}" placeholder="0,00"></div>
      <div class="campo"><label for="f-max">Valor até</label><input id="f-max" name="max" inputmode="decimal" value="{{ is_string(request('max')) ? request('max') : '' }}" placeholder="0,00"></div>
    </div>
  </details>
  <div class="acoes">
    <button>Filtrar</button>
    @if ($filtrosAtivos)<a href="{{ route($rota, ['aba' => $aba]) }}" class="botao terciario">Limpar filtros</a>@endif
  </div>
</form>
@if ($filtrosAtivos)
  <ul class="contas-filtrosativos" aria-label="Filtros ativos">
    @foreach ($filtrosAtivos as $campo => $rotulo)
      <li><a href="{{ $url($aba, [$campo => null]) }}" aria-label="Remover filtro {{ $rotulo }}">{{ $rotulo }} <span aria-hidden="true">×</span></a></li>
    @endforeach
  </ul>
@endif
</section>
@if (! $R && in_array($aba, ['aberto', 'vencidas'], true) && (isset($f['competencia']) || isset($f['centro'])))
  <p class="sub">Faturas agrupadas do cartão ficam fora dos filtros por competência e centro de custo. Consulte as compras na fatura.</p>
@endif
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
  <div class="tabela contas-listagem"><table role="table">
    <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Vencimento</th><th scope="col" role="columnheader">{{ $R ? 'Receita' : 'Conta' }}</th><th scope="col" role="columnheader">{{ $T['quem'] }}</th><th scope="col" role="columnheader">Categoria</th><th scope="col" role="columnheader" class="n">Saldo</th><th scope="col" role="columnheader">Situação</th><th scope="col" role="columnheader"></th></tr></thead>
    <tbody role="rowgroup">
    @forelse ($linhas as $l)
      <tr role="row">
        <td role="cell" data-label="Vencimento">{{ $l['vencimento']->format('d/m/Y') }}</td>
        <td role="cell" class="contas-descricao"><a href="{{ $l['url'] }}">{{ $l['descricao'] }}</a>@if ($l['n'])<br><span class="sub" style="font-size:12.5px">{{ $l['tipo'] === 'fatura' ? $l['n'] : 'Parcela '.$l['n'] }}@if ($l['forma']) · {{ $l['forma'] }}@endif</span>@endif</td>
        <td role="cell" data-label="{{ $T['quem'] }}">{{ $l['fornecedor'] ?? '—' }}</td><td role="cell" data-label="Categoria">{{ $l['categoria'] ?? '—' }}</td>
        <td role="cell" class="n" data-label="Saldo">{{ brl($l['saldo']) }}@if ($l['parcial'])<br><span class="sub" style="font-size:12px">de {{ brl($l['valor']) }}</span>@endif</td>
        <td role="cell" data-label="Situação">@if ($l['atrasado'])<span class="tag bad">{{ $R ? 'Atrasado' : 'Vencida' }}</span><br><span class="sub" style="font-size:12px">{{ (int) $l['vencimento']->diffInDays($hoje) }} dia(s)</span>@elseif ($l['parcial'])<span class="tag info">Paga em parte</span>@else<span class="tag warn">A vencer</span>@endif</td>
        <td role="cell" class="contas-acoes">@if ($opera)
          @if ($l['tipo'] === 'fatura')<a class="botao sec" href="{{ $l['url'] }}#pagar">Pagar fatura</a>
          @else
            @if ($R && $l['atrasado'])<a class="botao sec" href="{{ $l['url'] }}?aba=cobranca" style="margin-bottom:4px">Cobrar</a>@endif
            @if ($contasAtivas->isEmpty())
              <a class="botao sec" href="{{ route('financeiro.contas') }}">Cadastrar conta bancária</a>
            @else
              @php($reabrirBaixa = $errors->any() && is_string(old('parcela_rapida')) && old('parcela_rapida') === (string) $l['parcela']->id)
              @php($entradaBaixa = fn ($campo, $padrao = '') => $reabrirBaixa && is_string(old($campo)) ? old($campo) : $padrao)
              <details class="pagar-rapido" data-baixa-rapida @if ($reabrirBaixa) open data-reabrir-baixa @endif>
                <summary class="botao sec">{{ $T['verbo'] }}</summary>
                <form method="post" action="{{ route('financeiro.baixar', $l['parcela']) }}" data-evitar-reenvio>
                  @csrf
                  <input type="hidden" name="parcela_rapida" value="{{ $l['parcela']->id }}">
                  <input type="hidden" name="chave" value="{{ $entradaBaixa('chave', $chave.'-'.$l['parcela']->id) }}">
                  <div class="baixa-resumo">
                    <strong>{{ $l['descricao'] }}</strong>
                    @if ($l['fornecedor'])<span>{{ $l['fornecedor'] }}</span>@endif
                    <span>Saldo em aberto <strong>{{ brl($l['saldo']) }}</strong></span>
                  </div>
                  <label>Conta
                    <select name="conta_id" required>
                      @foreach ($contasAtivas as $c)
                        <option value="{{ $c->id }}" @selected((string) $entradaBaixa('conta_id', $l['conta_id']) === (string) $c->id)>{{ $c->nome }}</option>
                      @endforeach
                    </select>
                    @if ($reabrirBaixa)<small class="erro">{{ $errors->first('conta_id') }}</small>@endif
                  </label>
                  <label>Data
                    <input type="date" name="data" value="{{ $entradaBaixa('data', $hoje->toDateString()) }}" max="{{ $hoje->toDateString() }}" required>
                    @if ($reabrirBaixa)<small class="erro">{{ $errors->first('data') }}</small>@endif
                  </label>
                  <label>Valor {{ $R ? 'recebido' : 'pago' }}
                    <input name="valor" inputmode="decimal" value="{{ $entradaBaixa('valor', valor_campo($l['saldo'])) }}" required>
                    @if ($reabrirBaixa)<small class="erro">{{ $errors->first('valor') }}</small>@endif
                  </label>
                  <label>Observação <textarea name="observacao" rows="2" maxlength="500">{{ $entradaBaixa('observacao') }}</textarea></label>
                  <p class="sub baixa-ajuda">Para uma baixa parcial, informe um valor menor que o saldo.</p>
                  <div class="acoes">
                    <button type="submit">Registrar {{ $R ? 'recebimento' : 'pagamento' }}</button>
                    <a href="{{ $l['url'] }}">Abrir lançamento</a>
                  </div>
                </form>
              </details>
            @endif
          @endif
        @endif</td>
      </tr>
    @empty
      <tr role="row"><td colspan="7" class="vazio">{{ $aba === 'vencidas' ? 'Nada vencido. 🎉' : 'Nada em aberto' }}{{ count(array_diff_key($f, ['situacao' => 1])) ? ' com esses filtros' : '' }}</td></tr>
    @endforelse
    </tbody>
    @if ($linhas->total())<tfoot><tr><td colspan="4">{{ $linhas->total() }} compromisso(s) no filtro</td><td class="n"><strong>{{ brl($totalLinhas) }}</strong></td><td colspan="2"></td></tr></tfoot>@endif
  </table></div>
  {{ $linhas->links() }}
  @if ($opera && $contasAtivas->isNotEmpty())
    <dialog class="painel-baixa" data-painel-baixa aria-labelledby="titulo-painel-baixa">
      <header class="painel-baixa-topo">
        <h2 id="titulo-painel-baixa">{{ $R ? 'Registrar recebimento' : 'Registrar pagamento' }}</h2>
        <button type="button" class="botao sec" data-fechar-baixa>Fechar</button>
      </header>
      <div data-conteudo-baixa></div>
    </dialog>
  @endif

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
  {{ $parcelados->links() }}

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
    @forelse ($pagas as $m)
      <tr><td>{{ $m->data->format('d/m/Y') }}</td><td><a href="{{ route('financeiro.titulo', $m->parcela->titulo_id) }}">{{ $m->parcela->titulo->descricao }}</a></td>
        <td>{{ $m->parcela->titulo->contraparte ?? '—' }}</td><td>{{ $m->conta->nome }}</td><td class="n">{{ brl($m->valor_centavos) }}</td><td>{{ $m->criadoPor?->name ?? 'Sistema' }}</td></tr>
    @empty
      <tr><td colspan="6" class="vazio">Nenhum {{ $R ? 'recebimento' : 'pagamento' }} no período.</td></tr>
    @endforelse
    </tbody>
    @if ($pagas->total())<tfoot><tr><td colspan="4">{{ $pagas->total() }} {{ $R ? 'recebimento(s)' : 'pagamento(s)' }} no filtro</td><td class="n"><strong>{{ brl($totalPagas) }}</strong></td><td></td></tr></tfoot>@endif
  </table></div>
  {{ $pagas->links() }}
@endif
@endsection
