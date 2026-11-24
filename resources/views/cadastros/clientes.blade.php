@extends('layouts.app')
@section('titulo', 'Clientes')
@section('conteudo')
<x-cabecalho :trilha="['Operação' => null, 'Clientes' => null]" titulo="Clientes" sub="Consulte os dados dos clientes e acesse seus contratos e faturas.">
  <x-slot:acoes><a class="btn" href="{{ route('clientes.create') }}">Novo cliente</a></x-slot:acoes>
</x-cabecalho>
<form method="get" action="{{ route('clientes.index') }}" class="painel">
  <div class="grade">
    <div class="campo"><label for="busca">Nome ou CPF/CNPJ</label><input type="search" id="busca" name="busca" maxlength="120" value="{{ $filtros['busca'] ?? '' }}"></div>
    <div class="campo"><label for="tipo">Tipo de pessoa</label><select id="tipo" name="tipo">
      <option value="">Todos</option><option value="pf" @selected(($filtros['tipo'] ?? '') === 'pf')>Física</option><option value="pj" @selected(($filtros['tipo'] ?? '') === 'pj')>Jurídica</option>
    </select></div>
    <div class="campo"><label for="contratos">Contratos</label><select id="contratos" name="contratos">
      <option value="">Todos</option><option value="com" @selected(($filtros['contratos'] ?? '') === 'com')>Com contrato</option><option value="sem" @selected(($filtros['contratos'] ?? '') === 'sem')>Sem contrato</option>
    </select></div>
  </div>
  <div class="acoes"><button type="submit">Filtrar</button><a href="{{ route('clientes.index') }}">Limpar filtros</a></div>
</form>
<p class="sub">{{ $clientes->total() }} {{ $clientes->total() === 1 ? 'cliente encontrado' : 'clientes encontrados' }}</p>
<div class="tabela"><table>
  <thead><tr><th scope="col">Cliente</th><th scope="col">CPF/CNPJ</th><th scope="col">Contato</th><th scope="col" class="n">Contratos</th></tr></thead>
  <tbody>
  @forelse ($clientes as $cliente)
    <tr>
      <td><a href="{{ route('clientes.show', $cliente) }}">{{ $cliente->razao_social }}</a>@if ($cliente->nome_fantasia)<br><span class="sub">{{ $cliente->nome_fantasia }}</span>@endif</td>
      <td class="mono">{{ doc($cliente->documento) }}</td>
      <td>{{ $cliente->email ?? $cliente->telefone ?? '—' }}</td>
      <td class="n">{{ $cliente->contratos_count }}</td>
    </tr>
  @empty
    <tr><td colspan="4" class="vazio">Nenhum cliente encontrado. Confira os filtros ou cadastre um cliente.</td></tr>
  @endforelse
  </tbody>
</table></div>
{{ $clientes->links() }}
@endsection
