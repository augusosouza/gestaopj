@extends('layouts.app')
@section('titulo', 'Configurações')
@section('conteudo')
<x-cabecalho :trilha="['Configurações' => null]" titulo="Configurações" :sub="'Preferências de '.ctx()->empresa()->razao_social.' e da sua conta.'" />
<label for="busca-config" class="ajustes-busca-label">Encontrar uma configuração</label>
<input type="search" id="busca-config" class="busca-config" placeholder="Ex.: inteligência, permissões, SMTP">
<p id="sem-resultado" class="sub" role="status" hidden>Nenhuma configuração encontrada. Tente outro termo.</p>
<div class="config-secoes">
  @foreach ($secoes as [$titulo, $descricao, $itens])
    <section class="painel config-secao" aria-labelledby="ajustes-{{ $loop->index }}">
      <h2 id="ajustes-{{ $loop->index }}">{{ $titulo }}</h2>
      <p class="sub">{{ $descricao }}</p>
      @foreach ($itens as [$rotulo, $desc, $url, $palavras])
        <a class="config-item" href="{{ $url }}" data-busca="{{ $titulo.' '.$rotulo.' '.$desc.' '.$palavras }}">
          <strong>{{ $rotulo }}</strong><span>{{ $desc }}</span><b aria-hidden="true">›</b>
        </a>
      @endforeach
    </section>
  @endforeach
</div>
<script src="{{ asset('js/configuracoes.js') }}" defer></script>
@endsection
