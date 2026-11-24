@extends('layouts.app')
@section('titulo', $cliente->razao_social)
@section('conteudo')
<x-cabecalho :trilha="['Clientes' => route('clientes.index'), 'Ficha do cliente' => null]"
  :titulo="$cliente->razao_social" :sub="$cliente->nome_fantasia">
  <x-slot:acoes><a class="btn" href="{{ route('clientes.edit', $cliente) }}">Editar cliente</a></x-slot:acoes>
</x-cabecalho>
<section class="painel" aria-labelledby="dados-cliente">
  <h2 id="dados-cliente">Dados e contato</h2>
  <dl class="grade">
    <div><dt>CPF/CNPJ</dt><dd>{{ doc($cliente->documento) }}</dd></div>
    <div><dt>E-mail de cobrança</dt><dd>{{ $cliente->email ?? '—' }}</dd></div>
    <div><dt>Telefone</dt><dd>{{ $cliente->telefone ?? '—' }}</dd></div>
    <div><dt>Código IBGE do município</dt><dd>{{ $cliente->municipio_ibge ?? '—' }}</dd></div>
  </dl>
</section>
<section class="painel" aria-labelledby="contratos-cliente">
  <h2 id="contratos-cliente">Contratos ({{ $contratos->total() }})</h2>
  <div class="tabela"><table>
    <thead><tr><th scope="col">Contrato</th><th scope="col">Situação</th><th scope="col" class="n">Valor mensal</th></tr></thead>
    <tbody>
    @forelse ($contratos as $contrato)
      <tr><td><a href="{{ route('contratos.show', $contrato) }}">{{ $contrato->numero }} · {{ $contrato->descricao }}</a></td>
        <td>{{ ucfirst($contrato->status) }}</td><td class="n">{{ brl($contrato->valor_mensal_centavos) }}</td></tr>
    @empty
      <tr><td colspan="3" class="vazio">Nenhum contrato associado.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $contratos->links() }}
</section>
<section class="painel" aria-labelledby="faturas-cliente">
  <h2 id="faturas-cliente">Faturas ({{ $faturas->total() }})</h2>
  <div class="tabela"><table>
    <thead><tr><th scope="col">Fatura</th><th scope="col">Vencimento</th><th scope="col">Situação</th><th scope="col" class="n">Valor</th></tr></thead>
    <tbody>
    @forelse ($faturas as $fatura)
      <tr><td><a href="{{ route('faturas.show', $fatura) }}">#{{ $fatura->id }} · {{ $fatura->competencia->format('m/Y') }}</a> · {{ $fatura->contrato->numero }}</td>
        <td>{{ $fatura->vencimento->format('d/m/Y') }}</td><td>{{ $fatura->status->rotulo() }}</td><td class="n">{{ brl($fatura->valor_centavos) }}</td></tr>
    @empty
      <tr><td colspan="4" class="vazio">Nenhuma fatura nos contratos deste cliente.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $faturas->links() }}
</section>
@endsection
