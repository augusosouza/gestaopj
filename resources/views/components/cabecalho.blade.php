{{--
  Cabeçalho padrão de página: trilha (breadcrumb), título, descrição e ações.
  Uso: <x-cabecalho :trilha="['Financeiro' => route(...), 'Lançamentos' => null]" titulo="…" sub="…"> <x-slot:acoes>…</x-slot:acoes> </x-cabecalho>
--}}
@props(['trilha' => [], 'titulo', 'sub' => null])
<header class="cabecalho">
  @if ($trilha)
    <nav class="trilha" aria-label="Você está em">
      @foreach ($trilha as $rotulo => $url)
        @if ($url && ! $loop->last)<a href="{{ $url }}">{{ $rotulo }}</a><span aria-hidden="true">/</span>
        @else<span @if ($loop->last) aria-current="page" @endif>{{ $rotulo }}</span>@if (! $loop->last)<span aria-hidden="true">/</span>@endif @endif
      @endforeach
    </nav>
  @endif
  <div class="cabecalho-linha">
    <div class="cabecalho-texto">
      <h1>{{ $titulo }}</h1>
      @if ($sub)<p class="sub">{{ $sub }}</p>@endif
      {{ $slot }}
    </div>
    @isset($acoes)<div class="acoes cabecalho-acoes">{{ $acoes }}</div>@endisset
  </div>
</header>
