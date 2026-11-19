@extends('layouts.app')
@section('titulo', 'Contrato '.$contrato->numero)
@section('conteudo')
@php($admin = ctx()->tem(\App\Enums\Papel::Admin))
<a class="voltar" href="{{ route('contratos.index') }}">← Contratos</a>
<div class="barra">
  <div><h1>Contrato {{ $contrato->numero }}</h1><p class="sub" style="margin:0">{{ $contrato->cliente->razao_social }} · {{ $contrato->descricao }}</p></div>
  @if ($admin)<a class="botao sec" href="{{ route('contratos.edit', $contrato) }}">Editar</a>@endif
</div>
<div class="cards">
  <div class="card"><div class="rot">Faturamento mensal</div><div class="num">{{ brl($contrato->valor_mensal_centavos) }}</div><div class="det">Vence dia {{ $contrato->dia_vencimento }} do mês seguinte</div></div>
  @php($custo = (int) $contrato->alocacoes->filter(fn ($a) => ! $a->fim || $a->fim->isFuture())->sum('valor_mensal_centavos'))
  <div class="card"><div class="rot">Custo atual de prestadores</div><div class="num">{{ brl($custo) }}</div><div class="det">Alocações vigentes</div></div>
  <div class="card"><div class="rot">Margem bruta mensal</div><div class="num">{{ brl($contrato->valor_mensal_centavos - $custo) }}</div><div class="det">Antes de tributos</div></div>
</div>

<h2>Prestadores alocados</h2>
<div class="tabela"><table>
  <thead><tr><th>Prestador</th><th>Função</th><th class="n">Valor mensal</th><th>Período</th><th></th></tr></thead>
  <tbody>
  @forelse ($contrato->alocacoes as $a)
    <tr>
      <td>{{ $a->prestador->nome }} <span class="tag rotulo">{{ $a->prestador->tipo->value }}</span></td>
      <td>{{ $a->descricao ?? '—' }}</td>
      <td class="n">{{ brl($a->valor_mensal_centavos) }}</td>
      <td>{{ $a->inicio->format('d/m/Y') }} – {{ $a->fim?->format('d/m/Y') ?? 'atual' }}</td>
      <td>@if ($admin && ! $a->fim)
        <form method="post" action="{{ route('alocacoes.encerrar', $a) }}" style="display:flex;gap:6px">@csrf @method('patch')
          <input type="date" name="fim" required aria-label="Data de encerramento" style="width:auto"><button class="sec">Encerrar</button></form>
      @endif</td>
    </tr>
  @empty
    <tr><td colspan="5" class="vazio">Nenhum prestador alocado.</td></tr>
  @endforelse
  </tbody>
</table></div>

@if ($admin)
  <form method="post" action="{{ route('contratos.alocar', $contrato) }}" class="painel" style="margin-top:12px">
    @csrf
    <strong>Alocar prestador</strong>
    <div class="grade" style="margin-top:8px">
      <div><label for="prestador_id">Prestador</label><select id="prestador_id" name="prestador_id" required>
        @foreach ($prestadores as $p)<option value="{{ $p->id }}">{{ $p->nome }} ({{ $p->tipo->value }})</option>@endforeach</select></div>
      <div><label for="descricao">Função</label><input id="descricao" name="descricao" placeholder="Analista de suporte N2"></div>
      <div><label for="valor_mensal">Valor mensal (R$)</label><input id="valor_mensal" name="valor_mensal" inputmode="decimal" required placeholder="7.500,00"></div>
      <div><label for="inicio_a">Início</label><input id="inicio_a" type="date" name="inicio" value="{{ $contrato->inicio->toDateString() }}" required></div>
      <div style="align-self:end"><button>Alocar</button></div>
    </div>
  </form>
@endif

<h2>Últimas faturas</h2>
<div class="tabela"><table>
  <thead><tr><th>Competência</th><th class="n">Valor</th><th>Vencimento</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @forelse ($faturas as $f)
    <tr><td>{{ $f->competencia->format('m/Y') }}</td><td class="n">{{ brl($f->valor_centavos) }}</td><td>{{ $f->vencimento->format('d/m/Y') }}</td>
      <td>@include('partials.status', ['status' => $f->status])</td><td><a href="{{ route('faturas.show', $f) }}">Abrir</a></td></tr>
  @empty
    <tr><td colspan="5" class="vazio">Nenhuma fatura gerada ainda.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
