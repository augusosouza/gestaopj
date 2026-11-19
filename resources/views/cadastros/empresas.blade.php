@extends('layouts.app')
@section('titulo', 'Empresa')
@section('conteudo')
<h1>Empresa</h1>
<p class="sub">CNPJs da sua empresa: emitentes das notas aos clientes e tomadores das notas dos prestadores.</p>
<div class="duas">
  <div class="tabela"><table>
    <thead><tr><th>Razão social</th><th>CNPJ</th><th>Município (IBGE)</th><th></th></tr></thead>
    <tbody>
    @forelse ($empresas as $e)
      <tr><td>{{ $e->razao_social }}</td><td class="mono">{{ doc($e->cnpj) }}</td><td>{{ $e->municipio_ibge ?? '—' }}</td><td><a href="{{ route('empresas.edit', $e) }}">Editar</a></td></tr>
    @empty
      <tr><td colspan="4" class="vazio">Cadastre o CNPJ da sua empresa.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <form method="post" action="{{ $editar ? route('empresas.update', $editar) : route('empresas.store') }}" class="painel">
    @csrf @if ($editar) @method('put') @endif
    <strong>{{ $editar ? 'Editar empresa' : 'Nova empresa' }}</strong>
    <div class="campo" style="margin-top:8px"><label for="razao_social">Razão social</label><input id="razao_social" name="razao_social" value="{{ old('razao_social', $editar?->razao_social) }}" required></div>
    <div class="campo"><label for="cnpj">CNPJ</label><input id="cnpj" name="cnpj" value="{{ old('cnpj', doc($editar?->cnpj)) }}" required class="mono"></div>
    <div class="grade">
      <div class="campo"><label for="inscricao_municipal">Inscrição municipal</label><input id="inscricao_municipal" name="inscricao_municipal" value="{{ old('inscricao_municipal', $editar?->inscricao_municipal) }}"></div>
      <div class="campo"><label for="municipio_ibge">Código IBGE do município</label><input id="municipio_ibge" name="municipio_ibge" value="{{ old('municipio_ibge', $editar?->municipio_ibge) }}" maxlength="7" inputmode="numeric"></div>
    </div>
    <button>Salvar</button> @if ($editar)<a href="{{ route('empresas.index') }}">Cancelar</a>@endif
  </form>
</div>
@endsection
