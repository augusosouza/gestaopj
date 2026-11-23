@extends('layouts.app')
@section('titulo', 'Compras')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@php($url = fn ($a) => route('compras.index', array_filter(['aba' => $a, 'q' => $busca ?: null])))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Compras' => null]" titulo="Compras"
  sub="Aquisições de produtos e serviços, com parcelas, cartão, notas e garantia. Cada compra gera as parcelas a pagar — sem duplicar.">
  <x-slot:acoes>@if ($opera)<a class="botao" href="{{ route('compras.create') }}">Nova compra</a>@endif</x-slot:acoes>
</x-cabecalho>
<x-abas :itens="[
  ['Resumo', $url('resumo'), $aba === 'resumo'],
  ['Todas as compras', $url('todas'), $aba === 'todas', $compras->count()],
  ['Parcelamentos', $url('parcelamentos'), $aba === 'parcelamentos', $resumo['parceladas'] ?: null],
]" />

@if ($aba === 'resumo')
  <section class="cards" aria-label="Resumo de compras">
    <div class="card"><div class="rot">Compras do mês</div><div class="num">{{ brl($resumo['mes']) }}</div><div class="det">{{ $resumo['qtd_mes'] }} compra(s)</div></div>
    <div class="card"><div class="rot">Já pago</div><div class="num">{{ brl($resumo['pago']) }}</div><div class="det">todas as compras ativas</div></div>
    <div class="card"><div class="rot">Restante a pagar</div><div class="num">{{ brl($resumo['restante']) }}</div><div class="det">parcelas e faturas futuras</div></div>
    <div class="card"><div class="rot">Parcelamentos ativos</div><div class="num">{{ $resumo['parceladas'] }}</div><div class="det"><a href="{{ $url('parcelamentos') }}">ver progresso</a></div></div>
  </section>
  <h2>Últimas compras</h2>
  @include('compras._tabela', ['lista' => $compras->take(10)])
@elseif ($aba === 'todas')
  <form method="get" class="filtros-barra"><input type="hidden" name="aba" value="todas">
    <input name="q" value="{{ $busca }}" placeholder="Descrição, fornecedor ou nº da nota" aria-label="Buscar" style="max-width:320px"><button class="sec">Buscar</button></form>
  @include('compras._tabela', ['lista' => $compras])
@else
  <div class="tabela"><table>
    <thead><tr><th>Compra</th><th>Forma</th><th>Progresso</th><th class="n">Pago</th><th class="n">Restante</th><th>Próxima parcela</th></tr></thead>
    <tbody>
    @forelse ($parceladas as $c)
      @php($t = $c->titulo)
      @php($pagas = $t->parcelas->filter(fn ($p) => $p->saldo() === 0)->count())
      @php($prox = $t->parcelas->first(fn ($p) => $p->saldo() > 0))
      <tr><td><a href="{{ route('compras.show', $c) }}">{{ $c->descricao }}</a><br><span class="sub" style="font-size:12.5px">{{ $c->fornecedor ?? '—' }} · {{ $c->data_compra->format('d/m/Y') }}</span></td>
        <td>{{ $c->noCartao() ? $c->cartao?->rotulo() : \App\Models\Compra::MODALIDADES[$c->modalidade] }}</td>
        <td style="min-width:160px">{{ $pagas }} de {{ $t->parcelas->count() }} parcelas
          <div class="progresso serie-despesa" role="img" aria-label="{{ $pagas }} de {{ $t->parcelas->count() }} pagas"><span style="width:{{ round($t->liquidado() * 100 / max(1, $t->valor_total_centavos)) }}%"></span></div></td>
        <td class="n">{{ brl($t->liquidado()) }}</td><td class="n">{{ brl($t->saldo()) }}</td>
        <td>{{ $prox ? $prox->vencimento->format('d/m/Y').' · '.brl($prox->saldo()) : 'quitada' }}</td></tr>
    @empty
      <tr><td colspan="6" class="vazio">Nenhuma compra parcelada.</td></tr>
    @endforelse
    </tbody>
  </table></div>
@endif
@endsection
