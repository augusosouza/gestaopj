@extends('layouts.app')
@section('titulo', $cliente ? 'Editar cliente' : 'Novo cliente')
@section('compacta', '1')
@section('conteudo')
<x-cabecalho :trilha="['Clientes' => route('clientes.index'), ($cliente ? 'Editar cliente' : 'Novo cliente') => null]"
  :titulo="$cliente ? 'Editar cliente' : 'Novo cliente'" />
<form method="post" action="{{ $cliente ? route('clientes.update', $cliente) : route('clientes.store') }}" class="painel">
  @csrf @if ($cliente) @method('put') @endif
  <h2>Identificação</h2>
  <div class="campo"><label for="razao_social">Nome ou razão social</label><input id="razao_social" name="razao_social" value="{{ old('razao_social', $cliente?->razao_social) }}" maxlength="255" required></div>
  <div class="grade">
    <div class="campo"><label for="documento">CPF ou CNPJ</label><input id="documento" name="documento" value="{{ old('documento', doc($cliente?->documento)) }}" maxlength="32" required class="mono"></div>
    <div class="campo"><label for="nome_fantasia">Nome fantasia</label><input id="nome_fantasia" name="nome_fantasia" value="{{ old('nome_fantasia', $cliente?->nome_fantasia) }}" maxlength="255"></div>
  </div>
  <h2>Contato</h2>
  <div class="grade">
    <div class="campo"><label for="email">E-mail de cobrança</label><input id="email" type="email" name="email" value="{{ old('email', $cliente?->email) }}" maxlength="255"></div>
    <div class="campo"><label for="telefone">Telefone</label><input id="telefone" type="tel" name="telefone" value="{{ old('telefone', $cliente?->telefone) }}" maxlength="32"></div>
    <div class="campo"><label for="municipio_ibge">Código IBGE do município</label><input id="municipio_ibge" name="municipio_ibge" value="{{ old('municipio_ibge', $cliente?->municipio_ibge) }}" maxlength="7" inputmode="numeric"></div>
  </div>
  <div class="acoes"><button type="submit">{{ $cliente ? 'Salvar alterações' : 'Cadastrar cliente' }}</button>
    <a href="{{ $cliente ? route('clientes.show', $cliente) : route('clientes.index') }}">Cancelar</a></div>
</form>
@endsection
