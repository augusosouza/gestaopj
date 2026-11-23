@extends('layouts.app')
@section('titulo', $t->descricao)
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<a class="voltar" href="{{ route('financeiro.index', ['competencia' => $t->competencia->format('Y-m')]) }}">← Financeiro</a>
<div class="barra">
  <div>
    <h1>{{ $t->descricao }}</h1>
    <p class="sub">{{ $t->receber() ? 'A receber' : 'A pagar' }} · competência {{ $t->competencia->format('m/Y') }} · {{ $t->categoria?->nome ?? 'sem categoria' }}
      @if ($t->contraparte) · {{ $t->contraparte }}@endif @include('financeiro._situacao')</p>
  </div>
  @if ($t->origem_type === (new \App\Models\Fatura)->getMorphClass())<a class="botao sec" href="{{ route('faturas.show', $t->origem_id) }}">Ver fatura</a>
  @elseif ($t->origem_type === (new \App\Models\Cobranca)->getMorphClass())<a class="botao sec" href="{{ route('cobrancas.show', $t->origem_id) }}">Ver cobrança</a>@endif
</div>
@if ($t->cancelado())<div class="aviso info">Cancelado em {{ $t->cancelado_em->format('d/m/Y') }}: {{ $t->motivo_cancelamento }}</div>@endif

<section class="cards" style="grid-template-columns:repeat(3,minmax(0,1fr))">
  <div class="card"><div class="rot">Valor total</div><div class="num">{{ brl($t->valor_total_centavos) }}</div><div class="det">{{ $t->parcelas->count() }} parcela(s)</div></div>
  <div class="card"><div class="rot">{{ $t->receber() ? 'Recebido' : 'Pago' }} (abatido)</div><div class="num">{{ brl($t->liquidado()) }}</div><div class="det">inclui descontos e retenções</div></div>
  <div class="card"><div class="rot">Saldo</div><div class="num">{{ brl($t->saldo()) }}</div><div class="det">{{ $t->vencido() ? 'há parcela vencida' : 'em dia' }}</div></div>
</section>

@foreach ($t->parcelas as $p)
  <h2>Parcela {{ $p->numero }}/{{ $t->parcelas->count() }} · vence {{ $p->vencimento->format('d/m/Y') }} · {{ brl($p->valor_centavos) }}
    <span class="sub" style="font-weight:400">— saldo {{ brl($p->saldo()) }}</span> @if ($p->vencida())<span class="tag bad">Vencida</span>@endif</h2>
  <div class="tabela"><table>
    <thead><tr><th>Data</th><th>Tipo</th><th>Conta</th><th class="n">Valor</th><th class="n">Desc./Ret./Acrésc.</th><th>Por</th><th>Extrato</th><th></th></tr></thead>
    <tbody>
    @forelse ($p->movimentos as $m)
      <tr @if ($m->tipo === 'estorno' || $m->estornado()) style="color:var(--muted)" @endif>
        <td>{{ $m->data->format('d/m/Y') }}</td>
        <td>@if ($m->tipo === 'estorno')<span class="tag bad">Estorno</span><br><span style="font-size:12.5px">{{ $m->observacao }}</span>
            @else{{ $t->receber() ? 'Recebimento' : 'Pagamento' }} @if ($m->estornado())<span class="tag">estornado</span>@endif
              @if ($m->observacao)<br><span style="font-size:12.5px">{{ $m->observacao }}</span>@endif @endif</td>
        <td>{{ $m->conta->nome }}</td>
        <td class="n">{{ $m->tipo === 'estorno' ? '−' : '' }}{{ brl($m->valor_centavos) }}</td>
        <td class="n" style="font-weight:400">@if ($m->desconto_centavos || $m->retencao_centavos || $m->acrescimo_centavos){{ brl($m->desconto_centavos) }} / {{ brl($m->retencao_centavos) }} / {{ brl($m->acrescimo_centavos) }}@else — @endif</td>
        <td>{{ $m->criadoPor?->name ?? 'Sistema' }}</td>
        <td>@if ($m->linhaExtrato)<span class="tag ok">Conciliado</span>@elseif ($m->tipo === 'baixa' && ! $m->estornado())<span class="tag warn">Pendente</span>@else — @endif</td>
        <td>@if ($opera && $m->tipo === 'baixa' && ! $m->estornado() && ! $m->linhaExtrato)
          <details><summary class="link-sum">estornar</summary>
            <form method="post" action="{{ route('financeiro.estornar', $m) }}" style="display:flex;gap:6px;margin-top:6px">@csrf
              <input name="motivo" required placeholder="Motivo do estorno" aria-label="Motivo do estorno"><button class="perigo">Estornar</button></form>
          </details>@endif</td>
      </tr>
    @empty
      <tr><td colspan="8" class="vazio">Nenhuma baixa nesta parcela.</td></tr>
    @endforelse
    </tbody>
  </table></div>

  @if ($opera && ! $t->cancelado() && $p->saldo() > 0)
    <form method="post" action="{{ route('financeiro.baixar', $p) }}" class="painel" style="margin-top:10px">
      @csrf <input type="hidden" name="chave" value="{{ $chave }}-{{ $p->id }}">
      <strong>Registrar {{ $t->receber() ? 'recebimento' : 'pagamento' }}</strong>
      <div class="grade" style="margin-top:8px">
        <div><label for="conta{{ $p->id }}">Conta</label><select id="conta{{ $p->id }}" name="conta_id" required>
          @forelse ($contas as $c)<option value="{{ $c->id }}">{{ $c->nome }}</option>@empty<option value="">Cadastre uma conta</option>@endforelse</select></div>
        <div><label for="data{{ $p->id }}">Data</label><input id="data{{ $p->id }}" type="date" name="data" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
        <div><label for="valor{{ $p->id }}">Valor {{ $t->receber() ? 'recebido' : 'pago' }}</label><input id="valor{{ $p->id }}" name="valor" inputmode="decimal" value="{{ valor_campo($p->saldo()) }}" required></div>
      </div>
      <details style="margin-top:8px"><summary class="link-sum">Desconto, retenção ou acréscimo</summary>
        <div class="grade" style="margin-top:8px">
          <div><label>Desconto</label><input name="desconto" inputmode="decimal" placeholder="0,00"></div>
          <div><label>Retenção (impostos retidos)</label><input name="retencao" inputmode="decimal" placeholder="0,00"></div>
          <div><label>Acréscimo (juros/multa)</label><input name="acrescimo" inputmode="decimal" placeholder="0,00"></div>
        </div>
      </details>
      <div style="display:flex;gap:8px;margin-top:10px;align-items:end"><div style="flex:1"><label>Observação (opcional)</label><input name="observacao"></div><button>Registrar</button></div>
      <p class="sub" style="margin:8px 0 0;font-size:13px">Pagamento parcial é permitido; a parcela continua em aberto pelo saldo. Não é possível baixar mais que o saldo.</p>
    </form>
  @endif
@endforeach

@if ($opera && ! $t->cancelado() && ! $t->origem_type && $t->liquidado() === 0)
  <form method="post" action="{{ route('financeiro.cancelar', $t) }}" style="display:flex;gap:8px;margin-top:24px;max-width:600px">@csrf
    <input name="motivo" required placeholder="Motivo do cancelamento" aria-label="Motivo do cancelamento"><button class="perigo">Cancelar lançamento</button></form>
@endif
@endsection
