@extends('layouts.app')
@section('titulo', 'Fechamento '.$competencia->format('m/Y'))
@section('conteudo')
<div class="barra">
  <div>
    <h1>Fechamento do mês</h1>
    <p class="sub">Competência {{ $competencia->format('m/Y') }} · {{ ctx()->empresa()->razao_social }}</p>
  </div>
  <div class="acoes">
    @include('partials.competencia')
    <a class="botao sec" href="{{ route('fechamento.exportar', ['competencia' => $competencia->format('Y-m')]) }}">Exportar .zip</a>
    @if (ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
      <form method="post" action="{{ route('competencias.gerar') }}">
        @csrf <input type="hidden" name="competencia" value="{{ $competencia->format('Y-m') }}">
        <button title="Cria faturas e cobranças dos contratos ativos. Pode ser repetido sem duplicar.">Gerar competência</button>
      </form>
    @endif
  </div>
</div>

@if ($r['faturas']->isEmpty() && $r['cobrancas']->isEmpty())
  <div class="aviso info">Nenhuma fatura ou cobrança neste mês. Use <strong>Gerar competência</strong> para abrir o mês a partir dos contratos ativos.</div>
@endif

@php($moeda = fn ($c) => new \Illuminate\Support\HtmlString('<small>R$</small>'.e(number_format($c / 100, 2, ',', '.'))))
<section class="cards" aria-label="Resumo do mês">
  <div class="card"><div class="rot">Faturado</div><div class="num">{{ $moeda($r['faturado']) }}</div>
    <div class="det">{{ $r['faturas']->count() }} {{ $r['faturas']->count() === 1 ? 'fatura' : 'faturas' }}</div></div>
  <div class="card destaque"><div class="rot">Recebido</div><div class="num">{{ $moeda($r['recebido']) }}</div>
    <div class="det">falta receber {{ brl($r['a_receber']) }}</div></div>
  <div class="card"><div class="rot">A pagar a prestadores</div><div class="num">{{ $moeda($r['devido_prestadores']) }}</div>
    <div class="det">{{ brl($r['devido_aprovado']) }} aprovado · {{ brl($r['pago_prestadores']) }} pago</div></div>
  <div class="card"><div class="rot">Notas pendentes</div><div class="num">{{ $r['pendencias']->count() }}</div>
    <div class="det">emitir, receber ou conferir</div></div>
</section>

<section class="cards faixa-fin" aria-label="Financeiro" style="margin-top:12px">
  <div class="card"><div class="rot">Saldo em contas</div><div class="num">{{ brl($fin['saldo_contas']) }}</div><div class="det"><a href="{{ route('financeiro.contas') }}">ver contas</a></div></div>
  <div class="card"><div class="rot">Outras despesas do mês</div><div class="num">{{ brl($fin['despesas_previstas'] - $r['custo_total']) }}</div><div class="det">além dos prestadores · <a href="{{ route('financeiro.index', ['competencia' => $competencia->format('Y-m'), 'tipo' => 'pagar']) }}">ver</a></div></div>
  <div class="card"><div class="rot">Vencidos</div><div class="num">{{ brl($fin['vencido_receber'] + $fin['vencido_pagar']) }}</div><div class="det">{{ brl($fin['vencido_receber']) }} a receber · {{ brl($fin['vencido_pagar']) }} a pagar</div></div>
  <div class="card"><div class="rot">Extrato a conciliar</div><div class="num">{{ $fin['nao_conciliadas'] }}</div><div class="det">linha(s) importada(s)</div></div>
</section>

<h2>Pendências</h2>
<div class="tabela">
  <table>
    <thead><tr><th>Pendência</th><th>Quem</th><th>Contrato</th><th class="n">Valor</th><th></th></tr></thead>
    <tbody>
    @forelse ($r['pendencias'] as $p)
      <tr><td><span class="tag warn">{{ $p['tipo'] }}</span></td><td>{{ $p['quem'] }}</td><td>{{ $p['ref'] }}</td><td class="n">{{ brl($p['valor']) }}</td><td><a href="{{ $p['url'] }}">Abrir</a></td></tr>
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
<p class="sub" style="margin-top:10px;font-size:13px">Margem bruta antes de tributos sobre o faturamento. O cálculo de IBS/CBS por regime entra na fase 3.</p>
@endsection
