@extends('layouts.app')
@section('titulo', 'A pagar')
@section('conteudo')
<div class="barra">
  <div><h1>Contas a pagar</h1><p class="sub" style="margin:0">Cobranças dos prestadores e conferência das notas recebidas</p></div>
  <div class="acoes">
  <form method="get">
    <div><label for="status" class="sr">Status</label>
      <select id="status" name="status" onchange="this.form.submit()">
        <option value="">Todos os status</option>
        @foreach (\App\Enums\StatusCobranca::cases() as $s)
          <option value="{{ $s->value }}" @selected($status === $s)>{{ $s->rotulo() }}</option>
        @endforeach
      </select></div>
    <input type="hidden" name="competencia" value="{{ $competencia->format('Y-m') }}">
    <noscript><button class="sec">Filtrar</button></noscript>
  </form>
  @include('partials.competencia')
  </div>
</div>
<div class="tabela">
  <table>
    <thead><tr><th>Prestador</th><th>Contrato</th><th class="n">Valor</th><th>Nota</th><th>Conferência</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($cobrancas as $c)
      <tr>
        <td>{{ $c->prestador->nome }} <span class="tag rotulo">{{ $c->prestador->tipo->value }}</span><br><span class="mono">{{ doc($c->prestador->documento) }}</span></td>
        <td>{{ $c->contrato->numero }}<br><span class="sub">{{ $c->contrato->cliente->razao_social }}</span></td>
        <td class="n">{{ brl($c->valor_centavos) }}</td>
        <td>{{ $c->notaFiscal ? 'Nº '.$c->notaFiscal->numero : '—' }}</td>
        <td>@if ($c->conferencia)
              @if ($c->invalidaFiscalmente())<span class="tag bad">Nota inválida</span>
              @elseif ($c->temDivergenciaComercial())<span class="tag bad">Divergência comercial</span>
              @elseif ($c->naoVerificada())<span class="tag warn">Não verificada</span>
              @else<span class="tag ok">Sem divergências</span>@endif
            @else — @endif</td>
        <td>@include('partials.status', ['status' => $c->status])</td>
        <td><a href="{{ route('cobrancas.show', $c) }}">Abrir</a></td>
      </tr>
    @empty
      <tr><td colspan="7" class="vazio">Nenhuma cobrança encontrada.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
