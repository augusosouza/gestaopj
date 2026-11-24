@extends('layouts.app')
@section('titulo', 'Base de conhecimento')
@section('larga', '1')
@section('conteudo')
<x-cabecalho :trilha="['Ajuda' => null]" titulo="Base de conhecimento"
  sub="Encontre orientações para configurar o Augi e executar as tarefas do dia a dia." />

<form method="get" action="{{ route('conhecimento.index') }}" class="painel conhecimento-filtros" role="search" aria-label="Pesquisar guias">
  <div class="campo">
    <label for="conhecimento-busca">O que você precisa fazer?</label>
    <input type="search" id="conhecimento-busca" name="q" value="{{ $busca }}" maxlength="160" placeholder="Ex.: adicionar empresa, convidar usuário, conciliar extrato">
  </div>
  <div class="campo">
    <label for="conhecimento-categoria">Módulo</label>
    <select id="conhecimento-categoria" name="categoria">
      <option value="">Todos os módulos</option>
      @foreach ($categorias as $chave => $rotulo)
        <option value="{{ $chave }}" @selected($categoria === $chave)>{{ $rotulo }}</option>
      @endforeach
    </select>
  </div>
  <button>Buscar</button>
  @if ($busca !== '' || $categoria)
    <a class="botao sec" href="{{ route('conhecimento.index') }}">Limpar</a>
  @endif
</form>

@if ($busca === '' && ! $categoria)
  <section aria-labelledby="conhecimento-inicio">
    <h2 id="conhecimento-inicio">Guias para começar</h2>
    <div class="conhecimento-destaques">
      @foreach (config('conhecimento.destaques') as $slug)
        @php($guia = $artigos[$slug])
        <a class="painel conhecimento-link" href="{{ route('conhecimento.artigo', $slug) }}">
          <strong>{{ $guia['titulo'] }}</strong>
          <span class="sub">{{ $guia['resumo'] }}</span>
        </a>
      @endforeach
    </div>
  </section>
@endif

@if ($busca !== '' || $categoria)
  <p role="status" class="sub">{{ count($artigos) }} {{ count($artigos) === 1 ? 'guia encontrado' : 'guias encontrados' }}@if ($busca !== '') para “{{ $busca }}”@endif.</p>
@endif

@if ($artigos)
  <div class="conhecimento-modulos">
    @foreach ($categorias as $chave => $rotulo)
      @php($itens = array_filter($artigos, fn ($item) => $item['categoria'] === $chave))
      @continue(! $itens)
      <section class="painel conhecimento-modulo" aria-labelledby="modulo-{{ $chave }}">
        <h2 id="modulo-{{ $chave }}">{{ $rotulo }}</h2>
        <ul>
          @foreach ($itens as $slug => $artigo)
            <li>
              <a class="conhecimento-link" href="{{ route('conhecimento.artigo', $slug) }}">
                <strong>{{ $artigo['titulo'] }}</strong>
                <span class="sub">{{ $artigo['resumo'] }}</span>
              </a>
            </li>
          @endforeach
        </ul>
      </section>
    @endforeach
  </div>
@else
  <section class="painel conhecimento-vazio">
    <h2>Nenhum guia encontrado</h2>
    <p class="sub">Tente outro termo ou procure em todos os módulos.</p>
    <a href="{{ route('conhecimento.index') }}">Ver todos os guias</a>
  </section>
@endif
@endsection
