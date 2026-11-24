@extends('layouts.app')
@section('titulo', 'Painel financeiro')
@section('larga', '1')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@php($pct = fn ($a, $b) => $b > 0 ? min(100, round($a * 100 / $b)) : 0)
<x-cabecalho :trilha="['Financeiro' => null, 'Painel financeiro' => null]" titulo="Painel financeiro"
  :sub="$aba === 'fluxo' ? 'Projeção pelas parcelas em aberto, a partir do saldo de hoje.' : 'Competência '.$competencia->translatedFormat('F \d\e Y').'.'">
  <x-slot:acoes>
    @if ($aba !== 'fluxo')@include('partials.competencia')@endif
    @if ($opera)<a class="botao" href="{{ route('financeiro.novo') }}">+ Novo lançamento</a>@endif
  </x-slot:acoes>
</x-cabecalho>
<x-abas :itens="[
  ['Resumo', route('financeiro.painel', ['competencia' => $competencia->format('Y-m')]), $aba === 'resumo'],
  ['Fluxo de caixa', route('financeiro.painel', ['aba' => 'fluxo']), $aba === 'fluxo'],
  ['Despesas', route('financeiro.painel', ['aba' => 'despesas', 'competencia' => $competencia->format('Y-m')]), $aba === 'despesas'],
]" />

@if ($aba === 'resumo')
  <section class="cards" aria-label="Indicadores da competência">
    <div class="card serie-saldo"><div class="rot">Saldo nas contas</div><div class="num">{{ brl($p['saldo_contas']) }}</div><div class="det">hoje · <a href="{{ route('financeiro.contas') }}">contas</a></div></div>
    <div class="card serie-receita"><div class="rot">Receitas previstas</div><div class="num">{{ brl($p['receitas_previstas']) }}</div>
      <div class="progresso" role="img" aria-label="{{ $pct($p['receitas_realizadas'], $p['receitas_previstas']) }}% recebido"><span style="width:{{ $pct($p['receitas_realizadas'], $p['receitas_previstas']) }}%"></span></div>
      <div class="det">recebido {{ brl($p['receitas_realizadas']) }} ({{ $pct($p['receitas_realizadas'], $p['receitas_previstas']) }}%)</div></div>
    <div class="card serie-despesa"><div class="rot">Despesas previstas</div><div class="num">{{ brl($p['despesas_previstas']) }}</div>
      <div class="progresso" role="img" aria-label="{{ $pct($p['despesas_realizadas'], $p['despesas_previstas']) }}% pago"><span style="width:{{ $pct($p['despesas_realizadas'], $p['despesas_previstas']) }}%"></span></div>
      <div class="det">pago {{ brl($p['despesas_realizadas']) }} ({{ $pct($p['despesas_realizadas'], $p['despesas_previstas']) }}%)</div></div>
    <div class="card serie-comparativo"><div class="rot">Resultado previsto</div><div class="num">{{ brl($p['receitas_previstas'] - $p['despesas_previstas']) }}</div><div class="det">receitas − despesas da competência</div></div>
  </section>

  <h2>Ações necessárias</h2>
  @forelse ($acoes as $a)
    <div class="acao acao-{{ $a['tipo'] }}">
      <div><strong>{{ $a['titulo'] }}</strong><div class="sub" style="margin:2px 0 0">{{ $a['texto'] }}</div></div>
      <a class="botao {{ $a['tipo'] === 'bad' ? '' : 'sec' }}" href="{{ $a['url'] }}">{{ $a['acao'] }}</a>
    </div>
  @empty
    <p class="painel vazio">Nada pendente: extratos em dia, tudo conciliado e nenhum vencido.</p>
  @endforelse

@elseif ($aba === 'fluxo')
  @php($negativo = collect($fluxo)->firstWhere('saldo', '<', 0))
  @if ($negativo)
    <div class="aviso bad"><strong>Atenção:</strong> o saldo projetado fica negativo na semana de {{ $negativo['inicio']->format('d/m') }} ({{ brl($negativo['saldo']) }}).</div>
  @endif
  <x-grafico.linha titulo="Saldo projetado por semana" sub="próximas 12 semanas" serie="projecao" :tracejado="true"
    :pontos="collect($fluxo)->map(fn ($f) => ['rotulo' => $f['inicio']->format('d/m'), 'valor' => $f['saldo'], 'dica' => 'Semana de '.$f['inicio']->format('d/m').': saldo '.brl($f['saldo']).' (entradas '.brl($f['entradas']).', saídas '.brl($f['saidas']).')'])->all()"
    :rotulos="6" :rotulo-final="brl(end($fluxo)['saldo'])"
    :descricao="'Saldo projetado: de '.brl($fluxo[0]['saldo']).' a '.brl(end($fluxo)['saldo'])"
    nota="Linha tracejada = projeção. Parcelas vencidas e não pagas entram na primeira semana. Passe o mouse nos pontos para ver os valores." />
  <x-grafico.colunas titulo="Entradas e saídas previstas por semana" style="margin-top:12px"
    :periodos="collect($fluxo)->map(fn ($f) => $f['inicio']->format('d/m'))->all()"
    :series="[['nome' => 'Entradas', 'serie' => 'receita', 'valores' => array_column($fluxo, 'entradas')], ['nome' => 'Saídas', 'serie' => 'despesa', 'valores' => array_column($fluxo, 'saidas')]]" />
  <div class="tabela" style="margin-top:12px"><table>
    <thead><tr><th>Semana</th><th class="n">Entradas previstas</th><th class="n">Saídas previstas</th><th class="n">Saldo projetado</th></tr></thead>
    <tbody>
    @foreach ($fluxo as $f)
      <tr><td>{{ $f['inicio']->format('d/m') }} a {{ $f['fim']->format('d/m') }}</td><td class="n">{{ $f['entradas'] ? brl($f['entradas']) : '—' }}</td>
        <td class="n">{{ $f['saidas'] ? brl($f['saidas']) : '—' }}</td><td class="n" @if ($f['saldo'] < 0) style="color:var(--bad)" @endif>{{ brl($f['saldo']) }}@if ($f['saldo'] < 0) (negativo)@endif</td></tr>
    @endforeach
    </tbody>
  </table></div>

@else
  <div class="duas">
    <x-grafico.barras titulo="Despesas por categoria" :sub="'total '.brl($categorias->sum('valor'))" serie="despesa" vazio="Nenhuma despesa nesta competência."
      :itens="$categorias->map(fn ($c) => ['rotulo' => $c['nome'], 'valor' => $c['valor'], 'dica' => $c['nome'].': '.brl($c['valor']).' previsto, '.brl($c['pago']).' pago, '.$c['qtd'].' lançamento(s)'])->all()" />
    <x-grafico.barras titulo="Maiores destinos" sub="fornecedores e prestadores" serie="despesa" vazio="Nenhuma despesa nesta competência."
      :itens="$favorecidos->map(fn ($f) => ['rotulo' => $f['nome'], 'valor' => $f['valor'], 'dica' => $f['nome'].': '.brl($f['valor']).' em '.$f['qtd'].' lançamento(s)'])->all()" />
  </div>
  @if ($categorias->isNotEmpty())
  <div class="tabela" style="margin-top:12px"><table>
    <thead><tr><th>Categoria</th><th class="n">Previsto</th><th class="n">Pago</th><th class="n">Lançamentos</th><th class="n">% do total</th></tr></thead>
    <tbody>
    @foreach ($categorias as $c)
      <tr><td>{{ $c['nome'] }}</td>
        <td class="n">{{ brl($c['valor']) }}</td><td class="n">{{ brl($c['pago']) }}</td><td class="n">{{ $c['qtd'] }}</td>
        <td class="n">{{ number_format($c['valor'] * 100 / max(1, $categorias->sum('valor')), 1, ',', '.') }}%</td></tr>
    @endforeach
    </tbody>
  </table></div>
  @endif
@endif
@endsection
