@extends('layouts.app')
@section('titulo', 'Busca global')
@section('compacta', '1')
@section('conteudo')
<x-cabecalho titulo="Busca global" :sub="'Pesquisando em '.ctx()->empresa()->razao_social" />
<form class="busca-form" action="{{ route('busca') }}" method="post" role="search" data-evitar-reenvio="Buscando…">
  @csrf
  <div class="campo">
    <label for="busca-global">Pesquisar por nome, documento ou número</label>
    <input id="busca-global" name="q" type="search" value="{{ mb_substr($termo, 0, 100) }}" minlength="2" maxlength="100" autocomplete="off" required @if ($termo === '') autofocus @endif
      @if ($erro) aria-invalid="true" aria-describedby="busca-erro" @endif>
    @if ($erro)<p id="busca-erro" class="campo-erro">Informe de 2 a 100 caracteres.</p>@endif
  </div>
  <button type="submit">Buscar</button>
</form>
@if (!$erro && $termo !== '' && !$grupos && !$atalhos)
  <x-estado-vazio titulo="Nenhum resultado encontrado" descricao="Confira o termo ou pesquise pelo número do registro." />
@endif
<div class="ficha-secoes">
  @foreach ($grupos as $grupo => $itens)
    <section class="painel busca-grupo">
      <h2>{{ $grupo }}</h2>
      <ul>
        @foreach ($itens as [$rotulo, $url, $contexto])
          <li><a href="{{ $url }}">{{ $rotulo }} <span class="sub">{{ $contexto }}</span></a></li>
        @endforeach
      </ul>
    </section>
  @endforeach
  @if ($atalhos)
    <section class="painel busca-grupo">
      <h2>Atalhos</h2>
      <ul>@foreach ($atalhos as [$rotulo, $url])<li><a href="{{ $url }}">{{ $rotulo }}</a></li>@endforeach</ul>
    </section>
  @endif
</div>
@endsection
