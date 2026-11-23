{{--
  Colunas agrupadas (até 3 séries lado a lado por período), com legenda e valores na dica.
  Uso: <x-grafico.colunas titulo="Entradas e saídas por semana" :periodos="['23/11', ...]"
         :series="[['nome' => 'Entradas', 'serie' => 'receita', 'valores' => [..]], ['nome' => 'Saídas', 'serie' => 'despesa', 'valores' => [..]]]" />
  Codificação dupla: cor + posição fixa no grupo + legenda + tabela equivalente na tela.
--}}
@props(['titulo', 'sub' => null, 'periodos' => [], 'series' => [], 'nota' => null])
@php
  $n = count($periodos); $k = max(1, count($series));
  $maxV = max(1, collect($series)->flatMap(fn ($s) => $s['valores'])->max() ?? 1);
  [$W, $H, $pe, $pd, $ps, $pi] = [720, 200, 64, 16, 12, 26];
  $larg = ($W - $pe - $pd) / max(1, $n);
  $barra = min(18, ($larg - 8) / $k);
  $y = fn ($v) => round($ps + ($maxV - $v) * ($H - $ps - $pi) / $maxV, 1);
  $passo = max(1, (int) ceil($n / 8));
@endphp
<figure {{ $attributes->merge(['class' => 'painel grafico']) }}>
  <figcaption><strong>{{ $titulo }}</strong>@if ($sub) <span class="sub">— {{ $sub }}</span>@endif</figcaption>
  <div class="legenda" aria-hidden="true">@foreach ($series as $s)<span class="serie-{{ $s['serie'] }}"><i></i>{{ $s['nome'] }}</span>@endforeach</div>
  <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $titulo }}: {{ collect($series)->map(fn ($s) => $s['nome'].' '.brl((int) array_sum($s['valores'])))->join(', ') }} no total">
    @foreach ([$maxV, $maxV / 2, 0] as $g)
      <line x1="{{ $pe }}" x2="{{ $W - $pd }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" class="grade-linha"/>
      <text x="{{ $pe - 8 }}" y="{{ $y($g) + 4 }}" text-anchor="end" class="eixo">{{ number_format($g / 100000, 1, ',', '.') }} mil</text>
    @endforeach
    @foreach ($periodos as $i => $per)
      @foreach ($series as $j => $s)
        @php($v = $s['valores'][$i] ?? 0)
        @if ($v > 0)
          <g class="coluna serie-{{ $s['serie'] }}">
            <rect x="{{ round($pe + $i * $larg + ($larg - $barra * $k - 2 * ($k - 1)) / 2 + $j * ($barra + 2), 1) }}" y="{{ $y($v) }}" width="{{ round($barra, 1) }}" height="{{ max(1, round($H - $pi - $y($v), 1)) }}" rx="2"/>
            <title>{{ $per }} · {{ $s['nome'] }}: {{ brl((int) $v) }}</title>
          </g>
        @endif
      @endforeach
      @if ($i % $passo === 0)<text x="{{ round($pe + $i * $larg + $larg / 2, 1) }}" y="{{ $H - 8 }}" text-anchor="middle" class="eixo">{{ $per }}</text>@endif
    @endforeach
  </svg>
  @if ($nota)<p class="sub" style="margin:6px 0 0;font-size:12.5px">{{ $nota }}</p>@endif
</figure>
