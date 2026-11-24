@extends('layouts.app')
@section('titulo', 'Meu Caixa')
@section('conteudo')
@php($q = fn (array $extra) => route('financeiro.caixa', array_filter(array_merge(['dias' => $dias, 'contas' => $contaIds ?: null, 'vencidos' => $incluir ? 1 : null], $extra), fn ($v) => $v !== null)))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Meu Caixa' => null]" titulo="Meu Caixa"
  sub="O que posso fazer com o dinheiro de hoje sem comprometer os próximos vencimentos?">
  <x-slot:acoes>
    <nav class="filtros" aria-label="Horizonte" style="margin:0">
      @foreach (\App\Http\Controllers\CaixaController::HORIZONTES as $h)
        <a href="{{ $q(['dias' => $h]) }}" @class(['ativo' => $dias === $h])>{{ $h }} dias</a>
      @endforeach
    </nav>
    @include('partials.exportar', ['relatorio' => 'caixa'])
  </x-slot:acoes>
</x-cabecalho>

@if ($p['negativo'])
  <div class="aviso bad"><strong>Falta dinheiro em {{ $p['negativo']['data']->format('d/m/Y') }}:</strong>
    com os vencimentos previstos, o saldo fica em {{ brl(-$p['negativo']['deficit']) }}. Antecipe recebimentos, renegocie datas ou reforce a conta antes desse dia.</div>
@endif

<section class="decisao painel" aria-label="Livre para decidir">
  <div>
    <div class="rot">Livre para decidir hoje</div>
    <div class="num-grande">{{ brl($p['livre']) }}</div>
    <p class="sub" style="margin:6px 0 0">É o <strong>menor saldo previsto</strong> nos próximos {{ $dias }} dias ({{ $p['minimo']['data']->format('d/m') }}).
      Uma saída maior que isso hoje deixa algum dia negativo. {{ $p['livre'] === 0 ? 'Hoje não há folga.' : '' }}</p>
  </div>
  <a class="botao sec" href="#simular">Simular um gasto</a>
</section>

<section class="cards" aria-label="Posição">
  <div class="card serie-saldo"><div class="rot">Saldo registrado</div><div class="num">{{ brl($p['saldo_atual']) }}</div><div class="det">{{ $p['contas']->count() }} conta(s) · hoje</div></div>
  <div class="card serie-receita"><div class="rot">Entradas previstas</div><div class="num">{{ brl($p['a_receber']) }}</div><div class="det">até {{ $p['ate']->format('d/m') }} · <a href="#origens-receber">Entender entradas</a></div></div>
  <div class="card serie-despesa"><div class="rot">Saídas previstas</div><div class="num">{{ brl($p['a_pagar']) }}</div><div class="det">inclui vencidas e cartões · <a href="#origens-pagar">Entender saídas</a></div></div>
  <div class="card serie-projecao"><div class="rot">Saldo ao fim do período</div><div class="num">{{ brl($p['saldo_final']) }}</div><div class="det">projeção, não dinheiro de hoje</div></div>
</section>
<section class="cards" aria-label="Detalhes" style="margin-top:12px">
  <div class="card"><div class="rot">Se não entrar nada</div><div class="num" @if ($p['apos_pagar'] < 0) style="color:var(--bad)" @endif>{{ brl($p['apos_pagar']) }}</div><div class="det">saldo − saídas do período</div></div>
  <div class="card serie-projecao"><div class="rot">Menor saldo previsto</div><div class="num" @if ($p['minimo']['valor'] < 0) style="color:var(--bad)" @endif>{{ brl($p['minimo']['valor']) }}</div><div class="det">em {{ $p['minimo']['data']->format('d/m/Y') }}</div></div>
  <div class="card serie-pendencia"><div class="rot">Recebimentos vencidos</div><div class="num">{{ brl($p['vencidos_receber']) }}</div>
    <div class="det">{{ $incluir ? 'considerados hoje' : 'fora da projeção' }} · <a href="{{ route('financeiro.inadimplencia') }}">ver</a></div></div>
  <div class="card"><div class="rot">Cartões em aberto</div><div class="num">{{ brl($p['cartoes']) }}</div><div class="det">todas as faturas · <a href="{{ route('cartoes.index') }}">cartões</a></div></div>
</section>

<form method="get" action="{{ route('financeiro.caixa') }}" class="filtros-barra" style="margin-top:16px">
  <input type="hidden" name="dias" value="{{ $dias }}">
  <fieldset class="contas-filtro"><legend class="sub" style="margin:0 0 4px;font-size:13px">Contas no saldo de partida</legend>
    @foreach ($todasContas as $c)
      <label class="marcar"><input type="checkbox" name="contas[]" value="{{ $c->id }}" @checked(! $contaIds || in_array($c->id, $contaIds, true))> {{ $c->nome }}</label>
    @endforeach
  </fieldset>
  <label class="marcar"><input type="checkbox" name="vencidos" value="1" @checked($incluir)> Contar recebimentos vencidos como se entrassem hoje</label>
  <button class="sec">Atualizar</button>
</form>

<x-grafico.linha titulo="Saldo previsto dia a dia" :sub="$p['hoje']->format('d/m').' a '.$p['ate']->format('d/m')" serie="projecao" :tracejado="true" style="margin-top:16px"
  :pontos="collect($p['serie'])->map(fn ($f, $i) => ['rotulo' => $f['data']->format('d/m'), 'valor' => $f['saldo'], 'marcar' => $i === 0 || $f['entradas'] || $f['saidas'],
    'dica' => $f['data']->format('d/m').': saldo '.brl($f['saldo']).($f['entradas'] ? ' · entradas '.brl($f['entradas']) : '').($f['saidas'] ? ' · saídas '.brl($f['saidas']) : '')])->all()"
  destaque-inicial="Hoje" :rotulo-final="brl(end($p['serie'])['saldo'])"
  :descricao="'Saldo previsto: começa em '.brl($p['saldo_atual']).', menor valor '.brl($p['minimo']['valor']).' em '.$p['minimo']['data']->format('d/m').', termina em '.brl($p['saldo_final'])"
  nota="Ponto azul = saldo registrado hoje; linha tracejada = projeção. Cada ponto é um dia com entrada ou saída prevista; pagamentos vencidos entram hoje." />

<div class="duas" style="margin-top:16px">
  <form method="get" action="{{ route('financeiro.caixa') }}#simular" class="painel" id="simular">
    <strong>Simular uma decisão</strong>
    <p class="sub" style="font-size:13px;margin:0 0 12px">Teste um gasto ou uma entrada antes de assumir. <strong>Nada é gravado.</strong></p>
    <input type="hidden" name="dias" value="{{ $dias }}">
    @foreach ($contaIds as $id)<input type="hidden" name="contas[]" value="{{ $id }}">@endforeach
    @if ($incluir)<input type="hidden" name="vencidos" value="1">@endif
    <div class="grade">
      <div class="campo"><label for="simular_tipo">Tipo</label><select id="simular_tipo" name="simular_tipo">
        <option value="pagar" @selected(($p['simulacao']['tipo'] ?? 'pagar') === 'pagar')>Saída (gasto, compra)</option>
        <option value="receber" @selected(($p['simulacao']['tipo'] ?? '') === 'receber')>Entrada</option></select></div>
      <div class="campo"><label for="simular_valor">Valor (R$)</label><input id="simular_valor" name="simular_valor" inputmode="decimal" placeholder="2.400,00"
        value="{{ $p['simulacao'] ? number_format($p['simulacao']['valor'] / 100, 2, ',', '.') : '' }}" required></div>
      <div class="campo"><label for="simular_data">Data</label><input id="simular_data" type="date" name="simular_data" value="{{ $p['simulacao']['data'] ?? now()->toDateString() }}" required></div>
    </div>
    <button>Simular</button>
    @if ($p['simulacao'] && $semSimular)
      <div @class(['aviso', 'bad' => $p['negativo'], 'ok' => ! $p['negativo']]) style="margin:14px 0 0">
        @if ($p['negativo'])
          <strong>Não cabe agora.</strong> Com essa {{ $p['simulacao']['tipo'] === 'receber' ? 'entrada' : 'saída' }}, o saldo fica negativo em {{ $p['negativo']['data']->format('d/m/Y') }} (faltam {{ brl($p['negativo']['deficit']) }}).
        @else
          <strong>Cabe.</strong> O menor saldo previsto passa de {{ brl($semSimular['minimo']['valor']) }} para {{ brl($p['minimo']['valor']) }} ({{ $p['minimo']['data']->format('d/m') }}).
        @endif
        <div style="margin-top:6px"><a href="{{ $q([]) }}">Limpar simulação</a></div>
      </div>
    @endif
  </form>

  <div class="painel">
    <strong>Saldo confirmado pelo banco</strong>
    @forelse ($confirmados as $cf)
      <div class="linha-kv"><span>{{ $cf['conta']->nome }} <span class="sub">· extrato de {{ $cf['em']->format('d/m/Y') }}</span></span><span class="n">{{ brl($cf['valor']) }}</span></div>
      @if ($cf['diferenca'] !== 0)
        <p class="sub" style="font-size:12.5px;margin:0 0 8px"><span class="tag warn">Diferença de {{ brl($cf['diferenca']) }}</span> entre o registrado no Augi e o banco nessa data. <a href="{{ route('financeiro.conciliacao', $cf['conta']) }}">Conciliar</a></p>
      @endif
    @empty
      <p class="sub" style="font-size:13px;margin:0">Nenhum extrato OFX com saldo informado ainda. Ao importar um OFX, o saldo do banco aparece aqui para comparar com o registrado.</p>
    @endforelse
    @if ($sugestoes->isNotEmpty())
      <strong style="display:block;margin:16px 0 8px">Sugestões</strong>
      @foreach ($sugestoes as $s)
        <div class="acao acao-{{ $s['nivel'] }}" style="padding:10px 12px"><div><strong style="font-size:13.5px">{{ $s['titulo'] }}</strong><div class="sub" style="margin:2px 0 0;font-size:12.5px">{{ $s['motivo'] }}</div></div>
          <a class="botao sec" href="{{ $s['url'] }}">{{ $s['acao'] }}</a></div>
      @endforeach
    @endif
  </div>
</div>

<section class="ficha-secoes" aria-labelledby="origem-indicadores">
  <h2 id="origem-indicadores">Origem dos valores previstos</h2>
  <p class="sub">{{ $p['hoje']->format('d/m/Y') }} a {{ $p['ate']->format('d/m/Y') }}. Simulações ficam fora dos totais de entradas e saídas previstas.</p>
  @foreach (['receber' => ['Entradas', 'a_receber'], 'pagar' => ['Saídas', 'a_pagar']] as $tipo => [$rotulo, $total])
    <div class="painel" id="origens-{{ $tipo }}">
      <h3>{{ $rotulo }} · {{ brl($p[$total]) }}</h3>
      @forelse ($p['origens']->get($tipo, collect()) as $origem => $itens)
        <details class="filtros-avancados">
          <summary>{{ $origem }} · {{ brl($itens->sum('valor')) }}</summary>
          <ul class="check">
            @foreach ($itens as $item)
              <li class="barra"><a href="{{ $item['url'] }}">{{ $item['descricao'] }}</a><span>{{ brl($item['valor']) }}</span></li>
            @endforeach
          </ul>
        </details>
      @empty
        <p class="sub">Nenhum valor previsto no período.</p>
      @endforelse
    </div>
  @endforeach
</section>

<h2>Compromissos do período</h2>
@php($acumulado = $p['saldo_atual'])
<div class="tabela"><table>
  <thead><tr><th>Data</th><th>Descrição</th><th>Contraparte</th><th class="n">Entrada</th><th class="n">Saída</th><th class="n">Saldo após</th></tr></thead>
  <tbody>
  @forelse ($p['eventos'] as $e)
    @php($acumulado += $e['tipo'] === 'receber' ? $e['valor'] : -$e['valor'])
    <tr>
      <td>{{ $e['vencimento']->format('d/m/Y') }}@if ($e['atrasado'])<br><span class="tag bad">vencido</span>@endif</td>
      <td>@if ($e['simulado'] ?? false)<em>{{ $e['descricao'] }}</em>@else<a href="{{ $e['url'] }}">{{ $e['descricao'] }}</a>@endif</td>
      <td>{{ $e['contraparte'] ?? '—' }}</td>
      <td class="n">{{ $e['tipo'] === 'receber' ? brl($e['valor']) : '' }}</td>
      <td class="n">{{ $e['tipo'] === 'pagar' ? brl($e['valor']) : '' }}</td>
      <td class="n" @if ($acumulado < 0) style="color:var(--bad)" @endif>{{ brl($acumulado) }}</td>
    </tr>
  @empty
    <tr><td colspan="6" class="vazio">Nenhum compromisso nos próximos {{ $dias }} dias.</td></tr>
  @endforelse
  </tbody>
</table></div>
<p class="sub" style="font-size:12.5px;margin-top:8px">Projeção simplificada pelas datas de vencimento: considera o que está registrado no Augi. Compras no cartão aparecem uma vez, no vencimento da fatura.</p>
@endsection
