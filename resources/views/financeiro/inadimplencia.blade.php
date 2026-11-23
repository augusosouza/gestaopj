@extends('layouts.app')
@section('titulo', 'Inadimplência')
@section('conteudo')
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Recebimentos' => route('financeiro.index', ['tipo' => 'receber']), 'Inadimplência' => null]"
  titulo="Inadimplência" sub="Parcelas a receber vencidas e com saldo em aberto, por cliente e faixa de atraso.">
  <x-slot:acoes>@include('partials.exportar', ['relatorio' => 'inadimplencia'])</x-slot:acoes>
</x-cabecalho>
<x-abas :itens="[
  ['Em aberto', route('financeiro.index', ['tipo' => 'receber', 'situacao' => 'aberto', 'periodo' => 'todos']), false],
  ['Parciais', route('financeiro.index', ['tipo' => 'receber', 'situacao' => 'parcial', 'periodo' => 'todos']), false],
  ['Vencidos', route('financeiro.inadimplencia'), true, $qtdClientes ?: null],
  ['Recebidos', route('financeiro.index', ['tipo' => 'receber', 'situacao' => 'liquidado']), false],
]" />

<section class="cards" aria-label="Resumo">
  <div class="card"><div class="rot">Total vencido</div><div class="num">{{ brl($total) }}</div><div class="det">{{ $qtdClientes }} cliente(s)</div></div>
  @foreach (['1-7' => '1 a 7 dias', '8-15' => '8 a 15 dias', '16-30' => '16 a 30 dias'] as $k => $rot)
    <div class="card"><div class="rot">{{ $rot }}</div><div class="num">{{ brl($porFaixa[$k]['valor']) }}</div><div class="det">{{ $porFaixa[$k]['qtd'] }} parcela(s)</div></div>
  @endforeach
</section>
<section class="cards" aria-label="Atrasos longos" style="margin-top:12px;grid-template-columns:repeat(2,minmax(0,1fr))">
  @foreach (['31-60' => '31 a 60 dias', '60+' => 'Mais de 60 dias'] as $k => $rot)
    <div class="card"><div class="rot">{{ $rot }}</div><div class="num">{{ brl($porFaixa[$k]['valor']) }}</div><div class="det">{{ $porFaixa[$k]['qtd'] }} parcela(s)</div></div>
  @endforeach
</section>

<form method="get" class="filtros-barra" style="margin-top:16px">
  <nav class="filtros" aria-label="Faixa de atraso" style="margin:0">
    <a href="{{ request()->fullUrlWithQuery(['faixa' => null]) }}" @class(['ativo' => ! $faixa])>Todas</a>
    @foreach (array_keys(\App\Services\Recebimentos::FAIXAS) as $k)
      <a href="{{ request()->fullUrlWithQuery(['faixa' => $k]) }}" @class(['ativo' => $faixa === $k])>{{ $k === '60+' ? '+60' : $k }} dias</a>
    @endforeach
  </nav>
  @if ($faixa)<input type="hidden" name="faixa" value="{{ $faixa }}">@endif
  <input name="cliente" value="{{ $cliente }}" placeholder="Buscar cliente" aria-label="Buscar cliente" style="max-width:240px"><button class="sec">Filtrar</button>
</form>

@forelse ($clientes as $c)
  <details class="painel cliente-atraso" @if ($loop->first) open @endif style="margin-top:12px">
    <summary><strong>{{ $c['cliente'] }}</strong>
      <span class="sub">· {{ brl($c['total']) }} · {{ $c['qtd'] }} parcela(s) · maior atraso {{ $c['maior_atraso'] }} dia(s)</span>
      @if ($c['recorrente'])<span class="tag warn">atrasa com frequência</span>@endif</summary>
    <div class="tabela" style="margin-top:10px"><table>
      <thead><tr><th>Lançamento</th><th>Parcela</th><th>Vencimento</th><th class="n">Dias</th><th class="n">Original</th><th class="n">Em aberto</th><th></th></tr></thead>
      <tbody>
      @foreach ($c['itens'] as $v)
        @php($pa = $v['parcela'])
        <tr><td><a href="{{ route('financeiro.titulo', $pa->titulo) }}">{{ $pa->titulo->descricao }}</a></td>
          <td>{{ $pa->numero }}/{{ $pa->titulo->parcelas_count }}@if ($v['parcial']) <span class="tag info">parcial</span>@endif</td>
          <td>{{ $pa->vencimento->format('d/m/Y') }}</td><td class="n">{{ $v['dias'] }}</td>
          <td class="n">{{ brl($pa->valor_centavos) }}</td><td class="n">{{ brl($v['saldo']) }}</td>
          <td><a class="botao sec" href="{{ route('financeiro.titulo', [$pa->titulo, 'aba' => 'pagamentos']) }}#registrar">Registrar recebimento</a></td></tr>
      @endforeach
      </tbody>
    </table></div>
  </details>
@empty
  <p class="painel vazio" style="margin-top:12px">Nenhum recebimento vencido{{ $faixa || $cliente ? ' com esse filtro' : '' }}.</p>
@endforelse
<p class="sub" style="font-size:12.5px;margin-top:10px">"Atrasa com frequência": os três últimos vencimentos do cliente foram pagos depois da data ou seguem em aberto. Juros e multa por atraso são registrados como acréscimo no recebimento.</p>
@endsection
