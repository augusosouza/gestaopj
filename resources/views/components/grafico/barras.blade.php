{{--
  Barras horizontais de uma série, com o valor escrito ao lado (rótulo direto; sem legenda).
  Uso: <x-grafico.barras titulo="Despesas por categoria" serie="despesa" :itens="[['rotulo' => 'Aluguel', 'valor' => 350000, 'dica' => '...'], ...]" />
--}}
@props(['titulo', 'sub' => null, 'itens' => [], 'serie' => 'despesa', 'vazio' => 'Sem dados no período.'])
@php($maxV = max(1, collect($itens)->max('valor') ?? 1))
<figure {{ $attributes->merge(['class' => 'painel grafico serie-'.$serie]) }}>
  <figcaption><strong>{{ $titulo }}</strong>@if ($sub) <span class="sub">— {{ $sub }}</span>@endif</figcaption>
  @forelse ($itens as $i)
    <div class="barra-h" title="{{ $i['dica'] ?? $i['rotulo'].': '.brl($i['valor']) }}">
      <span class="barra-rotulo">{{ $i['rotulo'] }}</span>
      <span class="barra-trilho"><span class="barra-valor" style="width:{{ max(1, round($i['valor'] * 100 / $maxV)) }}%"></span></span>
      <span class="barra-numero">{{ brl($i['valor']) }}</span>
    </div>
  @empty
    <p class="vazio">{{ $vazio }}</p>
  @endforelse
  {{ $slot }}
</figure>
