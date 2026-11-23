@extends('layouts.app')
@section('titulo', $t->descricao)
@section('conteudo')
@php
  $opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro);
  $verbo = $t->receber() ? 'Recebido' : 'Pago';
  $lista = $t->receber() ? 'Contas a receber' : 'Contas a pagar';
  $listaUrl = $t->receber() ? route('contas_receber.index') : route('contas_pagar.index');
  $movs = $t->parcelas->flatMap->movimentos->sortBy('id');
  $abaUrl = fn ($a) => route('financeiro.titulo', [$t, 'aba' => $a]);
  $origem = $t->origem_type === (new \App\Models\Fatura)->getMorphClass() ? ['Ver fatura', route('faturas.show', $t->origem_id)]
    : ($t->origem_type === (new \App\Models\Cobranca)->getMorphClass() ? ['Ver cobrança', route('cobrancas.show', $t->origem_id)]
    : ($t->origem_type === (new \App\Models\Compra)->getMorphClass() ? ['Ver compra', route('compras.show', $t->origem_id)] : null));
  $rotuloOrigem = match ($t->origem_type) {
    (new \App\Models\Fatura)->getMorphClass() => 'Fatura de cliente',
    (new \App\Models\Cobranca)->getMorphClass() => 'Cobrança de prestador',
    (new \App\Models\Compra)->getMorphClass() => 'Compra'.($t->parcelas->contains(fn ($p) => $p->fatura_cartao_id) ? ' no cartão de crédito' : ''),
    default => 'Lançamento manual',
  };
@endphp
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), $lista => $listaUrl, $t->descricao => null]"
  :titulo="$t->descricao" :sub="'Competência '.$t->competencia->translatedFormat('F \d\e Y').($t->contraparte ? ' · '.$t->contraparte : '').' · '.($t->categoria?->nome ?? 'sem categoria')">
  <div style="margin-top:6px">@include('financeiro._situacao')</div>
  <x-slot:acoes>
    @if ($origem)<a class="botao sec" href="{{ $origem[1] }}">{{ $origem[0] }}</a>@endif
    @if ($opera && ! $t->cancelado() && $t->saldo() > 0 && ! $t->parcelas->contains(fn ($p) => $p->fatura_cartao_id))<a class="botao" href="{{ $abaUrl('pagamentos') }}#registrar">Registrar {{ $t->receber() ? 'recebimento' : 'pagamento' }}</a>@endif
  </x-slot:acoes>
</x-cabecalho>
@if ($t->cancelado())<div class="aviso info">Cancelado em {{ $t->cancelado_em->format('d/m/Y') }}: {{ $t->motivo_cancelamento }}</div>@endif

<section class="cards" aria-label="Valores">
  <div class="card"><div class="rot">Valor original</div><div class="num">{{ brl($t->valor_total_centavos) }}</div><div class="det">{{ $t->parcelas->count() }} parcela(s)</div></div>
  <div class="card"><div class="rot">{{ $verbo }} em dinheiro</div><div class="num">{{ brl($t->pagoEmDinheiro()) }}</div><div class="det">saiu/entrou nas contas</div></div>
  @php($ajustes = array_filter(['desconto' => $t->descontos(), 'retenção' => $t->retencoes(), 'acréscimo' => $t->acrescimos()]))
  <div class="card"><div class="rot">Descontos, retenções e acréscimos</div><div class="num">{{ brl($t->descontos() + $t->retencoes() - $t->acrescimos()) }}</div>
    <div class="det">{{ $ajustes ? collect($ajustes)->map(fn ($v, $k) => $k.' '.brl($v))->join(' · ') : 'nenhum' }}</div></div>
  <div class="card"><div class="rot">Pendente</div><div class="num">{{ brl($t->cancelado() ? 0 : $t->saldo()) }}</div><div class="det">{{ $t->vencido() ? 'há parcela vencida' : 'em dia' }}</div></div>
</section>
<div style="margin-top:20px"></div>
<x-abas :itens="[
  ['Resumo', $abaUrl('resumo'), $aba === 'resumo'],
  ['Parcelas', $abaUrl('parcelas'), $aba === 'parcelas', $t->parcelas->count()],
  [$t->receber() ? 'Recebimentos' : 'Pagamentos', $abaUrl('pagamentos'), $aba === 'pagamentos', $movs->count()],
  ['Documentos', $abaUrl('documentos'), $aba === 'documentos', ($t->anexos->count() + ($t->origem instanceof \App\Models\Compra ? $t->origem->anexos->count() : 0)) ?: null],
  ['Lembretes', $abaUrl('lembretes'), $aba === 'lembretes'],
  ...($t->receber() ? [['Cobrança', $abaUrl('cobranca'), $aba === 'cobranca']] : []),
  ['Histórico', $abaUrl('historico'), $aba === 'historico'],
]" />

@if ($aba === 'resumo')
  <div class="duas">
    <div class="painel"><dl class="dados">
      <dt>Tipo</dt><dd>{{ $t->receber() ? 'A receber' : 'A pagar' }}</dd>
      <dt>Descrição</dt><dd>{{ $t->descricao }}</dd>
      <dt>{{ $t->receber() ? 'Pagador' : 'Favorecido' }}</dt><dd>{{ $t->contraparte ?? '—' }}</dd>
      <dt>Categoria</dt><dd>{{ $t->categoria?->caminho() ?? '—' }}</dd>
      <dt>Centros de custo</dt><dd>{{ $t->rateios->isEmpty() ? '—' : $t->rateios->map(fn ($r) => $r->centro->rotulo().' '.number_format($r->percentual_bp / 100, 2, ',', '.').'% ('.brl($r->valor_centavos).')')->join(' · ') }}</dd>
      <dt>Competência</dt><dd>{{ $t->competencia->format('m/Y') }}</dd>
      <dt>Documento</dt><dd>{{ $t->documento ?? '—' }}</dd>
      <dt>Origem</dt><dd>{{ $rotuloOrigem }}</dd>
      <dt>Forma prevista</dt><dd>{{ \App\Models\Titulo::FORMAS[$t->forma] ?? '—' }}</dd>
      <dt>Próximo vencimento</dt><dd>{{ $t->parcelas->first(fn ($p) => $p->saldo() > 0)?->vencimento->format('d/m/Y') ?? '—' }}</dd>
    </dl></div>
    <div class="painel">
      <strong>Como ler os valores</strong>
      <ul style="margin:8px 0 0;padding-left:18px;color:var(--muted);font-size:13.5px;line-height:1.6">
        <li><strong style="color:var(--text)">{{ $verbo }} em dinheiro:</strong> só o que passou pelas contas.</li>
        <li><strong style="color:var(--text)">Descontos e retenções</strong> abatem o valor sem movimentar a conta (ex.: imposto retido na nota).</li>
        <li><strong style="color:var(--text)">Acréscimos</strong> (juros, multa) aumentam o pagamento sem aumentar o abatimento.</li>
        <li>Pendente = original − dinheiro − descontos − retenções + acréscimos.</li>
      </ul>
      @if ($opera && ! $t->cancelado() && $centrosAtivos->isNotEmpty())
        <details style="margin-top:14px" @if ($errors->has('rateio')) open @endif><summary class="link-sum">Rateio por centro de custo</summary>
          <form method="post" action="{{ route('planejamento.ratear', $t) }}" style="margin-top:8px">@csrf @method('put')
            @foreach ($centrosAtivos as $c)
              @php($atual = $t->rateios->firstWhere('centro_custo_id', $c->id))
              <div class="grade" style="align-items:center;grid-template-columns:1fr 110px"><label for="rt-{{ $c->id }}" style="margin:0">{{ $c->rotulo() }}</label>
                <input id="rt-{{ $c->id }}" name="rateio[{{ $c->id }}]" inputmode="decimal" placeholder="0" value="{{ $atual ? rtrim(rtrim(number_format($atual->percentual_bp / 100, 2, ',', ''), '0'), ',') : '' }}" aria-label="Percentual para {{ $c->nome }}"></div>
            @endforeach
            <p class="sub" style="font-size:12.5px;margin:6px 0">Percentuais somando 100%. Os centavos são distribuídos para que a soma seja exatamente {{ brl($t->valor_total_centavos) }}. Deixe tudo vazio para remover.</p>
            <button class="sec">Salvar rateio</button>
          </form>
        </details>
      @endif
      @if ($opera && ! $t->cancelado() && ! $t->origem_type && $t->liquidado() === 0)
        <form method="post" action="{{ route('financeiro.cancelar', $t) }}" style="display:flex;gap:8px;margin-top:16px">@csrf
          <input name="motivo" required placeholder="Motivo do cancelamento" aria-label="Motivo do cancelamento"><button class="perigo">Cancelar lançamento</button></form>
      @endif
    </div>
  </div>

@elseif ($aba === 'parcelas')
  <div class="tabela"><table>
    <thead><tr><th>Parcela</th><th>Vencimento</th><th class="n">Valor</th><th class="n">Abatido</th><th class="n">Saldo</th><th>Situação</th><th>Fatura</th><th></th></tr></thead>
    <tbody>
    @foreach ($t->parcelas as $p)
      <tr><td>{{ $p->numero }}/{{ $t->parcelas->count() }}</td><td>{{ $p->vencimento->format('d/m/Y') }}</td><td class="n">{{ brl($p->valor_centavos) }}</td>
        <td class="n">{{ brl($p->abatido()) }}</td><td class="n">{{ brl($p->saldo()) }}</td>
        <td>@if ($p->saldo() === 0)<span class="tag ok">Quitada</span>@elseif ($p->vencida())<span class="tag bad">Vencida</span>@elseif ($p->abatido() > 0)<span class="tag info">Parcial</span>@else<span class="tag warn">Em aberto</span>@endif</td>
        <td>@if ($p->fatura)<a href="{{ route('cartoes.fatura', $p->fatura) }}">{{ $p->fatura->cartao->nome }} · {{ $p->fatura->rotulo() }}</a>@else — @endif</td>
        <td>@if ($opera && ! $p->fatura_cartao_id && $p->saldo() > 0 && ! $t->cancelado())
          <details><summary class="link-sum">alterar vencimento</summary>
            <form method="post" action="{{ route('financeiro.vencimento', $p) }}" style="display:grid;gap:6px;margin-top:6px;min-width:200px">@csrf @method('put')
              <input type="date" name="vencimento" value="{{ $p->vencimento->toDateString() }}" required aria-label="Novo vencimento">
              <input name="motivo" required placeholder="Motivo (ex.: renegociado)" aria-label="Motivo"><button class="sec">Salvar</button></form>
          </details>@endif</td></tr>
    @endforeach
    </tbody>
  </table></div>

@elseif ($aba === 'pagamentos')
  <div class="tabela"><table>
    <thead><tr><th>Data</th><th>Parcela</th><th>Movimento</th><th>Conta</th><th class="n">Dinheiro</th><th class="n">Desc./Ret./Acrés.</th><th>Extrato</th><th>Por</th><th></th></tr></thead>
    <tbody>
    @forelse ($movs as $m)
      <tr @if ($m->tipo === 'estorno' || $m->estornado()) style="color:var(--muted)" @endif>
        <td>{{ $m->data->format('d/m/Y') }}</td><td>{{ $m->parcela->numero }}</td>
        <td>@if ($m->tipo === 'estorno')<span class="tag bad">Estorno</span><br><span style="font-size:12.5px">{{ $m->observacao }}</span>
            @else{{ $t->receber() ? 'Recebimento' : 'Pagamento' }} @if ($m->estornado())<span class="tag">estornado</span>@endif
              @if ($m->observacao)<br><span style="font-size:12.5px">{{ $m->observacao }}</span>@endif @endif</td>
        <td>{{ $m->conta->nome }}</td>
        <td class="n">{{ $m->tipo === 'estorno' ? '−' : '' }}{{ brl($m->valor_centavos) }}</td>
        <td class="n" style="font-weight:400">@if ($m->desconto_centavos || $m->retencao_centavos || $m->acrescimo_centavos){{ brl($m->desconto_centavos) }} / {{ brl($m->retencao_centavos) }} / {{ brl($m->acrescimo_centavos) }}@else — @endif</td>
        <td>@if ($m->linhaExtrato)<span class="tag ok">Conciliado</span>@elseif ($m->tipo === 'baixa' && ! $m->estornado())<span class="tag warn">Pendente</span>@else — @endif</td>
        <td>{{ $m->criadoPor?->name ?? 'Sistema' }}</td>
        <td>@if ($opera && $m->tipo === 'baixa' && ! $m->estornado() && ! $m->linhaExtrato)
          <details><summary class="link-sum">estornar</summary>
            <form method="post" action="{{ route('financeiro.estornar', $m) }}" style="display:flex;gap:6px;margin-top:6px">@csrf
              <input name="motivo" required placeholder="Motivo" aria-label="Motivo do estorno"><button class="perigo">Estornar</button></form>
          </details>@elseif ($m->linhaExtrato && $m->tipo === 'baixa')<span class="sub" style="font-size:12px">desconcilie para estornar</span>@endif</td>
      </tr>
    @empty
      <tr><td colspan="9" class="vazio">Nenhum {{ $t->receber() ? 'recebimento' : 'pagamento' }} registrado.</td></tr>
    @endforelse
    </tbody>
  </table></div>

  @php($aberta = $t->parcelas->first(fn ($p) => $p->saldo() > 0))
  @if ($opera && ! $t->cancelado() && $aberta && $aberta->fatura_cartao_id)
    <div class="aviso info" id="registrar" style="margin-top:14px">Compra no cartão de crédito: as parcelas são pagas <strong>pela fatura</strong>, para a despesa não sair duas vezes do caixa.
      <a href="{{ route('cartoes.fatura', $aberta->fatura) }}">Abrir fatura {{ $aberta->fatura->rotulo() }}</a></div>
  @elseif ($opera && ! $t->cancelado() && $aberta)
    <form method="post" action="{{ route('financeiro.baixar', $aberta) }}" class="painel" id="registrar" style="margin-top:14px"
      data-saldo="{{ $aberta->saldo() }}">
      @csrf <input type="hidden" name="chave" value="{{ $chave }}-{{ $aberta->id }}">
      <strong>Registrar {{ $t->receber() ? 'recebimento' : 'pagamento' }} · parcela {{ $aberta->numero }} (vence {{ $aberta->vencimento->format('d/m/Y') }}, saldo {{ brl($aberta->saldo()) }})</strong>
      <div class="grade" style="margin-top:10px">
        <div><label for="conta">Conta</label><select id="conta" name="conta_id" required>
          @forelse ($contas as $c)<option value="{{ $c->id }}">{{ $c->nome }}</option>@empty<option value="">Cadastre uma conta</option>@endforelse</select></div>
        <div><label for="data">Data</label><input id="data" type="date" name="data" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
        <div><label for="valor">Valor em dinheiro</label><input id="valor" name="valor" inputmode="decimal" value="{{ valor_campo($aberta->saldo()) }}" required data-calc></div>
        <div><label for="desconto">Desconto</label><input id="desconto" name="desconto" inputmode="decimal" placeholder="0,00" data-calc></div>
        <div><label for="retencao">Retenção (imposto retido)</label><input id="retencao" name="retencao" inputmode="decimal" placeholder="0,00" data-calc></div>
        <div><label for="acrescimo">Acréscimo (juros/multa)</label><input id="acrescimo" name="acrescimo" inputmode="decimal" placeholder="0,00" data-calc></div>
      </div>
      <div class="campo" style="margin-top:10px"><label for="obs">Observação (opcional)</label><input id="obs" name="observacao"></div>
      <div class="resumo-baixa"><span>Saldo da parcela após o registro: <strong id="saldo-apos">{{ brl(0) }}</strong></span><button>Registrar</button></div>
    </form>
    <script>
      (function () {
        var f = document.getElementById('registrar'); if (!f) return;
        var saldo = parseInt(f.dataset.saldo, 10), out = document.getElementById('saldo-apos');
        function c(v) { v = (v || '').replace(/[R$\s]/g, ''); if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.'); var n = Math.round(parseFloat(v) * 100); return isNaN(n) ? 0 : n; }
        function atualizar() {
          var g = function (n) { return c(f.querySelector('[name=' + n + ']').value); };
          var r = saldo - (g('valor') + g('desconto') + g('retencao') - g('acrescimo'));
          out.textContent = (r < 0 ? '−' : '') + 'R$ ' + (Math.abs(r) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
          out.style.color = r < 0 ? 'var(--bad)' : '';
          out.title = r < 0 ? 'Maior que o saldo: o sistema não aceitará' : '';
        }
        f.querySelectorAll('[data-calc]').forEach(function (i) { i.addEventListener('input', atualizar); });
        atualizar();
      })();
    </script>
  @endif

@elseif ($aba === 'cobranca' && $t->receber())
  @include('financeiro._cobranca')
@elseif ($aba === 'lembretes')
  @if ($t->parcelas->contains(fn ($p) => $p->fatura_cartao_id))
    <div class="aviso info">Compra no cartão: o lembrete é o da <strong>fatura</strong> (configure em <a href="{{ route('configuracoes.vencimentos') }}">Lembretes de vencimento → Faturas de cartão</a>).</div>
  @elseif ($lembretes['parcelas']->isEmpty())
    <p class="painel vazio">Nada em aberto: sem lembretes para este lançamento.</p>
  @else
    <div class="duas">
      <form method="post" action="{{ route('lembretes.titulo', $t) }}" class="painel">@csrf @method('put')
        <strong>Meus lembretes deste lançamento</strong>
        <p class="sub" style="font-size:12.5px;margin:2px 0 10px">{{ ['obrigacao' => 'Ajuste próprio deste lançamento.', 'usuario' => 'Usando o seu padrão.', 'sistema' => 'Usando o padrão do sistema.'][$lembretes['cfg']['origem']] }}</p>
        @include('partials.lembrete_form', ['cfg' => $lembretes['cfg'], 'id' => 't'.$t->id, 'tipoLembrete' => $t->tipo])
        <div class="acoes" style="flex-wrap:wrap"><button>Salvar para este lançamento</button>
          @if ($lembretes['cfg']['origem'] === 'obrigacao')<button class="sec" name="restaurar" value="1">Voltar ao meu padrão</button>@endif</div>
      </form>
      <div class="painel">
        <strong>Prévia</strong>
        @foreach ($lembretes['parcelas'] as $x)
          <div style="margin-top:10px"><strong style="font-size:13.5px">Parcela {{ $x['p']->numero }} · vence {{ $x['p']->vencimento->format('d/m/Y') }} · saldo {{ brl($x['p']->saldo()) }}</strong>
            @if ($lembretes['cfg']['ativo'] && $x['momentos'])
              <ul class="previa-lembretes">@foreach ($x['momentos'] as $m)<li @if ($m['em']->isPast()) style="color:var(--faint)" @endif>{{ $m['em']->format('d/m/Y \à\s H:i') }} — {{ \App\Models\LembreteConfig::diasPara($t->tipo)[$m['dias']] }}@if ($m['em']->isPast()) (já passou)@endif</li>@endforeach</ul>
            @else<p class="sub" style="font-size:13px;margin:4px 0 0">Sem lembretes.</p>@endif
            <div style="margin-top:4px;font-size:12.5px">
              @if ($ev = $lembretes['eventos']->get($x['p']->id))<span class="tag {{ $ev->erro ? 'warn' : 'ok' }}">{{ $ev->erro ? 'Google: erro, será refeito' : 'No Google Agenda' }}</span>@endif
              @if (! $lembretes['conexao'] || $lembretes['conexao']->status !== 'ativa')<a href="{{ $x['link'] }}" target="_blank" rel="noopener">Adicionar ao Google Agenda (manual)</a>@endif
            </div>
          </div>
        @endforeach
        <p class="sub" style="font-size:12.5px;margin-top:12px">Horário de Brasília. Os avisos aparecem na central de notificações do Augi; pagou, eles param. Pagamento parcial mantém os avisos do saldo.</p>
        @if ($lembretes['conexao'] && $lembretes['conexao']->status === 'ativa' && ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
          <form method="post" action="{{ route('lembretes.google', $t) }}">@csrf<button class="sec">Atualizar no Google Agenda agora</button></form>
        @elseif (! $lembretes['conexao'])
          <p style="font-size:13px"><a href="{{ route('integracoes.google') }}">Conectar o Google Agenda</a> para sincronizar automaticamente.</p>
        @endif
      </div>
    </div>
  @endif

@elseif ($aba === 'documentos')
  @include('partials.anexos', ['anexos' => $t->anexos, 'tipo' => 'titulo', 'alvoId' => $t->id, 'extras' => $t->origem instanceof \App\Models\Compra ? $t->origem->anexos : collect()])

@else
  <div class="tabela"><table>
    <thead><tr><th>Quando</th><th>Quem</th><th>O quê</th><th>Detalhe</th></tr></thead>
    <tbody>
    @forelse ($historico as $e)
      <tr><td>{{ $e->created_at->format('d/m/Y H:i') }}</td><td>{{ $e->user?->name ?? 'Sistema' }}</td><td>{{ $e->rotulo() }}</td>
        <td>{{ collect($e->dados ?? [])->map(fn ($v, $k) => in_array($k, ['valor'], true) ? brl((int) $v) : (is_scalar($v) ? $v : ''))->filter()->join(' · ') }}</td></tr>
    @empty
      <tr><td colspan="4" class="vazio">Sem eventos registrados.</td></tr>
    @endforelse
    </tbody>
  </table></div>
@endif
@endsection
