@extends('layouts.app')
@section('titulo', $cliente ? 'Editar cliente' : 'Novo cliente')
@section('compacta', '1')
@section('conteudo')
@php($listaClientes = route('clientes.index', session('clientes.lista.'.auth()->id().'.'.ctx()->organizacaoId(), [])))
<x-cabecalho :trilha="['Clientes' => $listaClientes, ($cliente ? 'Editar cliente' : 'Novo cliente') => null]"
  :titulo="$cliente ? 'Editar cliente' : 'Novo cliente'" />
<form method="post" action="{{ $cliente ? route('clientes.update', $cliente) : route('clientes.store') }}" class="painel" data-evitar-reenvio="Salvando…">
  @csrf @if ($cliente) @method('put') @endif
  <h2>Identificação</h2>
  <div class="campo"><label for="razao_social">Nome ou razão social</label><input id="razao_social" name="razao_social" value="{{ old('razao_social', $cliente?->razao_social) }}" maxlength="255" required @error('razao_social') aria-invalid="true" aria-describedby="erro-razao_social" @enderror>
      @error('razao_social')<p class="campo-erro" id="erro-razao_social">{{ $message }}</p>@enderror</div>
  <div class="grade">
    <div class="campo"><label for="documento">CPF ou CNPJ</label><input id="documento" name="documento" value="{{ old('documento', doc($cliente?->documento)) }}" maxlength="32" required class="mono" @error('documento') aria-invalid="true" aria-describedby="erro-documento" @enderror>
      @error('documento')<p class="campo-erro" id="erro-documento">{{ $message }}</p>@enderror</div>
    <div class="campo"><label for="nome_fantasia">Nome fantasia</label><input id="nome_fantasia" name="nome_fantasia" value="{{ old('nome_fantasia', $cliente?->nome_fantasia) }}" maxlength="255" @error('nome_fantasia') aria-invalid="true" aria-describedby="erro-nome_fantasia" @enderror>
      @error('nome_fantasia')<p class="campo-erro" id="erro-nome_fantasia">{{ $message }}</p>@enderror</div>
  </div>
  <h2>Contato</h2>
  <div class="grade">
    <div class="campo"><label for="email">E-mail de cobrança</label><input id="email" type="email" name="email" value="{{ old('email', $cliente?->email) }}" maxlength="255" @error('email') aria-invalid="true" aria-describedby="erro-email" @enderror>
      @error('email')<p class="campo-erro" id="erro-email">{{ $message }}</p>@enderror</div>
    <div class="campo"><label for="telefone">Telefone</label><input id="telefone" type="tel" name="telefone" value="{{ old('telefone', $cliente?->telefone) }}" maxlength="32" @error('telefone') aria-invalid="true" aria-describedby="erro-telefone" @enderror>
      @error('telefone')<p class="campo-erro" id="erro-telefone">{{ $message }}</p>@enderror</div>
    <div class="campo"><label for="municipio_ibge">Código IBGE do município</label><input id="municipio_ibge" name="municipio_ibge" value="{{ old('municipio_ibge', $cliente?->municipio_ibge) }}" maxlength="7" inputmode="numeric" @error('municipio_ibge') aria-invalid="true" aria-describedby="erro-municipio_ibge" @enderror>
      @error('municipio_ibge')<p class="campo-erro" id="erro-municipio_ibge">{{ $message }}</p>@enderror</div>
  </div>
  <div class="formulario-rodape"><button type="submit">{{ $cliente ? 'Salvar alterações' : 'Cadastrar cliente' }}</button>
    <a href="{{ $cliente ? route('clientes.show', $cliente) : $listaClientes }}">Cancelar</a></div>
</form>
@endsection
