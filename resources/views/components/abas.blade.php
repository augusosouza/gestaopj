{{--
  Abas: organizam conteúdos de uma mesma área (filtros ficam fora delas).
  Uso: <x-abas :itens="[['Resumo', url, true], ['Parcelas', url, false, 3]]" />
--}}
@props(['itens' => []])
<nav class="abas" data-abas aria-label="Seções desta página">
  @foreach ($itens as $i)
    <a href="{{ $i[1] }}" @class(['ativa' => $i[2]]) @if ($i[2]) aria-current="page" @endif>{{ $i[0] }}@if (isset($i[3]) && $i[3] !== null)<span class="contador-aba">{{ $i[3] }}</span>@endif</a>
  @endforeach
</nav>
