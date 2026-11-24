@extends('layouts.app')
@section('titulo', 'Histórico do cliente')
@section('conteudo')
<x-cabecalho :trilha="['Clientes' => route('clientes.index'), $cliente->razao_social => route('clientes.show', $cliente), 'Histórico' => null]"
  titulo="Histórico do cliente" :sub="$cliente->razao_social" />
<nav class="acoes" aria-label="Filtrar histórico">
  <a class="btn sec" href="{{ route('clientes.historico', $cliente) }}" @if (!$filtro) aria-current="page" @endif>Todas as atividades</a>
  <a class="btn sec" href="{{ route('clientes.historico', [$cliente, 'tipo' => 'comunicacao']) }}" @if ($filtro) aria-current="page" @endif>Comunicações de faturas</a>
</nav>
<div class="tabela"><table>
  <thead><tr><th scope="col">Data e hora</th><th scope="col">Responsável</th><th scope="col">Ação</th><th scope="col">Detalhes</th></tr></thead>
  <tbody>
  @forelse ($eventos as $evento)
    <tr>
      <td>{{ $evento->created_at->format('d/m/Y H:i:s') }}</td>
      <td>{{ $evento->user_id ? ($evento->user?->name ?? 'Usuário indisponível') : 'Sistema ou usuário removido' }}</td>
      <td>{{ $evento->rotulo() }}</td>
      <td>
        @if ($evento->alvo_type === (new \App\Models\Fatura)->getMorphClass())
          <a href="{{ route('faturas.show', $evento->alvo_id) }}">Fatura #{{ $evento->alvo_id }}</a>
        @elseif ($evento->alvo_type === (new \App\Models\Titulo)->getMorphClass())
          <a href="{{ route('financeiro.titulo', $evento->alvo_id) }}">Título #{{ $evento->alvo_id }}</a>
        @elseif (isset($evento->dados['registro']))
          {{ ucfirst($evento->dados['tipo'] ?? 'Contrato') }} #{{ $evento->dados['registro'] }}
        @endif
        @include('faturas.motivo-envio')
        @if (! empty($evento->dados['campos']))
          <div>Campos: {{ implode(', ', $evento->dados['campos']) }}</div>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="4" class="vazio">Nenhum evento registrado para este cliente.</td></tr>
  @endforelse
  </tbody>
</table></div>
{{ $eventos->links() }}
@endsection
