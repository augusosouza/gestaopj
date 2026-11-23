@extends('layouts.app')
@section('titulo', 'Financeiro')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<div class="barra">
  <div><h1>Financeiro</h1><p class="sub">Previsto x realizado por competência. Vencimento e data de pagamento ficam em cada parcela.</p></div>
  <div class="acoes">
    @include('partials.competencia')
    @if ($opera)
      <a class="botao sec" href="{{ route('financeiro.novo', ['tipo' => 'receber']) }}">Nova receita</a>
      <a class="botao" href="{{ route('financeiro.novo', ['tipo' => 'pagar']) }}">Nova despesa</a>
    @endif
  </div>
</div>
@include('financeiro._abas')

<section class="cards" aria-label="Resumo financeiro">
  <div class="card"><div class="rot">Receitas da competência</div><div class="num">{{ brl($p['receitas_realizadas']) }}</div><div class="det">recebido de {{ brl($p['receitas_previstas']) }} previsto</div></div>
  <div class="card"><div class="rot">Despesas da competência</div><div class="num">{{ brl($p['despesas_realizadas']) }}</div><div class="det">pago de {{ brl($p['despesas_previstas']) }} previsto</div></div>
  <div class="card"><div class="rot">Vencidos (todas as competências)</div><div class="num">{{ brl($p['vencido_receber'] + $p['vencido_pagar']) }}</div><div class="det">{{ brl($p['vencido_receber']) }} a receber · {{ brl($p['vencido_pagar']) }} a pagar</div></div>
  <div class="card"><div class="rot">Saldo em contas</div><div class="num">{{ brl($p['saldo_contas']) }}</div><div class="det">{{ $p['nao_conciliadas'] }} linha(s) de extrato a conciliar</div></div>
</section>

<nav class="filtros" aria-label="Filtrar lançamentos">
  @foreach (['' => 'Todos', 'receber' => 'A receber', 'pagar' => 'A pagar'] as $k => $r)
    <a href="{{ request()->fullUrlWithQuery(['tipo' => $k ?: null]) }}" @class(['ativo' => ($tipo ?? '') === $k])>{{ $r }}</a>
  @endforeach
  <span style="width:12px"></span>
  @foreach (['' => 'Qualquer situação', 'aberto' => 'Em aberto', 'parcial' => 'Parcial', 'vencido' => 'Vencidos', 'liquidado' => 'Liquidados', 'cancelado' => 'Cancelados'] as $k => $r)
    <a href="{{ request()->fullUrlWithQuery(['situacao' => $k ?: null]) }}" @class(['ativo' => ($situacao ?? '') === $k])>{{ $r }}</a>
  @endforeach
</nav>

<div class="tabela"><table>
  <thead><tr><th>Lançamento</th><th>Categoria</th><th>Vencimento</th><th class="n">Valor</th><th class="n">Saldo</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  @forelse ($titulos as $t)
    <tr>
      <td><span class="tag rotulo">{{ $t->receber() ? 'Receber' : 'Pagar' }}</span> {{ $t->descricao }}
        @if ($t->contraparte)<br><span class="sub" style="font-size:12.5px">{{ $t->contraparte }}</span>@endif</td>
      <td>{{ $t->categoria?->nome ?? '—' }}</td>
      <td>{{ $t->parcelas->first()?->vencimento->format('d/m/Y') }}@if ($t->parcelas->count() > 1)<span class="sub" style="font-size:12.5px"> · {{ $t->parcelas->count() }} parcelas</span>@endif</td>
      <td class="n">{{ brl($t->valor_total_centavos) }}</td>
      <td class="n">{{ $t->cancelado() ? '—' : brl($t->saldo()) }}</td>
      <td>@include('financeiro._situacao')</td>
      <td><a href="{{ route('financeiro.titulo', $t) }}">Abrir</a></td>
    </tr>
  @empty
    <tr><td colspan="7" class="vazio">Nenhum lançamento nesta competência.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
