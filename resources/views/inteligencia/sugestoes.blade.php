@extends('layouts.app')
@section('titulo', 'Sugestões')
@section('conteudo')
<x-cabecalho :trilha="['Inteligência' => null, 'Sugestões' => null]" titulo="Sugestões"
  sub="Situações que pedem atenção, encontradas por regras sobre os dados da empresa. Cada uma diz o motivo e o que fazer." />
<nav class="filtros" aria-label="Tipo de sugestão">
  <a href="{{ route('inteligencia.sugestoes') }}" @class(['ativo' => ! $tipo])>Todas ({{ $contagem->sum() }})</a>
  @foreach (\App\Services\Sugestoes::TIPOS as $k => $rot)
    @if ($contagem[$k] ?? 0)<a href="{{ route('inteligencia.sugestoes', ['tipo' => $k]) }}" @class(['ativo' => $tipo === $k])>{{ $rot }} ({{ $contagem[$k] }})</a>@endif
  @endforeach
</nav>
@forelse ($sugestoes as $s)
  <div class="acao acao-{{ $s['nivel'] }} sugestao">
    <div style="flex:1 1 320px"><span class="sub" style="font-size:12px">{{ \App\Services\Sugestoes::TIPOS[$s['tipo']] }}</span>
      <div><strong>{{ $s['titulo'] }}</strong></div><div class="sub" style="margin:2px 0 0">{{ $s['motivo'] }}</div></div>
    <div class="acoes" style="flex-wrap:wrap">
      <a class="botao {{ $s['nivel'] === 'bad' ? '' : 'sec' }}" href="{{ $s['url'] }}">{{ $s['acao'] }}</a>
      @if ($podeDispensar)
        <details class="dispensar"><summary class="link-sum">adiar ou dispensar</summary>
          <form method="post" action="{{ route('inteligencia.dispensar') }}" class="dispensar-form">@csrf
            <input type="hidden" name="chave" value="{{ $s['chave'] }}">
            <input name="motivo" placeholder="Motivo (opcional)" aria-label="Motivo">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button class="sec" name="dias" value="3">Adiar 3 dias</button><button class="sec" name="dias" value="7">Adiar 7 dias</button>
              <button class="sec">Dispensar</button></div>
          </form>
        </details>
      @endif
    </div>
  </div>
@empty
  <p class="painel vazio">Nada pedindo atenção agora.</p>
@endforelse
<p class="sub" style="font-size:12.5px;margin-top:10px">Dispensar esconde só esta situação. Se ela mudar (outro atraso, outro valor), uma nova sugestão aparece. Toda dispensa fica na auditoria.</p>
@endsection
