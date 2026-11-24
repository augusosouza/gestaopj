@extends('layouts.app')
@section('titulo', $artigo['titulo'])
@section('larga', '1')
@section('conteudo')
<x-cabecalho :trilha="['Base de conhecimento' => route('conhecimento.index'), $categoria => route('conhecimento.index', ['categoria' => $artigo['categoria']]), $artigo['titulo'] => null]"
  :titulo="$artigo['titulo']" :sub="$artigo['resumo']" />

<div class="conhecimento-artigo">
  <article class="painel">
    <h2>Passo a passo</h2>
    <ol class="conhecimento-passos">
      @foreach ($artigo['passos'] as $passo)
        <li>{{ $passo }}</li>
      @endforeach
    </ol>
    @if ($destino)
      <a class="botao" href="{{ $destino }}">Abrir tela no Augi</a>
    @endif
  </article>
  <aside class="painel conhecimento-lateral" aria-label="Acesso e guias relacionados">
    <h2>Quem pode fazer</h2>
    <p class="sub">{{ $artigo['quem'] }}</p>
    @if ($relacionados)
      <h2>Guias relacionados</h2>
      <ul>
        @foreach ($relacionados as $slug => $relacionado)
          <li><a href="{{ route('conhecimento.artigo', $slug) }}">{{ $relacionado['titulo'] }}</a></li>
        @endforeach
      </ul>
    @endif
  </aside>
</div>
@endsection
