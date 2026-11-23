{{--
  Gráfico de linha (uma série) em SVG gerado no servidor: funciona sem JavaScript, imprime e tem texto alternativo.
  Uso: <x-grafico.linha titulo="Saldo previsto" :pontos="[['rotulo' => '23/11', 'valor' => 950000, 'dica' => '...', 'marcar' => true], ...]"
         serie="projecao" :tracejado="true" :rotulo-final="brl(...)" nota="..." />
  serie: receita | despesa | saldo | pendencia | projecao | comparativo (cores aprovadas; ver docs/graficos.md).
  Valores abaixo de zero: ponto em vermelho e linha de zero tracejada (vermelho = alerta, nunca uma série).
--}}
@props(['titulo', 'sub' => null, 'pontos' => [], 'serie' => 'saldo', 'tracejado' => false, 'rotuloFinal' => null, 'nota' => null, 'destaqueInicial' => null, 'rotulos' => 8, 'descricao' => null])
@php
  $n = count($pontos);
  $vals = array_column($pontos, 'valor');
  [$min, $max] = [min(0, $vals ? min($vals) : 0), max(1, $vals ? max($vals) : 1)];
  [$W, $H, $pe, $pd, $ps, $pi] = [720, 220, 64, 16, 16, 28];
  $x = fn ($i) => round($pe + $i * ($W - $pe - $pd) / max(1, $n - 1), 1);
  $y = fn ($v) => round($ps + ($max - $v) * ($H - $ps - $pi) / max(1, $max - $min), 1);
  $passo = max(1, (int) ceil($n / max(1, $rotulos)));
  // Rótulo direto acima do ponto; perto do topo, vai para baixo (não colide com a linha nem com o título).
  $ry = fn ($v) => $y($v) < $ps + 22 ? $y($v) + 20 : $y($v) - 10;
  $mil = fn ($v) => number_format($v / 100000, abs($max - $min) < 1000000 ? 1 : 0, ',', '.').' mil';
@endphp
<figure {{ $attributes->merge(['class' => 'painel grafico serie-'.$serie]) }}>
  <figcaption><strong>{{ $titulo }}</strong>@if ($sub) <span class="sub">— {{ $sub }}</span>@endif</figcaption>
  @if ($n)
  <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $descricao ?? $titulo }}">
    @foreach ([$max, ($max + $min) / 2, $min] as $g)
      <line x1="{{ $pe }}" x2="{{ $W - $pd }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" class="grade-linha"/>
      <text x="{{ $pe - 8 }}" y="{{ $y($g) + 4 }}" text-anchor="end" class="eixo">{{ $mil($g) }}</text>
    @endforeach
    @if ($min < 0)<line x1="{{ $pe }}" x2="{{ $W - $pd }}" y1="{{ $y(0) }}" y2="{{ $y(0) }}" class="linha-zero"/>@endif
    <polyline @class(['serie', 'tracejada' => $tracejado]) points="@foreach ($pontos as $i => $p){{ $x($i) }},{{ $y($p['valor']) }} @endforeach"/>
    @foreach ($pontos as $i => $p)
      @if ($p['marcar'] ?? true)
        <g @class(['ponto', 'neg' => $p['valor'] < 0, 'inicio' => $i === 0 && $destaqueInicial])>
          <circle cx="{{ $x($i) }}" cy="{{ $y($p['valor']) }}" r="12" class="alvo"/>
          <circle cx="{{ $x($i) }}" cy="{{ $y($p['valor']) }}" r="{{ $i === 0 && $destaqueInicial ? 5 : 4 }}"/>
          <title>{{ $p['dica'] ?? $p['rotulo'].': '.brl($p['valor']) }}</title>
        </g>
      @endif
      @if ($i % $passo === 0)<text x="{{ $x($i) }}" y="{{ $H - 8 }}" text-anchor="middle" class="eixo">{{ $p['rotulo'] }}</text>@endif
    @endforeach
    @if ($destaqueInicial)<text x="{{ $x(0) + 8 }}" y="{{ $ry($pontos[0]['valor']) }}" class="rotulo-direto">{{ $destaqueInicial }}</text>@endif
    @if ($rotuloFinal)<text x="{{ $x($n - 1) }}" y="{{ $ry(end($pontos)['valor']) }}" text-anchor="end" class="rotulo-direto">{{ $rotuloFinal }}</text>@endif
  </svg>
  @else
    <p class="vazio">Sem dados no período.</p>
  @endif
  @if ($nota)<p class="sub" style="margin:6px 0 0;font-size:12.5px">{{ $nota }}</p>@endif
  {{ $slot }}
</figure>
