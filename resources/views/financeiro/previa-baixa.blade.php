@extends('layouts.app')
@section('titulo', 'Conferir baixa')
@section('compacta', '1')
@section('conteudo')
<x-cabecalho titulo="Conferir baixa" :sub="$parcela->titulo->descricao" />
<div class="painel">
  <dl class="dados">
    <dt>Contraparte</dt><dd>{{ $parcela->titulo->contraparte ?? '—' }}</dd>
    <dt>Operação</dt><dd>{{ $parcela->titulo->receber() ? 'Recebimento' : 'Pagamento' }}</dd>
    <dt>Conta</dt><dd>{{ $resumo['conta']->nome }}</dd>
    <dt>Data</dt><dd>{{ \Carbon\CarbonImmutable::parse($dados['data'])->format('d/m/Y') }}</dd>
    <dt>{{ ($dados['tarifa'] ?? 0) ? 'Valor bruto' : 'Valor' }}</dt><dd>{{ brl($dados['valor']) }}</dd>
    @if ($dados['tarifa'] ?? 0)
      @php($dataTarifa = \Carbon\CarbonImmutable::parse($dados['tarifa_data'] ?? $dados['data']))
      <dt>Tarifa bancária</dt><dd>{{ brl($dados['tarifa']) }} em {{ $dataTarifa->format('d/m/Y') }}</dd>
      @if (empty($dados['tarifa_data']))
        <dt>Crédito líquido para conciliar</dt><dd>{{ brl($dados['valor'] - $dados['tarifa']) }}</dd>
      @else
        <dt>Conciliação</dt><dd>Crédito bruto e débito da tarifa em linhas separadas.</dd>
      @endif
    @endif
    @foreach (['desconto' => 'Desconto', 'retencao' => 'Retenção', 'acrescimo' => 'Acréscimo'] as $campo => $rotulo)
      @if ($dados[$campo])<dt>{{ $rotulo }}</dt><dd>{{ brl($dados[$campo]) }}</dd>@endif
    @endforeach
    <dt>Saldo em aberto</dt><dd>{{ brl($resumo['saldo']) }}</dd>
    <dt>Saldo após a baixa</dt><dd>{{ brl($resumo['restante']) }}</dd>
  </dl>
  @if ($possivelDuplicidade)<p class="aviso info">Já existe uma baixa ativa desta parcela com a mesma conta, data e valor. Confira o histórico antes de confirmar.</p>@endif
  <form method="post" action="{{ route('financeiro.baixa.confirmar', $parcela) }}" class="formulario-rodape" data-evitar-reenvio="Registrando…">
    @csrf <input type="hidden" name="confirmacao" value="{{ $confirmacao }}">
    <a href="{{ route('financeiro.titulo', $parcela->titulo_id) }}">Cancelar</a>
    <button>Confirmar baixa</button>
  </form>
</div>
@endsection
