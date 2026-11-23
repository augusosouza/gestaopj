@extends('layouts.app')
@section('titulo', 'Contabilidade')
@section('conteudo')
@php($contador = ctx()->tem(\App\Enums\Papel::Analista, \App\Enums\Papel::Revisor))
<div class="barra">
  <div>
    <h1>Contabilidade</h1>
    <p class="sub">{{ $contador ? 'Competências desta empresa: documentos recebidos, solicitações e fechamento.' : 'Envie ao seu contador os documentos de cada mês e acompanhe o fechamento.' }}</p>
  </div>
  <a class="botao" href="{{ route('contabil.show', $sugerida->format('Y-m')) }}">Abrir {{ $sugerida->translatedFormat('F/Y') }}</a>
</div>
<div class="tabela"><table>
  <thead><tr><th>Competência</th><th>Etapa</th><th class="n">Documentos</th><th class="n">Solicitações pendentes</th><th>Envio da empresa</th><th>Versão</th><th></th></tr></thead>
  <tbody>
  @forelse ($competencias as $cc)
    <tr>
      <td>{{ ucfirst($cc->competencia->translatedFormat('F \d\e Y')) }}</td>
      <td>@include('contabil._etapa', ['etapa' => $cc->etapa])</td>
      <td class="n">{{ $cc->documentos_count }}</td>
      <td class="n">{{ $cc->pendentes_count ?: '—' }}</td>
      <td>{{ $cc->enviada_em?->format('d/m/Y') ?? '—' }}</td>
      <td>{{ $cc->versao_atual ? 'v'.$cc->versao_atual : '—' }}</td>
      <td><a href="{{ route('contabil.show', $cc->mes()) }}">Abrir</a></td>
    </tr>
  @empty
    <tr><td colspan="7" class="vazio">Nenhuma competência iniciada. Abra o mês para enviar os primeiros documentos.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
