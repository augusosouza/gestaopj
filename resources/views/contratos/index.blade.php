@extends('layouts.app')
@section('titulo', 'Contratos')
@section('conteudo')
<x-cabecalho :trilha="['Operação' => null, 'Contratos' => null]" titulo="Contratos" sub="Faturamento recorrente e custo dos prestadores alocados.">
  @if (ctx()->tem(\App\Enums\Papel::Admin))
    <x-slot:acoes><a class="botao" href="{{ route('contratos.create') }}">Novo contrato</a></x-slot:acoes>
  @endif
</x-cabecalho>
<div class="lista-barra"><p class="sub">{{ $contratos->count() }} contratos</p><x-densidade tabela="lista-contratos" /></div>
<div class="tabela" id="lista-contratos">
  <table>
    <thead><tr><th>Número</th><th>Cliente</th><th>Descrição</th><th class="n">Mensal</th><th class="n">Custo alocado</th><th class="n">Margem</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($contratos as $c)
      <tr>
        <td><a href="{{ route('contratos.show', $c) }}">{{ $c->numero }}</a></td>
        <td>{{ $c->cliente->razao_social }}</td>
        <td>{{ $c->descricao }}</td>
        <td class="n">{{ brl($c->valor_mensal_centavos) }}</td>
        <td class="n">{{ brl((int) $c->custo_centavos) }}</td>
        <td class="n">{{ brl($c->valor_mensal_centavos - (int) $c->custo_centavos) }}</td>
        <td><span class="tag {{ $c->status === 'ativo' ? 'ok' : 'warn' }}">{{ ucfirst($c->status) }}</span></td>
      </tr>
    @empty
      <tr><td colspan="7" class="vazio">Nenhum contrato cadastrado.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
<p class="sub" style="margin-top:8px">Custo alocado soma todas as alocações registradas (inclusive encerradas); o custo exato do mês aparece no fechamento.</p>
@endsection
