@extends('layouts.app')
@section('titulo', 'Fechamento '.$competencia->format('m/Y'))
@section('conteudo')
@php($u = auth()->user())
<div class="barra">
  <div>
    <h1>Fechamento de {{ $competencia->translatedFormat('F \d\e Y') }}</h1>
    <p class="sub" style="margin:0">Competência {{ $competencia->format('m/Y') }}</p>
  </div>
  <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
    @include('partials.competencia')
    @if ($u->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
      <form method="post" action="{{ route('competencias.gerar') }}">
        @csrf <input type="hidden" name="competencia" value="{{ $competencia->format('Y-m') }}">
        <button title="Cria faturas e cobranças dos contratos ativos. Pode ser repetido sem duplicar.">Gerar competência</button>
      </form>
    @endif
    <a class="botao sec" href="{{ route('fechamento.exportar', ['competencia' => $competencia->format('Y-m')]) }}">Exportar para contabilidade (.zip)</a>
  </div>
</div>

@if ($r['faturas']->isEmpty() && $r['cobrancas']->isEmpty())
  <div class="aviso info">Nenhuma fatura ou cobrança nesta competência. Use <strong>Gerar competência</strong> para abrir o mês a partir dos contratos ativos.</div>
@endif

<div class="cards">
  <div class="card"><div class="rot">1 · Quanto faturei</div><div class="num">{{ brl($r['faturado']) }}</div>
    <div class="det">{{ $r['faturas']->count() }} fatura(s) na competência</div></div>
  <div class="card"><div class="rot">2 · Quanto recebi</div><div class="num">{{ brl($r['recebido']) }}</div>
    <div class="det">Falta receber {{ brl($r['a_receber']) }}</div></div>
  <div class="card"><div class="rot">3 · Quanto devo aos prestadores</div><div class="num">{{ brl($r['devido_prestadores']) }}</div>
    <div class="det">{{ brl($r['devido_aprovado']) }} já aprovado · {{ brl($r['pago_prestadores']) }} pago</div></div>
  <div class="card"><div class="rot">4 · Notas pendentes</div><div class="num">{{ $r['pendencias']->count() }}</div>
    <div class="det">Emitir, receber ou conferir</div></div>
</div>

<h2>Pendências</h2>
<div class="tabela">
  <table>
    <thead><tr><th>Pendência</th><th>Quem</th><th>Contrato</th><th class="n">Valor</th><th></th></tr></thead>
    <tbody>
    @forelse ($r['pendencias'] as $p)
      <tr><td>{{ $p['tipo'] }}</td><td>{{ $p['quem'] }}</td><td>{{ $p['ref'] }}</td><td class="n">{{ brl($p['valor']) }}</td><td><a href="{{ $p['url'] }}">Abrir</a></td></tr>
    @empty
      <tr><td colspan="5" class="vazio">Nenhuma pendência de nota nesta competência.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>

<h2>Resultado por contrato</h2>
<div class="tabela">
  <table>
    <thead><tr><th>Contrato</th><th>Cliente</th><th class="n">Faturado</th><th class="n">Custo prestadores</th><th class="n">Margem</th><th class="n">%</th></tr></thead>
    <tbody>
    @forelse ($r['contratos'] as $c)
      <tr>
        <td><a href="{{ route('contratos.show', $c['contrato']) }}">{{ $c['contrato']->numero }}</a></td>
        <td>{{ $c['contrato']->cliente->razao_social }}</td>
        <td class="n">{{ brl($c['faturado']) }}</td><td class="n">{{ brl($c['custo']) }}</td>
        <td class="n">{{ brl($c['margem']) }}</td><td class="n">{{ $c['margem_pct'] !== null ? number_format($c['margem_pct'], 1, ',', '.').'%' : '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="vazio">Sem faturas nesta competência.</td></tr>
    @endforelse
    </tbody>
    @if ($r['contratos']->count() > 1)
      <tfoot><tr><th colspan="2">Total</th><th class="n">{{ brl($r['faturado']) }}</th><th class="n">{{ brl($r['custo_total']) }}</th><th class="n">{{ brl($r['faturado'] - $r['custo_total']) }}</th><th></th></tr></tfoot>
    @endif
  </table>
</div>
<p class="sub" style="margin-top:8px">Margem bruta antes de tributos sobre o faturamento. O cálculo de IBS/CBS por regime entra na fase 3.</p>
@endsection
