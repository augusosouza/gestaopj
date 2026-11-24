@extends('layouts.app')
@section('titulo', 'Oportunidades de economia')
@section('conteudo')
<x-cabecalho :trilha="['Sugestões' => route('inteligencia.sugestoes'), 'Oportunidades de economia' => null]"
  titulo="Oportunidades de economia" :sub="ctx()->empresa()->razao_social" />
<div class="ficha-secoes">
<form method="get" action="{{ route('inteligencia.economia') }}" class="painel">
  <div class="grade">
    <div class="campo"><label for="base">Mês de referência</label><input id="base" name="base" type="month" value="{{ $base->format('Y-m') }}" required></div>
    <div class="campo"><label for="atual">Mês analisado</label><input id="atual" name="atual" type="month" value="{{ $atual->format('Y-m') }}" required></div>
  </div>
  <button type="submit">Comparar meses</button>
</form>
<div class="cards">
  <div class="card"><div class="rot">Tarifas bancárias registradas</div><div class="num">{{ brl($r['atual']['tarifas']) }}</div><div class="sub">Referência {{ brl($r['base']['tarifas']) }}</div></div>
  <div class="card"><div class="rot">Acréscimos em baixas registradas</div><div class="num">{{ brl($r['atual']['acrescimos']) }}</div><div class="sub">Referência {{ brl($r['base']['acrescimos']) }}</div></div>
  <div class="card"><div class="rot">Diferença ajustada nas tarifas vinculadas</div>
    <div class="num">{{ $r['diferenca'] === null ? '—' : brl($r['diferenca']) }}</div>
    <div class="sub">{{ $r['diferenca'] === null ? 'Amostra insuficiente' : ($r['diferenca'] >= 0 ? 'Custo abaixo ou igual à referência' : 'Custo acima da referência') }}</div>
  </div>
</div>
<section class="painel">
  <h2>Acompanhamento de economia</h2>
  @if ($r['grupos'])
    <p>Com o volume bruto do mês analisado, a referência seria <strong>{{ brl($r['esperado']) }}</strong>. As tarifas vinculadas nos grupos comparáveis foram de <strong>{{ brl($r['observado']) }}</strong>.</p>
  @else
    <p>A comparação exige três recebimentos tarifados em cada mês para pelo menos uma mesma conta e modalidade.</p>
  @endif
  <p class="sub">O Augi aplica a taxa efetiva de referência ao volume bruto atual, por conta e modalidade. A diferença é uma estimativa ajustada, sujeita a condições comerciais e composição das operações. Tarifas sem vínculo entram só no total registrado.</p>
  <details><summary>Entender esta oportunidade</summary>
    <p>Confira as maiores tarifas registradas no mês analisado e compare com a tabela contratada do banco.</p>
    <ul class="check">
      @forelse ($r['atual']['evidencias'] as $item)
        <li><a href="{{ route('financeiro.titulo', $item->id) }}">{{ $item->descricao }}</a> · {{ brl($item->valor_centavos) }}</li>
      @empty
        <li>Nenhuma tarifa registrada neste período.</li>
      @endforelse
    </ul>
  </details>
  @if (ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
    <details>
      <summary>Registrar ação para acompanhar</summary>
      <form method="post" action="{{ route('inteligencia.economia.acompanhar') }}">
        @csrf <input type="hidden" name="base" value="{{ $base->format('Y-m') }}">
        <div class="campo"><label for="acao-radar">Ação realizada e referência de {{ $base->format('m/Y') }}</label>
          <input id="acao-radar" name="acao" maxlength="160" required>
        </div>
        <button class="sec">Salvar acompanhamento</button>
      </form>
    </details>
  @endif
  @if ($acompanhamentos->isNotEmpty())
    <h3>Ações registradas</h3>
    <ul class="check">@foreach ($acompanhamentos as $evento)
      <li>{{ $evento->created_at->format('d/m/Y') }} · {{ $evento->dados['acao'] }} · referência {{ $evento->dados['base'] }}</li>
    @endforeach</ul>
  @endif
  <p><a href="{{ route('inteligencia.sugestoes', ['tipo' => 'duplicado']) }}">Conferir possíveis pagamentos duplicados</a></p>
</section>
@if ($podeUsarIa)
  <section class="painel">
    <h2>Orientação com IA</h2>
    <p class="sub">A OpenAI recebe somente sinais da comparação, sem valores, documentos ou identificadores da empresa. O Augi mantém o cálculo e os links das evidências.</p>
    @if ($orientacoes)
      <ol>@foreach ($orientacoes as $orientacao)<li>{{ $orientacao }}</li>@endforeach</ol>
    @elseif ($solicitouIa)
      <p class="aviso info">A orientação externa está indisponível ou o limite mensal foi atingido. Os cálculos locais continuam disponíveis.</p>
    @endif
    <form method="post" action="{{ route('inteligencia.economia.orientar') }}" data-evitar-reenvio="Consultando…">
      @csrf
      <input type="hidden" name="base" value="{{ $base->format('Y-m') }}">
      <input type="hidden" name="atual" value="{{ $atual->format('Y-m') }}">
      <label><input type="checkbox" name="autorizar" value="1" required> Autorizar esta consulta à OpenAI</label>
      <button>Pedir orientação à IA</button>
    </form>
  </section>
@endif
</div>
@endsection
