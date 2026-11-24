@props(['titulo', 'descricao', 'url' => null, 'acao' => null])
<div class="estado-vazio">
  <h2>{{ $titulo }}</h2>
  <p>{{ $descricao }}</p>
  @if ($url && $acao)<a class="botao sec" href="{{ $url }}">{{ $acao }}</a>@endif
</div>
