@extends('layouts.app')
@section('titulo', 'Governança')
@section('conteudo')
<x-cabecalho :trilha="['Administração' => null, 'Governança' => null]" titulo="Governança"
  :sub="'Histórico de mudanças registradas em '.ctx()->empresa()->razao_social" />
<section class="painel ajustes-painel">
  <form method="get" action="{{ route('governanca.index') }}" class="ajustes-filtros">
    <div class="campo"><label for="tipo">Tipo de alteração</label><select id="tipo" name="tipo">
      <option value="">Todos</option>
      @foreach ($tipos as $codigo => [$rotulo])
        <option value="{{ $codigo }}" @selected($filtro === $codigo)>{{ $rotulo }}</option>
      @endforeach
    </select></div>
    <button class="sec">Filtrar</button>
  </form>
  <div class="tabela"><table>
    <thead><tr><th scope="col">Quando</th><th scope="col">Alteração</th><th scope="col">Responsável</th></tr></thead>
    <tbody>
      @forelse ($eventos as $evento)
        <tr><td>{{ $evento->created_at->format('d/m/Y H:i') }}</td><td>
          {{ $rotulos[$evento->acao] }}
          @if (str_starts_with($evento->acao, 'pix.alteracao_'))
            · <a href="{{ route('prestadores.edit', $evento->alvo_id) }}">Abrir prestador</a>
          @elseif (isset(\App\Services\EnvioFatura::ROTULOS[$evento->acao]))
            · <a href="{{ route('faturas.show', $evento->alvo_id) }}">Fatura #{{ $evento->alvo_id }}</a>
            · <a href="{{ route('clientes.historico', [$evento->dados['cliente_id'], 'tipo' => 'comunicacao']) }}">Histórico do cliente</a>
            @include('faturas.motivo-envio')
          @endif
        </td><td>{{ $evento->user?->name ?? 'Sistema' }}</td></tr>
      @empty
        <tr><td colspan="3" class="vazio">Nenhuma alteração encontrada neste filtro.</td></tr>
      @endforelse
    </tbody>
  </table></div>
  {{ $eventos->links() }}
</section>
@endsection
