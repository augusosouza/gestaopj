@extends('layouts.app')
@section('titulo', 'Clientes')
@section('conteudo')
<h1>Clientes</h1>
<p class="sub">Tomadores dos serviços da empresa.</p>
<div class="duas">
  <div class="tabela"><table>
    <thead><tr><th>Razão social</th><th>Documento</th><th class="n">Contratos</th><th></th></tr></thead>
    <tbody>
    @forelse ($clientes as $c)
      <tr><td>{{ $c->razao_social }}</td><td class="mono">{{ doc($c->documento) }}</td><td class="n">{{ $c->contratos_count }}</td><td><a href="{{ route('clientes.edit', $c) }}">Editar</a></td></tr>
    @empty
      <tr><td colspan="4" class="vazio">Nenhum cliente.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <form method="post" action="{{ $editar ? route('clientes.update', $editar) : route('clientes.store') }}" class="painel">
    @csrf @if ($editar) @method('put') @endif
    <strong>{{ $editar ? 'Editar cliente' : 'Novo cliente' }}</strong>
    <div class="campo" style="margin-top:8px"><label for="razao_social">Razão social / nome</label><input id="razao_social" name="razao_social" value="{{ old('razao_social', $editar?->razao_social) }}" required></div>
    <div class="campo"><label for="documento">CNPJ ou CPF</label><input id="documento" name="documento" value="{{ old('documento', doc($editar?->documento)) }}" required class="mono"></div>
    <div class="grade">
      <div class="campo"><label for="email">E-mail de cobrança</label><input id="email" type="email" name="email" value="{{ old('email', $editar?->email) }}"></div>
      <div class="campo"><label for="municipio_ibge">Código IBGE do município</label><input id="municipio_ibge" name="municipio_ibge" value="{{ old('municipio_ibge', $editar?->municipio_ibge) }}" maxlength="7" inputmode="numeric"></div>
    </div>
    <button>Salvar</button> @if ($editar)<a href="{{ route('clientes.index') }}">Cancelar</a>@endif
  </form>
</div>
@endsection
