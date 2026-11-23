@extends('layouts.app')
@section('titulo', $r['titulo'])
@section('larga', '1')
@section('conteudo')
@php($q = ['de' => $de->format('Y-m'), 'ate' => $ate->format('Y-m'), 'regime' => $regime])
@php($fmt = fn ($v, $tipo) => $v === null ? '' : match ($tipo) { 'dinheiro' => brl((int) $v), 'pct' => number_format((float) $v, $v == (int) $v ? 0 : 1, ',', '.').'%', 'numero' => (string) $v, default => (string) $v })
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Relatórios' => route('relatorios.index'), $r['titulo'] => null]" :titulo="$r['titulo']" :sub="$r['descricao']">
  <x-slot:acoes>
    <a class="botao sec" href="{{ route('relatorios.exportar', [$slug] + $q + ['formato' => 'xlsx']) }}">Excel</a>
    <a class="botao sec" href="{{ route('relatorios.exportar', [$slug] + $q + ['formato' => 'csv']) }}">CSV</a>
  </x-slot:acoes>
</x-cabecalho>
<form method="get" class="filtros-barra">
  <label for="r-de" class="sub" style="margin:0">De</label><input id="r-de" type="month" name="de" value="{{ $de->format('Y-m') }}" style="width:auto">
  <label for="r-ate" class="sub" style="margin:0">até</label><input id="r-ate" type="month" name="ate" value="{{ $ate->format('Y-m') }}" style="width:auto">
  @if (in_array($slug, ['dre', 'despesas-categoria', 'receitas-categoria'], true))
    <select name="regime" aria-label="Regime" style="width:auto"><option value="competencia" @selected($regime === 'competencia')>Por competência</option><option value="caixa" @selected($regime === 'caixa')>Por caixa (pago/recebido)</option></select>
  @endif
  <button class="sec">Aplicar</button>
</form>

@if ($slug === 'dre')
  @php($e = $r['estrutura'])
  <div class="aviso info">Relatório de gestão {{ $regime === 'caixa' ? 'pelo regime de caixa' : 'por competência' }}. <strong>Não substitui a DRE contábil</strong> elaborada pelo contador. A linha de cada categoria é definida em <a href="{{ route('financeiro.categorias') }}">Categorias</a>.</div>
  <section class="cards" aria-label="Resumo">
    @php($final = collect($e['dre'])->firstWhere('tipo', 'final'))
    <div class="card serie-receita"><div class="rot">Receita líquida</div><div class="num">{{ brl($e['receita_liquida']) }}</div><div class="det">{{ $de->format('m/Y') }} a {{ $ate->format('m/Y') }}</div></div>
    <div class="card serie-saldo"><div class="rot">Resultado líquido</div><div class="num" @if ($final['total'] < 0) style="color:var(--bad)" @endif>{{ brl($final['total']) }}</div><div class="det">no período</div></div>
    <div class="card serie-comparativo"><div class="rot">Margem líquida</div><div class="num">{{ $e['margem'] === null ? '—' : $e['margem'].'%' }}</div><div class="det">resultado ÷ receita líquida</div></div>
  </section>
  <div class="tabela" style="margin-top:16px"><table class="dre">
    <thead><tr><th>DRE gerencial</th>@foreach ($e['meses'] as $m)<th class="n">{{ $m->format('m/Y') }}</th>@endforeach<th class="n">Total</th><th class="n">% RL</th></tr></thead>
    <tbody>
    @foreach ($e['dre'] as $l)
      <tr class="dre-{{ $l['tipo'] }}"><td @if ($l['nivel']) style="padding-left:28px" @endif>{{ $l['rotulo'] }}</td>
        @foreach ($l['valores'] as $v)<td class="n" @if ($v < 0) style="color:var(--bad)" @endif>{{ $v ? brl($v) : '—' }}</td>@endforeach
        <td class="n" @if ($l['total'] < 0) style="color:var(--bad)" @endif>{{ brl($l['total']) }}</td>
        <td class="n">{{ $e['receita_liquida'] ? number_format($l['total'] * 100 / $e['receita_liquida'], 1, ',', '.').'%' : '—' }}</td></tr>
    @endforeach
    </tbody>
  </table></div>
  @php($res = collect($e['dre'])->firstWhere('tipo', 'final'))
  <x-grafico.linha titulo="Resultado líquido por mês" serie="saldo" style="margin-top:16px" :rotulos="12"
    :pontos="collect($e['meses'])->map(fn ($m, $i) => ['rotulo' => $m->format('m/y'), 'valor' => $res['valores'][$i], 'dica' => $m->format('m/Y').': '.brl($res['valores'][$i])])->all()"
    :descricao="'Resultado líquido mês a mês, total '.brl($res['total'])" />
@else
  @if ($r['grafico'])
    @if ($r['grafico']['tipo'] === 'colunas')
      <x-grafico.colunas :titulo="$r['titulo']" :periodos="$r['grafico']['periodos']" :series="$r['grafico']['series']" style="margin-bottom:16px" />
    @else
      <x-grafico.barras :titulo="$r['titulo']" :serie="$r['grafico']['serie']" :itens="$r['grafico']['itens']" vazio="Sem dados no período." style="margin-bottom:16px" />
    @endif
  @endif
  <div class="tabela"><table>
    <thead><tr>@foreach ($r['colunas'] as [$rot, $tipo])<th @class(['n' => $tipo !== 'texto'])>{{ $rot }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($r['linhas'] as $l)
      <tr>@foreach ($l as $i => $v)<td @class(['n' => $r['colunas'][$i][1] !== 'texto']) @if ($r['colunas'][$i][1] === 'dinheiro' && is_int($v) && $v < 0) style="color:var(--bad)" @endif>{{ $fmt($v, $r['colunas'][$i][1]) }}</td>@endforeach</tr>
    @empty
      <tr><td colspan="{{ count($r['colunas']) }}" class="vazio">Sem dados no período.</td></tr>
    @endforelse
    </tbody>
    @if ($r['total'] && count($r['linhas']))<tfoot><tr>@foreach ($r['total'] as $i => $v)<td @class(['n' => $r['colunas'][$i][1] !== 'texto'])><strong>{{ $fmt($v, $r['colunas'][$i][1]) }}</strong></td>@endforeach</tr></tfoot>@endif
  </table></div>
@endif
@endsection
