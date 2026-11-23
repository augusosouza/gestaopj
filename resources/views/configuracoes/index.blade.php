@extends('layouts.app')
@section('titulo', 'Configurações')
@section('conteudo')
<x-cabecalho :trilha="['Configurações' => null]" titulo="Configurações" :sub="'Ajustes de '.ctx()->empresa()->razao_social.' e da sua conta.'" />
<input type="search" id="busca-config" class="busca-config" placeholder="Pesquisar configuração… (ex.: 2FA, extrato, categorias)" aria-label="Pesquisar configuração">
<p id="sem-resultado" class="sub" hidden>Nenhuma configuração encontrada.</p>
<div class="config-secoes">
  @foreach ($secoes as [$titulo, $descricao, $itens])
    <section class="painel config-secao">
      <h2 style="margin:0 0 2px">{{ $titulo }}</h2>
      <p class="sub" style="margin:0 0 10px;font-size:13px">{{ $descricao }}</p>
      @foreach ($itens as [$rotulo, $desc, $url, $palavras])
        <a class="config-item" href="{{ $url }}" data-busca="{{ mb_strtolower($titulo.' '.$rotulo.' '.$desc.' '.$palavras) }}">
          <strong>{{ $rotulo }}</strong><span>{{ $desc }}</span></a>
      @endforeach
    </section>
  @endforeach
</div>
<script>
  (function () {
    var campo = document.getElementById('busca-config'), vazio = document.getElementById('sem-resultado');
    var norm = function (s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
    campo.addEventListener('input', function () {
      var q = norm(campo.value.trim()), algum = false;
      document.querySelectorAll('.config-secao').forEach(function (sec) {
        var visiveis = 0;
        sec.querySelectorAll('.config-item').forEach(function (i) { var ok = !q || norm(i.dataset.busca).indexOf(q) >= 0; i.hidden = !ok; if (ok) visiveis++; });
        sec.hidden = visiveis === 0; if (visiveis) algum = true;
      });
      vazio.hidden = algum;
    });
  })();
</script>
@endsection
