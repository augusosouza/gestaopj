@extends('layouts.app')
@section('titulo', 'Atribuições')
@section('conteudo')
<h1>Atribuições</h1>
<p class="sub">Distribua as empresas da carteira entre os analistas. Encerrar uma atribuição corta o acesso do analista na próxima ação e mantém o histórico.</p>
<div class="tabela"><table>
  <thead><tr><th>Empresa</th><th>Analistas atribuídos</th><th>Atribuir</th></tr></thead>
  <tbody>
  @forelse ($empresas as $e)
    <tr>
      <td>{{ $e->razao_social }}<br><span class="mono">{{ doc($e->cnpj) }}</span></td>
      <td>
        @forelse ($atribuicoes[$e->id] ?? [] as $a)
          <div style="display:flex;gap:8px;align-items:center;margin-bottom:4px">{{ $a->user->name }}
            <form method="post" action="{{ route('escritorio.encerrar', $a) }}">@csrf @method('patch')<button class="link">encerrar</button></form></div>
        @empty <span class="sub">Ninguém — só revisores acessam</span> @endforelse
      </td>
      <td>
        @if ($analistas->isNotEmpty())
        <form method="post" action="{{ route('escritorio.atribuir') }}" style="display:flex;gap:6px">
          @csrf <input type="hidden" name="empresa_id" value="{{ $e->id }}">
          <select name="user_id" aria-label="Analista">@foreach ($analistas as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
          <button class="sec">Atribuir</button>
        </form>
        @else <span class="sub">Cadastre analistas em Acessos</span> @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="3" class="vazio">Nenhuma empresa autorizou o escritório ainda.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
