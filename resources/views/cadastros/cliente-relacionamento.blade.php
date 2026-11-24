@extends('layouts.app')
@php($titulo = ($registro ? 'Editar ' : 'Adicionar ').($tipo === 'contato' ? 'contato' : 'endereço'))
@section('titulo', $titulo)
@section('compacta', '1')
@section('conteudo')
@php($listaClientes = route('clientes.index', session('clientes.lista.'.auth()->id().'.'.ctx()->organizacaoId(), [])))
<x-cabecalho :titulo="$titulo" :sub="$cliente->razao_social"
  :trilha="['Clientes' => $listaClientes, $cliente->razao_social => route('clientes.show', $cliente), $titulo => null]" />
<form method="post" action="{{ route('clientes.relacionamentos.salvar', [$cliente, $tipo, $registro?->id]) }}" class="painel" data-evitar-reenvio="Salvando…">
  @csrf
  <div class="grade">
    <div class="campo"><label for="finalidade">Finalidade</label><select id="finalidade" name="finalidade" required>
      @foreach (\App\Models\ClienteRelacionamento::FINALIDADES[$tipo] as $codigo => $rotulo)
        <option value="{{ $codigo }}" @selected(old('finalidade', $registro?->finalidade) === $codigo)>{{ $rotulo }}</option>
      @endforeach
    </select></div>
    <div class="campo"><label for="ativo">Situação</label><select id="ativo" name="ativo">
      <option value="1" @selected((bool) old('ativo', $registro?->ativo ?? true))>Ativo</option>
      <option value="0" @selected(! (bool) old('ativo', $registro?->ativo ?? true))>Inativo</option>
    </select></div>
    @foreach (\App\Models\ClienteRelacionamento::CAMPOS[$tipo] as $campo => $rotulo)
      <div class="campo">
        <label for="{{ $campo }}">{{ $rotulo }}</label>
        <input id="{{ $campo }}" name="dados[{{ $campo }}]" type="{{ match ($campo) { 'email' => 'email', 'telefone', 'whatsapp' => 'tel', default => 'text' } }}"
          value="{{ old('dados.'.$campo, $registro->dados[$campo] ?? ($campo === 'pais' ? 'Brasil' : '')) }}"
          @required(in_array($campo, ['nome', 'logradouro', 'municipio', 'pais'], true))>
      </div>
    @endforeach
  </div>
  <div class="formulario-rodape"><button type="submit">Salvar</button><a href="{{ route('clientes.show', $cliente) }}">Cancelar</a></div>
</form>
@endsection
