@extends('layouts.app')
@section('titulo', 'Assistente financeiro')
@section('conteudo')
<x-cabecalho :trilha="['Inteligência' => null, 'Assistente' => null]" titulo="Assistente financeiro"
  sub="Pergunte sobre o dinheiro da empresa. As respostas vêm das mesmas consultas das telas, com regras fixas — sem IA generativa e sem custo de tokens." />
<form method="get" action="{{ route('inteligencia.assistente') }}" class="pergunta">
  <label for="q" class="sr-only">Sua pergunta</label>
  <input id="q" name="q" value="{{ $q }}" maxlength="300" placeholder="Ex.: Quanto vou receber nos próximos 15 dias?" autocomplete="off" autofocus>
  <button>Perguntar</button>
</form>
<div class="sugeridas" aria-label="Perguntas sugeridas">
  @foreach ($sugeridas as $s)<a href="{{ route('inteligencia.assistente', ['q' => $s]) }}" @class(['chip', 'ativo' => $s === $q])>{{ $s }}</a>@endforeach
</div>

@if ($r)
  <section class="painel resposta" aria-live="polite">
    <div class="sub" style="font-size:12.5px;margin:0 0 4px">Você perguntou: “{{ $r['pergunta'] }}”</div>
    <h2 style="margin:0">{{ $r['titulo'] }}</h2>
    @if ($r['destaque'])<div class="num-grande" style="margin-top:6px">{{ $r['destaque'] }}</div>@endif
    <p style="margin:8px 0 0">{{ $r['texto'] }}</p>
    @if ($r['linhas'])
      <div class="tabela" style="margin-top:12px"><table>
        <thead><tr>@foreach ($r['colunas'] as $i => $col)<th @class(['n' => $i > 0 && $i === count($r['colunas']) - 1 && str_contains(implode('', array_column($r['linhas'], $i)), 'R$')])>{{ $col }}</th>@endforeach</tr></thead>
        <tbody>@foreach ($r['linhas'] as $l)<tr>@foreach ($l as $i => $v)<td @class(['n' => $i > 0 && $i === count($l) - 1 && str_contains($v, 'R$')])>{{ $v }}</td>@endforeach</tr>@endforeach</tbody>
      </table></div>
    @endif
    <p class="sub" style="font-size:12.5px;margin:10px 0 0"><strong>De onde vem:</strong> {{ $r['origem'] }}</p>
    @if ($r['links'])<div class="acoes" style="margin-top:12px;flex-wrap:wrap">@foreach ($r['links'] as [$rot, $href])<a @class(['botao', 'sec' => ! $loop->first]) href="{{ $href }}">{{ $rot }}</a>@endforeach</div>@endif
  </section>
@endif
@endsection
