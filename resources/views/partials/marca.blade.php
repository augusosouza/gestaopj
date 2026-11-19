{{-- Logotipo em texto: primeira palavra na cor da marca ("Augi"), o resto em peso normal. --}}
@php([$primeira, $resto] = array_pad(explode(' ', config('app.name'), 2), 2, ''))
<span class="marca-a">{{ $primeira }}</span>@if ($resto) <span class="marca-b">{{ $resto }}</span>@endif
