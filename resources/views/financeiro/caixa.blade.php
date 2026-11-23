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
  <div class="card"><div class="rot">Saldo registrado</div><div class="num">{{ brl($p['saldo_atual']) }}</div><div class="det">{{ $p['contas']->count() }} conta(s) · hoje</div></div>
  <div class="card"><div class="rot">Entradas previstas</div><div class="num">{{ brl($p['a_receber']) }}</div><div class="det">até {{ $p['ate']->format('d/m') }}</div></div>
  <div class="card"><div class="rot">Saídas previstas</div><div class="num">{{ brl($p['a_pagar']) }}</div><div class="det">inclui vencidas e faturas de cartão</div></div>
  <div class="card"><div class="rot">Saldo ao fim do período</div><div class="num">{{ brl($p['saldo_final']) }}</div><div class="det">projeção, não dinheiro de hoje</div></div>
</section>
<section class="cards" aria-label="Detalhes" style="margin-top:12px">
  <div class="card"><div class="rot">Se não entrar nada</div><div class="num" @if ($p['apos_pagar'] < 0) style="color:var(--bad)" @endif>{{ brl($p['apos_pagar']) }}</div><div class="det">saldo − saídas do período</div></div>
  <div class="card"><div class="rot">Menor saldo previsto</div><div class="num" @if ($p['minimo']['valor'] < 0) style="color:var(--bad)" @endif>{{ brl($p['minimo']['valor']) }}</div><div class="det">em {{ $p['minimo']['data']->format('d/m/Y') }}</div></div>
  <div class="card"><div class="rot">Recebimentos vencidos</div><div class="num">{{ brl($p['vencidos_receber']) }}</div>
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

@php($serie = $p['serie'])
@php($vals = array_column($serie, 'saldo'))
@php([$min, $max, $W, $H, $pe, $pd, $ps, $pi] = [min(0, min($vals)), max(1, max($vals)), 720, 220, 64, 16, 16, 28])
@php($x = fn ($i) => $pe + $i * ($W - $pe - $pd) / max(1, count($serie) - 1))
@php($y = fn ($v) => $ps + ($max - $v) * ($H - $ps - $pi) / max(1, $max - $min))
@php($passo = max(1, (int) ceil(count($serie) / 8)))
<figure class="painel grafico" style="margin-top:16px">
  <figcaption><strong>Saldo previsto dia a dia</strong> <span class="sub">— {{ $p['hoje']->format('d/m') }} a {{ $p['ate']->format('d/m') }}</span></figcaption>
  <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Saldo previsto: começa em {{ brl($p['saldo_atual']) }}, menor valor {{ brl($p['minimo']['valor']) }} em {{ $p['minimo']['data']->format('d/m') }}, termina em {{ brl($p['saldo_final']) }}">
    @foreach ([$max, ($max + $min) / 2, $min] as $g)
      <line x1="{{ $pe }}" x2="{{ $W - $pd }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" class="grade-linha"/>
      <text x="{{ $pe - 8 }}" y="{{ $y($g) + 4 }}" text-anchor="end" class="eixo">{{ number_format($g / 100000, 1, ',', '.') }} mil</text>
    @endforeach
    @if ($min < 0)<line x1="{{ $pe }}" x2="{{ $W - $pd }}" y1="{{ $y(0) }}" y2="{{ $y(0) }}" class="linha-zero"/>@endif
    <polyline class="serie" points="@foreach ($serie as $i => $f){{ round($x($i), 1) }},{{ round($y($f['saldo']), 1) }} @endforeach"/>
    @foreach ($serie as $i => $f)
      @if ($f['entradas'] || $f['saidas'] || $i === 0)
        <g class="ponto @if ($f['saldo'] < 0) neg @endif">
          <circle cx="{{ $x($i) }}" cy="{{ $y($f['saldo']) }}" r="12" class="alvo"/>
          <circle cx="{{ $x($i) }}" cy="{{ $y($f['saldo']) }}" r="4"/>
          <title>{{ $f['data']->format('d/m') }}: saldo {{ brl($f['saldo']) }}@if ($f['entradas']) · entradas {{ brl($f['entradas']) }}@endif @if ($f['saidas']) · saídas {{ brl($f['saidas']) }}@endif</title>
        </g>
      @endif
      @if ($i % $passo === 0)<text x="{{ $x($i) }}" y="{{ $H - 8 }}" text-anchor="middle" class="eixo">{{ $f['data']->format('d/m') }}</text>@endif
    @endforeach
    <text x="{{ $x(count($serie) - 1) }}" y="{{ $y(end($serie)['saldo']) - 10 }}" text-anchor="end" class="rotulo-direto">{{ brl(end($serie)['saldo']) }}</text>
  </svg>
  <p class="sub" style="margin:6px 0 0;font-size:12.5px">Cada ponto é um dia com entrada ou saída prevista. Pagamentos vencidos entram hoje. Passe o mouse para ver os valores.</p>
</figure>

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
