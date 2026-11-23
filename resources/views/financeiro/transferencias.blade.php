@extends('layouts.app')
@section('titulo', 'Transferências')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Transferências' => null]" titulo="Transferências entre contas"
  sub="Dinheiro que muda de conta dentro da empresa. Não é receita nem despesa: só altera os saldos. A tarifa, se houver, vira uma despesa própria." />

@if ($opera)
  @if ($contas->count() < 2)
    <div class="aviso info">Cadastre pelo menos duas contas em <a href="{{ route('financeiro.contas') }}">Contas e bancos</a> para transferir.</div>
  @else
  <form method="post" action="{{ route('transferencias.store') }}" class="painel" style="margin-bottom:20px">@csrf
    <input type="hidden" name="chave" value="{{ $chave }}">
    <strong>Nova transferência</strong>
    <div class="grade" style="margin-top:10px">
      <div class="campo"><label for="conta_origem_id">De</label><select id="conta_origem_id" name="conta_origem_id" required>
        @foreach ($contas as $c)<option value="{{ $c->id }}" @selected(old('conta_origem_id') == $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
      <div class="campo"><label for="conta_destino_id">Para</label><select id="conta_destino_id" name="conta_destino_id" required>
        @foreach ($contas as $c)<option value="{{ $c->id }}" @selected(old('conta_destino_id', $contas[1]->id) == $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
      <div class="campo"><label for="valor">Valor (R$)</label><input id="valor" name="valor" inputmode="decimal" value="{{ old('valor') }}" required placeholder="5.000,00"></div>
      <div class="campo"><label for="data">Data</label><input id="data" type="date" name="data" value="{{ old('data', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
      <div class="campo"><label for="tarifa">Tarifa (opcional)</label><input id="tarifa" name="tarifa" inputmode="decimal" value="{{ old('tarifa') }}" placeholder="0,00"></div>
    </div>
    <div class="campo"><label for="descricao">Descrição (opcional)</label><input id="descricao" name="descricao" value="{{ old('descricao') }}" placeholder="Ex.: Reforço de caixa para a folha"></div>
    <button>Transferir</button>
  </form>
  @endif
@endif

<div class="tabela"><table>
  <thead><tr><th>Data</th><th>De</th><th>Para</th><th>Descrição</th><th class="n">Valor</th><th>Extrato</th><th></th></tr></thead>
  <tbody>
  @forelse ($transferencias as $t)
    <tr @if ($t->estorno || $t->estorno_de_id) style="color:var(--muted)" @endif>
      <td>{{ $t->data->format('d/m/Y') }}</td><td>{{ $t->origem->nome }}</td><td>{{ $t->destino->nome }}</td>
      <td>@if ($t->estorno_de_id)<span class="tag bad">Estorno</span> @endif{{ $t->descricao ?? '—' }}@if ($t->estorno) <span class="tag">estornada</span>@endif
        <br><span class="sub" style="font-size:12px">por {{ $t->criadoPor?->name ?? 'Sistema' }}</span></td>
      <td class="n">{{ brl($t->valor_centavos) }}</td>
      <td>@if ($t->linhasExtrato->count() >= 2)<span class="tag ok">Conciliada</span>@elseif ($t->linhasExtrato->count() === 1)<span class="tag info">1 de 2 contas</span>@else<span class="tag warn">Pendente</span>@endif</td>
      <td>@if ($opera && ! $t->estorno && ! $t->estorno_de_id && $t->linhasExtrato->isEmpty())
        <details><summary class="link-sum">estornar</summary>
          <form method="post" action="{{ route('transferencias.estornar', $t) }}" style="display:flex;gap:6px;margin-top:6px">@csrf
            <input name="motivo" required placeholder="Motivo" aria-label="Motivo do estorno"><button class="perigo">Estornar</button></form>
        </details>@endif</td>
    </tr>
  @empty
    <tr><td colspan="7" class="vazio">Nenhuma transferência registrada.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
