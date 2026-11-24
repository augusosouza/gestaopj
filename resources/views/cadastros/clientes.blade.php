@extends('layouts.app')
@section('titulo', 'Clientes')
@section('conteudo')
@php($rotulos = [
  'busca' => ['Pesquisa', [$filtros['busca'] ?? '' => $filtros['busca'] ?? '']],
  'tipo' => ['Pessoa', ['pf' => 'Física', 'pj' => 'Jurídica']],
  'contratos' => ['Contratos', ['com' => 'Com contrato', 'sem' => 'Sem contrato']],
  'ordem' => ['Ordem', ['nome' => 'Nome de A a Z', 'nome_desc' => 'Nome de Z a A', 'contratos' => 'Mais contratos']],
])
@php($filtrosAtivos = array_intersect_key($filtros, $rotulos))
<x-cabecalho :trilha="['Operação' => null, 'Clientes' => null]" titulo="Clientes" sub="Consulte os dados dos clientes e acesse seus contratos e faturas.">
  <x-slot:acoes><a class="botao" href="{{ route('clientes.create') }}">Novo cliente</a></x-slot:acoes>
</x-cabecalho>
<form method="get" action="{{ route('clientes.index') }}" class="painel lista-filtros">
  <div class="lista-filtros-principais">
    <div class="campo lista-pesquisa"><label for="busca">Nome, CPF/CNPJ ou e-mail</label><input type="search" id="busca" name="busca" maxlength="120" value="{{ $filtros['busca'] ?? '' }}"></div>
    <div class="campo"><label for="tipo">Tipo de pessoa</label><select id="tipo" name="tipo">
      <option value="">Todos</option>
      @foreach ($rotulos['tipo'][1] as $valor => $rotulo)<option value="{{ $valor }}" @selected(($filtros['tipo'] ?? '') === $valor)>{{ $rotulo }}</option>@endforeach
    </select></div>
    <div class="campo"><label for="ordem">Ordenar por</label><select id="ordem" name="ordem">
      <option value="">Nome de A a Z</option>
      @foreach (array_diff_key($rotulos['ordem'][1], ['nome' => true]) as $valor => $rotulo)<option value="{{ $valor }}" @selected(($filtros['ordem'] ?? '') === $valor)>{{ $rotulo }}</option>@endforeach
    </select></div>
  </div>
  <details class="filtros-avancados" @if (isset($filtros['contratos'])) open @endif>
    <summary>Filtros avançados</summary>
    <div class="lista-filtros-principais">
      <div class="campo"><label for="contratos">Contratos</label><select id="contratos" name="contratos">
        <option value="">Todos</option>
        @foreach ($rotulos['contratos'][1] as $valor => $rotulo)<option value="{{ $valor }}" @selected(($filtros['contratos'] ?? '') === $valor)>{{ $rotulo }}</option>@endforeach
      </select></div>
    </div>
  </details>
  <div class="acoes"><button type="submit" class="sec">Filtrar</button>@if ($filtrosAtivos)<a href="{{ route('clientes.index') }}">Limpar filtros</a>@endif</div>
</form>
@include('partials.visoes-salvas', ['modulo' => 'clientes', 'filtrosVisao' => $filtrosAtivos])
@if ($filtrosAtivos)
  <ul class="contas-filtrosativos" aria-label="Filtros aplicados">
    @foreach ($filtrosAtivos as $campo => $valor)
      <li><a href="{{ route('clientes.index', array_diff_key($filtrosAtivos, [$campo => true])) }}" aria-label="Remover {{ $rotulos[$campo][0] }}: {{ $rotulos[$campo][1][$valor] }}">
        {{ $rotulos[$campo][0] }} · {{ $rotulos[$campo][1][$valor] }} <span aria-hidden="true">×</span>
      </a></li>
    @endforeach
  </ul>
@endif
@if ($clientes->count())
  <div class="lista-barra">
    <p class="sub">@if ($clientes->total() === 1) 1 cliente @else Exibindo {{ $clientes->firstItem() }}–{{ $clientes->lastItem() }} de {{ $clientes->total() }} clientes @endif</p>
    <x-densidade tabela="lista-clientes" />
  </div>
  <div class="tabela clientes-lista" id="lista-clientes"><table aria-label="Clientes encontrados" role="table">
    <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Cliente</th><th role="columnheader" scope="col">CPF/CNPJ</th><th role="columnheader" scope="col">Contato</th><th role="columnheader" scope="col" class="n">Contratos</th></tr></thead>
    <tbody role="rowgroup">
      @foreach ($clientes as $cliente)
        <tr role="row">
          <td role="cell" class="registro-principal"><a href="{{ route('clientes.show', $cliente) }}">{{ $cliente->razao_social }}</a>@if ($cliente->nome_fantasia)<div class="sub">{{ $cliente->nome_fantasia }}</div>@endif</td>
          <td role="cell" class="mono" data-rotulo="CPF/CNPJ">{{ doc($cliente->documento) }}</td>
          <td role="cell" class="registro-contato" data-rotulo="Contato">{{ $cliente->email ?? $cliente->telefone ?? '—' }}</td>
          <td role="cell" class="n" data-rotulo="Contratos">{{ $cliente->contratos_count }}</td>
        </tr>
      @endforeach
    </tbody>
  </table></div>
@elseif ($filtrosAtivos)
  <x-estado-vazio titulo="Nenhum cliente encontrado" descricao="Altere ou limpe os filtros para consultar outros registros." :url="route('clientes.index')" acao="Limpar filtros" />
@elseif ($clientes->total())
  <x-estado-vazio titulo="Esta página está vazia" descricao="Volte à primeira página para consultar os registros." :url="route('clientes.index')" acao="Ver clientes" />
@else
  <x-estado-vazio titulo="Nenhum cliente cadastrado" descricao="Cadastre um cliente para vincular contratos e consultar suas faturas." :url="route('clientes.create')" acao="Cadastrar primeiro cliente" />
@endif
{{ $clientes->links() }}
@endsection
