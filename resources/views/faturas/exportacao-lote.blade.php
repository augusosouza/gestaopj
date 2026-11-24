@extends('layouts.app')
@section('titulo', 'Conferir exportação de faturas')
@section('conteudo')
<x-cabecalho :trilha="['Faturas' => route('faturas.index'), 'Conferir exportação' => null]" titulo="Conferir exportação de faturas"
  sub="A confirmação gera um arquivo XLSX com os dados exibidos." />
<div class="painel">
  <p><strong>{{ $faturas->count() }} faturas disponíveis</strong> · {{ $bloqueadas }} indisponíveis</p>
  @if ($bloqueadas)<p class="sub">Os registros indisponíveis foram removidos ou estão fora do seu acesso.</p>@endif
  @if ($confirmacao)
    <div class="tabela"><table>
      <thead><tr><th>Fatura</th><th>Contrato</th><th>Cliente</th><th>Competência</th><th>Vencimento</th><th>Situação</th><th class="n">Valor</th></tr></thead>
      <tbody>@foreach ($faturas as $f)
        <tr><td>#{{ $f->id }}</td><td>{{ $f->contrato->numero }}</td><td>{{ $f->contrato->cliente->razao_social }}</td>
          <td>{{ $f->competencia->format('m/Y') }}</td><td>{{ $f->vencimento->format('d/m/Y') }}</td><td>{{ $f->status->rotulo() }}</td><td class="n">{{ brl($f->valor_centavos) }}</td></tr>
      @endforeach</tbody>
      <tfoot><tr><th colspan="6">Total das faturas disponíveis</th><th class="n">{{ brl($faturas->sum('valor_centavos')) }}</th></tr></tfoot>
    </table></div>
    <form method="post" action="{{ route('faturas.lote.exportar') }}" class="formulario-rodape">
      @csrf <input type="hidden" name="confirmacao" value="{{ $confirmacao }}">
      <a href="{{ route('faturas.index') }}">Cancelar</a><button>Confirmar e baixar XLSX</button>
    </form>
  @else
    <a href="{{ route('faturas.index') }}">Voltar para Faturas</a>
  @endif
</div>
@endsection
