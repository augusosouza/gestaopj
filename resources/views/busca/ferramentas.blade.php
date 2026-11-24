@extends('layouts.app')
@section('titulo', 'Ferramentas')
@section('compacta', '1')
@section('conteudo')
<x-cabecalho :trilha="['Busca global' => route('busca'), 'Ferramentas' => null]" titulo="Ferramentas" />
<div class="ficha-secoes">
  <section class="painel">
    <h2>Validador cadastral</h2>
    <p class="sub">Verifica formato e, para CPF e CNPJ, dígitos verificadores.</p>
    <form method="post" action="{{ route('ferramentas') }}">
      @csrf <input type="hidden" name="acao" value="validar">
      <div class="grade">
        <div class="campo"><label for="validar-tipo">Tipo</label><select id="validar-tipo" name="tipo">
          <option value="cpf">CPF</option><option value="cnpj">CNPJ</option><option value="cep">CEP</option><option value="email">E-mail</option>
        </select></div>
        <div class="campo"><label for="validar-valor">Dado a validar</label><input id="validar-valor" name="valor" maxlength="254" autocomplete="off" required></div>
      </div>
      <button class="sec">Validar dado</button>
    </form>
    @if ($valido !== null)<p role="status" class="aviso {{ $valido ? 'ok' : 'bad' }}">{{ $valido ? 'Dado válido para o tipo selecionado.' : 'Dado inválido para o tipo selecionado.' }}</p>@endif
  </section>
  <section class="painel">
    <h2>Simulador de parcelamento</h2>
    <p class="sub">Distribui o total em parcelas mensais, sem juros e sem criar lançamentos.</p>
    <form method="post" action="{{ route('ferramentas') }}">
      @csrf <input type="hidden" name="acao" value="simular">
      <div class="grade">
        <div class="campo"><label for="simular-total">Total em reais</label><input id="simular-total" name="total" maxlength="16" inputmode="decimal" value="{{ old('total') }}" required></div>
        <div class="campo"><label for="simular-quantidade">Parcelas</label><input id="simular-quantidade" type="number" name="quantidade" min="1" max="120" value="{{ old('quantidade', 2) }}" required></div>
        <div class="campo"><label for="simular-inicio">Primeiro vencimento</label><input id="simular-inicio" type="date" name="inicio" value="{{ old('inicio', now()->toDateString()) }}" required></div>
      </div>
      <button>Simular parcelas</button>
    </form>
    @if ($parcelas)
      <div class="tabela"><table>
        <thead><tr><th>Parcela</th><th>Vencimento</th><th class="n">Valor</th></tr></thead>
        <tbody>@foreach ($parcelas as [$data, $valor])<tr><td>{{ $loop->iteration }}</td><td>{{ $data->format('d/m/Y') }}</td><td class="n">{{ brl($valor) }}</td></tr>@endforeach</tbody>
        <tfoot><tr><th colspan="2">Total simulado</th><th class="n">{{ brl($total) }}</th></tr></tfoot>
      </table></div>
    @endif
  </section>
</div>
@endsection
