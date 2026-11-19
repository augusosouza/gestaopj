@extends('layouts.app')
@section('titulo', $contrato->exists ? 'Editar contrato' : 'Novo contrato')
@section('conteudo')
<h1>{{ $contrato->exists ? 'Editar contrato '.$contrato->numero : 'Novo contrato' }}</h1>
@if ($clientes->isEmpty())
  <div class="aviso info">Cadastre antes o <a href="{{ route('clientes.index') }}">cliente</a>.</div>
@endif
<form method="post" action="{{ $contrato->exists ? route('contratos.update', $contrato) : route('contratos.store') }}" class="painel">
  @csrf @if ($contrato->exists) @method('put') @endif
  <div class="grade">
    <div class="campo"><label>Empresa (emitente)</label><input value="{{ ctx()->empresa()->razao_social }}" disabled></div>
    <div class="campo"><label for="cliente_id">Cliente (tomador)</label>
      <select id="cliente_id" name="cliente_id" required>
        @foreach ($clientes as $c)<option value="{{ $c->id }}" @selected(old('cliente_id', $contrato->cliente_id) == $c->id)>{{ $c->razao_social }}</option>@endforeach
      </select></div>
    <div class="campo"><label for="numero">Número</label><input id="numero" name="numero" value="{{ old('numero', $contrato->numero) }}" required></div>
    <div class="campo"><label for="status">Status</label>
      <select id="status" name="status">
        @foreach (['ativo' => 'Ativo', 'suspenso' => 'Suspenso', 'encerrado' => 'Encerrado'] as $v => $r)<option value="{{ $v }}" @selected(old('status', $contrato->status) === $v)>{{ $r }}</option>@endforeach
      </select></div>
  </div>
  <div class="campo"><label for="descricao">Descrição do serviço</label><input id="descricao" name="descricao" value="{{ old('descricao', $contrato->descricao) }}" required placeholder="Suporte técnico e consultoria em TI"></div>
  <div class="grade">
    <div class="campo"><label for="codigo_servico">Código de tributação nacional (cTribNac)</label><input id="codigo_servico" name="codigo_servico" value="{{ old('codigo_servico', $contrato->codigo_servico) }}" inputmode="numeric" maxlength="6" placeholder="6 dígitos — confirmar com a contabilidade"></div>
    <div class="campo"><label for="valor_mensal">Valor mensal (R$)</label><input id="valor_mensal" name="valor_mensal" value="{{ old('valor_mensal', valor_campo($contrato->valor_mensal_centavos)) }}" inputmode="decimal" required placeholder="12.000,00"></div>
    <div class="campo"><label for="dia_vencimento">Dia de vencimento (mês seguinte)</label><input id="dia_vencimento" type="number" min="1" max="31" name="dia_vencimento" value="{{ old('dia_vencimento', $contrato->dia_vencimento) }}" required></div>
    <div class="campo"><label for="inicio">Início</label><input id="inicio" type="date" name="inicio" value="{{ old('inicio', $contrato->inicio?->toDateString()) }}" required></div>
    <div class="campo"><label for="fim">Fim (opcional)</label><input id="fim" type="date" name="fim" value="{{ old('fim', $contrato->fim?->toDateString()) }}"></div>
  </div>
  <button>Salvar</button>
</form>
@endsection
