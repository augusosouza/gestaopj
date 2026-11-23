@extends('layouts.app')
@section('titulo', 'Planejamento')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@php($url = fn ($a) => route('planejamento.index', ['aba' => $a, 'competencia' => $competencia->format('Y-m')]))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Planejamento' => null]" titulo="Planejamento"
  sub="Orçamentos por categoria ou centro de custo, rateio de despesas e evolução mês a mês.">
  <x-slot:acoes>@include('partials.competencia')</x-slot:acoes>
</x-cabecalho>
<x-abas :itens="collect(\App\Http\Controllers\PlanejamentoController::ABAS)->map(fn ($r, $k) => [$r, $url($k), $aba === $k])->values()->all()" />

@if ($aba === 'orcamentos')
  @php($exc = $linhas->where('estado', 'excedido'))
  @if ($exc->isNotEmpty())
    <div class="aviso bad"><strong>{{ $exc->count() }} orçamento(s) ultrapassado(s):</strong> {{ $exc->map(fn ($l) => $l['rotulo'].' (+'.brl(-$l['disponivel']).')')->join(', ') }}.</div>
  @endif
  <section class="cards" aria-label="Totais do mês">
    <div class="card serie-comparativo"><div class="rot">Orçado</div><div class="num">{{ brl((int) $linhas->sum('orcado')) }}</div><div class="det">{{ $linhas->count() }} orçamento(s)</div></div>
    <div class="card serie-despesa"><div class="rot">Comprometido</div><div class="num">{{ brl((int) $linhas->sum('comprometido')) }}</div><div class="det">despesas lançadas no mês</div></div>
    <div class="card serie-saldo"><div class="rot">Realizado</div><div class="num">{{ brl((int) $linhas->sum('realizado')) }}</div><div class="det">já pago</div></div>
    <div class="card serie-pendencia"><div class="rot">Disponível</div><div class="num" @if ($linhas->sum('disponivel') < 0) style="color:var(--bad)" @endif>{{ brl((int) $linhas->sum('disponivel')) }}</div><div class="det">orçado − comprometido</div></div>
  </section>
  <div class="tabela" style="margin-top:16px"><table>
    <thead><tr><th>Orçamento</th><th class="n">Orçado</th><th class="n">Comprometido</th><th class="n">Realizado</th><th class="n">Disponível</th><th style="min-width:160px">Uso</th></tr></thead>
    <tbody>
    @forelse ($linhas as $l)
      <tr><td>{{ $l['rotulo'] }}<br><span class="sub" style="font-size:12px">{{ $l['tipo'] === 'categoria' ? 'categoria (inclui subcategorias)' : 'centro de custo' }}</span></td>
        <td class="n">{{ brl($l['orcado']) }}</td><td class="n">{{ brl($l['comprometido']) }}</td><td class="n">{{ brl($l['realizado']) }}</td>
        <td class="n" @if ($l['disponivel'] < 0) style="color:var(--bad)" @endif>{{ brl($l['disponivel']) }}</td>
        <td><div @class(['progresso', 'serie-despesa' => $l['estado'] === 'ok', 'serie-pendencia' => $l['estado'] === 'alerta']) @if ($l['estado'] === 'excedido') style="--cor:var(--bad)" @endif role="img" aria-label="{{ $l['pct'] }}% usado"><span style="width:{{ min(100, $l['pct']) }}%"></span></div>
          <span class="sub" style="font-size:12px">{{ $l['pct'] }}% @if ($l['estado'] === 'excedido')<span class="tag bad">ultrapassado</span>@elseif ($l['estado'] === 'alerta')<span class="tag warn">perto do limite</span>@endif</span></td></tr>
    @empty
      <tr><td colspan="6" class="vazio">Nenhum orçamento em {{ $competencia->translatedFormat('F/Y') }}.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  @if ($sem->isNotEmpty())
    <h2>Gastos sem orçamento neste mês</h2>
    <x-grafico.barras titulo="Despesas lançadas sem orçamento" serie="despesa" :itens="$sem->all()" />
  @endif
  @if ($opera)
  <div class="duas" style="margin-top:16px">
    <form method="post" action="{{ route('planejamento.orcamento') }}" class="painel">@csrf
      <input type="hidden" name="competencia" value="{{ $competencia->format('Y-m') }}">
      <strong>Definir orçamento de {{ $competencia->translatedFormat('F/Y') }}</strong>
      <div class="grade" style="margin-top:10px">
        <div class="campo"><label for="o-alvo">Categoria ou centro</label><select id="o-alvo" name="alvo" required><option value="">—</option>
          <optgroup label="Categorias">@foreach ($categorias as $c)<option value="cat:{{ $c->id }}">{{ $c->caminho() }}</option>@endforeach</optgroup>
          @if ($centros->isNotEmpty())<optgroup label="Centros de custo">@foreach ($centros as $c)<option value="cc:{{ $c->id }}">{{ $c->rotulo() }}</option>@endforeach</optgroup>@endif
        </select></div>
        <div class="campo"><label for="o-valor">Limite no mês (R$)</label><input id="o-valor" name="valor" inputmode="decimal" required placeholder="3.000,00"></div>
      </div>
      <button>Salvar</button> <span class="sub" style="font-size:12.5px">Mesmo alvo no mesmo mês: o valor é atualizado.</span>
    </form>
    <form method="post" action="{{ route('planejamento.copiar') }}" class="painel">@csrf
      <input type="hidden" name="competencia" value="{{ $competencia->format('Y-m') }}">
      <strong>Repetir o mês anterior</strong>
      <p class="sub" style="font-size:13px">Copia os orçamentos de {{ $competencia->subMonthNoOverflow()->translatedFormat('F/Y') }} que ainda não existem neste mês.</p>
      <button class="sec">Copiar orçamentos</button>
      <p class="sub" style="font-size:12.5px;margin-top:12px">Alerta em {{ \App\Services\Planejamento::ALERTA_PCT }}% do limite e quando ultrapassar (aparece em Sugestões).</p>
    </form>
  </div>
  @endif

@elseif ($aba === 'centros')
  <div class="duas">
    <x-grafico.barras :titulo="'Despesas por centro de custo — '.$competencia->translatedFormat('F/Y')" serie="despesa" vazio="Nenhuma despesa no mês." :itens="$porCentro->all()" />
    <div class="tabela"><table>
      <thead><tr><th>Centro</th><th>Tipo</th><th>Situação</th><th></th></tr></thead>
      <tbody>
      @forelse ($centros as $c)
        <tr @if (! $c->ativo) style="color:var(--muted)" @endif><td>{{ $c->rotulo() }}</td><td>{{ \App\Models\CentroCusto::TIPOS[$c->tipo] }}</td><td>{{ $c->ativo ? 'Ativo' : 'Inativo' }}</td>
          <td>@if ($opera)<form method="post" action="{{ route('planejamento.centro_alternar', $c) }}">@csrf @method('patch')<button class="link">{{ $c->ativo ? 'desativar' : 'reativar' }}</button></form>@endif</td></tr>
      @empty
        <tr><td colspan="4" class="vazio">Nenhum centro de custo.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </div>
  @if ($opera)
  <form method="post" action="{{ route('planejamento.centro') }}" class="painel" style="margin-top:16px">@csrf
    <strong>Novo centro de custo</strong>
    <div class="grade" style="margin-top:10px">
      <div class="campo"><label for="cc-nome">Nome</label><input id="cc-nome" name="nome" required placeholder="Ex.: Comercial"></div>
      <div class="campo"><label for="cc-cod">Código (opcional)</label><input id="cc-cod" name="codigo" maxlength="20"></div>
      <div class="campo"><label for="cc-tipo">Tipo</label><select id="cc-tipo" name="tipo">@foreach (\App\Models\CentroCusto::TIPOS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <div class="campo"><label for="cc-contr">Contrato (opcional)</label><select id="cc-contr" name="contrato_id"><option value="">—</option>
        @foreach ($contratos as $c)<option value="{{ $c->id }}">{{ $c->numero }} — {{ $c->descricao }}</option>@endforeach</select></div>
    </div>
    <button>Criar</button>
    <p class="sub" style="font-size:12.5px;margin:8px 0 0">Para dividir uma despesa entre centros, abra o lançamento e use “Rateio por centro de custo” no Resumo.</p>
  </form>
  @endif

@else
  <div class="tabela"><table>
    <thead><tr><th>Categoria</th>@foreach ($meses as $m)<th class="n">{{ ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][$m->month - 1] }}/{{ $m->format('y') }}</th>@endforeach<th class="n">Último × média</th></tr></thead>
    <tbody>
    @forelse ($linhas as $l)
      <tr><td>{{ $l['rotulo'] }}</td>@foreach ($l['valores'] as $v)<td class="n">{{ $v ? brl($v) : '—' }}</td>@endforeach
        <td class="n">@if ($l['variacao'] === null) — @elseif ($l['variacao'] > 0)<span style="color:var(--bad)">▲ {{ $l['variacao'] }}%</span>@elseif ($l['variacao'] < 0)<span style="color:var(--ok)">▼ {{ abs($l['variacao']) }}%</span>@else 0% @endif</td></tr>
    @empty
      <tr><td colspan="{{ count($meses) + 2 }}" class="vazio">Sem despesas no período.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <p class="sub" style="font-size:12.5px;margin-top:8px">Despesas por competência, agrupadas pela categoria principal. "Último × média": mês selecionado comparado à média dos 5 anteriores — ▲ em vermelho indica gasto crescendo.</p>
  @if ($linhas->isNotEmpty())
    <x-grafico.colunas titulo="Total de despesas por mês" style="margin-top:12px" :periodos="collect($meses)->map(fn ($m) => $m->format('m/y'))->all()"
      :series="[['nome' => 'Despesas', 'serie' => 'despesa', 'valores' => collect(range(0, count($meses) - 1))->map(fn ($i) => (int) $linhas->sum(fn ($l) => $l['valores'][$i]))->all()]]" />
  @endif
@endif
@endsection
