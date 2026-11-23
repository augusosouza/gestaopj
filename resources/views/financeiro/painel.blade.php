@extends('layouts.app')
@section('titulo', 'Painel financeiro')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@php($pct = fn ($a, $b) => $b > 0 ? min(100, round($a * 100 / $b)) : 0)
<x-cabecalho :trilha="['Financeiro' => null, 'Painel financeiro' => null]" titulo="Painel financeiro"
  :sub="$aba === 'fluxo' ? 'Projeção pelas parcelas em aberto, a partir do saldo de hoje.' : 'Competência '.$competencia->translatedFormat('F \d\e Y').'.'">
  <x-slot:acoes>
    @if ($aba !== 'fluxo')@include('partials.competencia')@endif
    @if ($opera)<a class="botao" href="{{ route('financeiro.novo') }}">+ Novo lançamento</a>@endif
  </x-slot:acoes>
</x-cabecalho>
<x-abas :itens="[
  ['Resumo', route('financeiro.painel', ['competencia' => $competencia->format('Y-m')]), $aba === 'resumo'],
  ['Fluxo de caixa', route('financeiro.painel', ['aba' => 'fluxo']), $aba === 'fluxo'],
  ['Despesas', route('financeiro.painel', ['aba' => 'despesas', 'competencia' => $competencia->format('Y-m')]), $aba === 'despesas'],
]" />

@if ($aba === 'resumo')
  <section class="cards" aria-label="Indicadores da competência">
    <div class="card"><div class="rot">Saldo nas contas</div><div class="num">{{ brl($p['saldo_contas']) }}</div><div class="det">hoje · <a href="{{ route('financeiro.contas') }}">contas</a></div></div>
    <div class="card"><div class="rot">Receitas previstas</div><div class="num">{{ brl($p['receitas_previstas']) }}</div>
      <div class="progresso" role="img" aria-label="{{ $pct($p['receitas_realizadas'], $p['receitas_previstas']) }}% recebido"><span style="width:{{ $pct($p['receitas_realizadas'], $p['receitas_previstas']) }}%"></span></div>
      <div class="det">recebido {{ brl($p['receitas_realizadas']) }} ({{ $pct($p['receitas_realizadas'], $p['receitas_previstas']) }}%)</div></div>
    <div class="card"><div class="rot">Despesas previstas</div><div class="num">{{ brl($p['despesas_previstas']) }}</div>
      <div class="progresso" role="img" aria-label="{{ $pct($p['despesas_realizadas'], $p['despesas_previstas']) }}% pago"><span style="width:{{ $pct($p['despesas_realizadas'], $p['despesas_previstas']) }}%"></span></div>
      <div class="det">pago {{ brl($p['despesas_realizadas']) }} ({{ $pct($p['despesas_realizadas'], $p['despesas_previstas']) }}%)</div></div>
    <div class="card"><div class="rot">Resultado previsto</div><div class="num">{{ brl($p['receitas_previstas'] - $p['despesas_previstas']) }}</div><div class="det">receitas − despesas da competência</div></div>
  </section>

  <h2>Ações necessárias</h2>
  @forelse ($acoes as $a)
    <div class="acao acao-{{ $a['tipo'] }}">
      <div><strong>{{ $a['titulo'] }}</strong><div class="sub" style="margin:2px 0 0">{{ $a['texto'] }}</div></div>
      <a class="botao {{ $a['tipo'] === 'bad' ? '' : 'sec' }}" href="{{ $a['url'] }}">{{ $a['acao'] }}</a>
    </div>
  @empty
    <p class="painel vazio">Nada pendente: extratos em dia, tudo conciliado e nenhum vencido.</p>
  @endforelse

@elseif ($aba === 'fluxo')
  @php($vals = array_column($fluxo, 'saldo'))
  @php([$min, $max, $W, $H, $pe, $pd, $ps, $pi] = [min(0, min($vals)), max(1, max($vals)), 720, 220, 64, 16, 16, 28])
  @php($x = fn ($i) => $pe + $i * ($W - $pe - $pd) / max(1, count($fluxo) - 1))
  @php($y = fn ($v) => $ps + ($max - $v) * ($H - $ps - $pi) / max(1, $max - $min))
  @php($negativo = collect($fluxo)->firstWhere('saldo', '<', 0))
  @if ($negativo)
    <div class="aviso bad"><strong>Atenção:</strong> o saldo projetado fica negativo na semana de {{ $negativo['inicio']->format('d/m') }} ({{ brl($negativo['saldo']) }}).</div>
  @endif
  <figure class="painel grafico">
    <figcaption><strong>Saldo projetado por semana</strong> <span class="sub">— próximas 12 semanas</span></figcaption>
    <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Saldo projetado: de {{ brl($fluxo[0]['saldo']) }} a {{ brl(end($fluxo)['saldo']) }}">
      @foreach ([$max, ($max + $min) / 2, $min] as $g)
        <line x1="{{ $pe }}" x2="{{ $W - $pd }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" class="grade-linha"/>
        <text x="{{ $pe - 8 }}" y="{{ $y($g) + 4 }}" text-anchor="end" class="eixo">{{ number_format($g / 100000, 0, ',', '.') }} mil</text>
      @endforeach
      @if ($min < 0)<line x1="{{ $pe }}" x2="{{ $W - $pd }}" y1="{{ $y(0) }}" y2="{{ $y(0) }}" class="linha-zero"/>@endif
      <polyline class="serie" points="@foreach ($fluxo as $i => $f){{ round($x($i), 1) }},{{ round($y($f['saldo']), 1) }} @endforeach"/>
      @foreach ($fluxo as $i => $f)
        <g class="ponto @if ($f['saldo'] < 0) neg @endif">
          <circle cx="{{ $x($i) }}" cy="{{ $y($f['saldo']) }}" r="14" class="alvo"/>
          <circle cx="{{ $x($i) }}" cy="{{ $y($f['saldo']) }}" r="4"/>
          <title>Semana de {{ $f['inicio']->format('d/m') }}: saldo {{ brl($f['saldo']) }} (entradas {{ brl($f['entradas']) }}, saídas {{ brl($f['saidas']) }})</title>
        </g>
        @if ($i % 2 === 0)<text x="{{ $x($i) }}" y="{{ $H - 8 }}" text-anchor="middle" class="eixo">{{ $f['inicio']->format('d/m') }}</text>@endif
      @endforeach
      <text x="{{ $x(count($fluxo) - 1) }}" y="{{ $y(end($fluxo)['saldo']) - 10 }}" text-anchor="end" class="rotulo-direto">{{ brl(end($fluxo)['saldo']) }}</text>
    </svg>
    <p class="sub" style="margin:6px 0 0;font-size:12.5px">Parcelas vencidas e não pagas entram na primeira semana. Passe o mouse nos pontos para ver os valores.</p>
  </figure>
  <div class="tabela" style="margin-top:12px"><table>
    <thead><tr><th>Semana</th><th class="n">Entradas previstas</th><th class="n">Saídas previstas</th><th class="n">Saldo projetado</th></tr></thead>
    <tbody>
    @foreach ($fluxo as $f)
      <tr><td>{{ $f['inicio']->format('d/m') }} a {{ $f['fim']->format('d/m') }}</td><td class="n">{{ $f['entradas'] ? brl($f['entradas']) : '—' }}</td>
        <td class="n">{{ $f['saidas'] ? brl($f['saidas']) : '—' }}</td><td class="n" @if ($f['saldo'] < 0) style="color:var(--bad)" @endif>{{ brl($f['saldo']) }}@if ($f['saldo'] < 0) (negativo)@endif</td></tr>
    @endforeach
    </tbody>
  </table></div>

@else
  @php($maxCat = max(1, $categorias->max('valor') ?? 1))
  @php($maxFav = max(1, $favorecidos->max('valor') ?? 1))
  <div class="duas">
    <figure class="painel grafico">
      <figcaption><strong>Despesas por categoria</strong> <span class="sub">— total {{ brl($categorias->sum('valor')) }}</span></figcaption>
      @forelse ($categorias as $c)
        <div class="barra-h" title="{{ $c['nome'] }}: {{ brl($c['valor']) }} previsto, {{ brl($c['pago']) }} pago, {{ $c['qtd'] }} lançamento(s)">
          <span class="barra-rotulo">{{ $c['nome'] }}</span>
          <span class="barra-trilho"><span class="barra-valor" style="width:{{ max(1, round($c['valor'] * 100 / $maxCat)) }}%"></span></span>
          <span class="barra-numero">{{ brl($c['valor']) }}</span>
        </div>
      @empty
        <p class="vazio">Nenhuma despesa nesta competência.</p>
      @endforelse
    </figure>
    <figure class="painel grafico">
      <figcaption><strong>Maiores destinos</strong> <span class="sub">— fornecedores e prestadores</span></figcaption>
      @forelse ($favorecidos as $f)
        <div class="barra-h" title="{{ $f['nome'] }}: {{ brl($f['valor']) }} em {{ $f['qtd'] }} lançamento(s)">
          <span class="barra-rotulo">{{ $f['nome'] }}</span>
          <span class="barra-trilho"><span class="barra-valor" style="width:{{ max(1, round($f['valor'] * 100 / $maxFav)) }}%"></span></span>
          <span class="barra-numero">{{ brl($f['valor']) }}</span>
        </div>
      @empty
        <p class="vazio">Nenhuma despesa nesta competência.</p>
      @endforelse
    </figure>
  </div>
  @if ($categorias->isNotEmpty())
  <div class="tabela" style="margin-top:12px"><table>
    <thead><tr><th>Categoria</th><th class="n">Previsto</th><th class="n">Pago</th><th class="n">Lançamentos</th><th class="n">% do total</th></tr></thead>
    <tbody>
    @foreach ($categorias as $c)
      <tr><td>{{ $c['nome'] }}</td>
        <td class="n">{{ brl($c['valor']) }}</td><td class="n">{{ brl($c['pago']) }}</td><td class="n">{{ $c['qtd'] }}</td>
        <td class="n">{{ number_format($c['valor'] * 100 / max(1, $categorias->sum('valor')), 1, ',', '.') }}%</td></tr>
    @endforeach
    </tbody>
  </table></div>
  @endif
@endif
@endsection
