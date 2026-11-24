@extends('layouts.app')
@section('titulo', 'Conferir faturamento')
@section('conteudo')
<x-cabecalho :trilha="['Fechamento' => route('painel', ['competencia' => $competencia->format('Y-m')]), 'Conferir faturamento' => null]"
  titulo="Conferir faturamento" :sub="'Competência '.$competencia->format('m/Y')" />
<div class="ficha-secoes">
  <section class="cards" aria-label="Registros previstos">
    <div class="card"><div class="rot">Contratos vigentes</div><div class="num">{{ $previa->count() }}</div></div>
    <div class="card"><div class="rot">Novas faturas</div><div class="num">{{ $novas->count() }}</div><div class="det">{{ brl($novas->sum('contrato.valor_mensal_centavos')) }}</div></div>
    <div class="card"><div class="rot">Novas obrigações com prestadores</div><div class="num">{{ $cobrancas->count() }}</div><div class="det">{{ brl($cobrancas->sum('alocacao.valor_mensal_centavos')) }}</div></div>
  </section>
  <div class="tabela"><table>
    <thead><tr><th>Contrato</th><th>Cliente</th><th>Fatura</th><th>Obrigações com prestadores</th></tr></thead>
    <tbody>
      @forelse ($previa as $item)
        <tr>
          <td><a href="{{ route('contratos.show', $item['contrato']) }}">{{ $item['contrato']->numero }}</a></td>
          <td>{{ $item['contrato']->cliente?->razao_social ?? 'Indisponível' }}</td>
          <td>
            @if ($item['problema']) <span class="tag bad">{{ $item['problema'] }}</span>
            @elseif ($item['fatura']) <a href="{{ route('faturas.show', $item['fatura']) }}">Fatura já gerada</a>
            @else {{ brl($item['contrato']->valor_mensal_centavos) }} · {{ \Carbon\CarbonImmutable::parse($item['vencimento'])->format('d/m/Y') }}
            @endif
          </td>
          <td>
            @forelse ($item['alocacoes'] as $a)
              <div>{{ $a['alocacao']->prestador?->nome ?? 'Prestador indisponível' }} ·
                @if ($a['existente']) <a href="{{ route('cobrancas.show', $a['existente']) }}">Já registrada</a>
                @elseif ($item['problema'] || $a['problema']) {{ $a['problema'] ?? 'Contrato com pendência.' }}
                @else {{ brl($a['alocacao']->valor_mensal_centavos) }}
                @endif
              </div>
            @empty —
            @endforelse
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="vazio">Nenhum contrato vigente nesta competência.</td></tr>
      @endforelse
    </tbody>
  </table></div>
  @if ($novas->isNotEmpty() || $cobrancas->isNotEmpty())
    <form method="post" action="{{ route('competencias.gerar') }}" class="formulario-rodape" data-evitar-reenvio="Gerando…">
      @csrf <input type="hidden" name="competencia" value="{{ $competencia->format('Y-m') }}">
      <input type="hidden" name="revisao" value="{{ $revisao }}">
      <a href="{{ route('painel', ['competencia' => $competencia->format('Y-m')]) }}">Voltar</a>
      <button>Confirmar geração da competência</button>
    </form>
  @endif
</div>
@endsection
