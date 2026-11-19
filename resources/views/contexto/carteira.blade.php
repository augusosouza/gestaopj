@extends('layouts.app')
@section('titulo', 'Carteira')
@section('conteudo')
<h1>Carteira de {{ $escritorio->razao_social }}</h1>
<p class="sub">
  @if (ctx()->tem(\App\Enums\Papel::Revisor)) Todas as empresas que autorizaram o escritório.
  @else Empresas atribuídas a você e autorizadas pelo cliente. @endif
</p>
@if (ctx()->empresa())
  <div class="aviso info">Você está acessando <strong>{{ ctx()->empresa()->razao_social }}</strong>.
    <form method="post" action="{{ route('carteira.sair') }}" style="display:inline">@csrf<button class="link">Voltar à carteira</button></form></div>
@endif
<div class="tabela"><table>
  <thead><tr><th>Empresa</th><th>CNPJ</th><th></th></tr></thead>
  <tbody>
  @forelse ($empresas as $e)
    <tr>
      <td>{{ $e->razao_social }}</td><td class="mono">{{ doc($e->cnpj) }}</td>
      <td><form method="post" action="{{ route('carteira.entrar', $e) }}">@csrf<button class="sec">Acessar</button></form></td>
    </tr>
  @empty
    <tr><td colspan="3" class="vazio">Nenhuma empresa na sua carteira ainda.</td></tr>
  @endforelse
  </tbody>
</table></div>
<p class="sub" style="margin-top:8px">Pendências e etapas do fechamento por empresa entram com a área do contador (entrega 3).</p>
@endsection
