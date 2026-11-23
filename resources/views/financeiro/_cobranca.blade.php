{{-- Aba Cobrança do lançamento a receber: contato, promessa, renegociação e histórico. Espera $t e $cobranca. --}}
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
@php($abertas = $t->parcelas->filter(fn ($p) => $p->saldo() > 0 && ! $t->cancelado()))
@if ($opera && $abertas->isNotEmpty())
<div class="duas">
  <form method="post" action="{{ route('cobranca.registrar', $t) }}" class="painel">@csrf
    <strong>Registrar contato ou promessa</strong>
    <div class="grade" style="margin-top:10px">
      <div class="campo"><label for="c-tipo">Tipo</label><select id="c-tipo" name="tipo">
        <option value="contato">Contato</option><option value="promessa">Promessa de pagamento</option></select></div>
      <div class="campo"><label for="c-canal">Canal</label><select id="c-canal" name="canal">
        @foreach (\App\Models\CobrancaRegistro::CANAIS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <div class="campo"><label for="c-data">Data do contato</label><input id="c-data" type="datetime-local" name="data" value="{{ now()->format('Y-m-d\TH:i') }}" max="{{ now()->format('Y-m-d\TH:i') }}" required></div>
      <div class="campo"><label for="c-parc">Parcela</label><select id="c-parc" name="parcela_id"><option value="">Todo o saldo</option>
        @foreach ($abertas as $p)<option value="{{ $p->id }}">{{ $p->numero }} · vence {{ $p->vencimento->format('d/m') }} · {{ brl($p->saldo()) }}</option>@endforeach</select></div>
    </div>
    <div class="campo"><label for="c-msg">Mensagem enviada / assunto</label><textarea id="c-msg" name="mensagem" rows="2"></textarea></div>
    <div class="campo"><label for="c-resp">Resposta do cliente</label><textarea id="c-resp" name="resposta" rows="2"></textarea></div>
    <div class="grade" data-promessa>
      <div class="campo"><label for="c-pdata">Promete pagar até</label><input id="c-pdata" type="date" name="promessa_data"></div>
      <div class="campo"><label for="c-pvalor">Valor prometido</label><input id="c-pvalor" name="promessa_valor" inputmode="decimal" placeholder="0,00"></div>
    </div>
    <button>Registrar</button>
    <p class="sub" style="font-size:12.5px;margin:8px 0 0">Promessa não é recebimento. O valor só entra quando o pagamento for registrado.</p>
  </form>
  <form method="post" action="{{ route('cobranca.renegociar', $t) }}" class="painel">@csrf
    <strong>Renegociar vencimentos</strong>
    <p class="sub" style="font-size:12.5px;margin:2px 0 10px">Novas datas para parcelas em aberto. Fica no histórico; lembretes e agenda são recalculados. Juros/multa do acordo são lançados como acréscimo ao receber.</p>
    @foreach ($abertas as $p)
      <div class="grade" style="align-items:end">
        <label class="marcar"><input type="checkbox" name="parcelas[]" value="{{ $p->id }}"> Parcela {{ $p->numero }} · {{ brl($p->saldo()) }} · vence {{ $p->vencimento->format('d/m/Y') }}</label>
        <div class="campo" style="margin:0"><label for="nv-{{ $p->id }}">Nova data</label><input id="nv-{{ $p->id }}" type="date" name="datas[{{ $p->id }}]"></div>
      </div>
    @endforeach
    <div class="campo" style="margin-top:10px"><label for="r-motivo">Motivo</label><input id="r-motivo" name="motivo" required placeholder="Ex.: cliente pediu prazo"></div>
    <div class="campo"><label for="r-cond">Condições combinadas (opcional)</label><input id="r-cond" name="condicoes" placeholder="Ex.: multa de 2% no pagamento"></div>
    <button class="sec">Renegociar</button>
  </form>
</div>
@endif
<h2>Histórico de cobrança</h2>
<div class="tabela"><table>
  <thead><tr><th>Data</th><th>Tipo</th><th>Canal</th><th>Detalhe</th><th>Situação</th><th>Por</th></tr></thead>
  <tbody>
  @forelse ($cobranca as $r)
    <tr><td>{{ $r->data->format('d/m/Y H:i') }}</td><td>{{ \App\Models\CobrancaRegistro::TIPOS[$r->tipo] }}</td><td>{{ \App\Models\CobrancaRegistro::CANAIS[$r->canal] ?? '—' }}</td>
      <td>@if ($r->tipo === 'renegociacao')@foreach ($r->detalhes['parcelas'] ?? [] as $d)<div>Parcela {{ $d['parcela'] }}: {{ $d['de'] }} → {{ $d['para'] }}</div>@endforeach<span class="sub" style="font-size:12.5px">{{ $r->mensagem }}{{ $r->resposta ? ' · '.$r->resposta : '' }}</span>
        @else{{ $r->mensagem ?? '—' }}@if ($r->resposta)<br><span class="sub" style="font-size:12.5px">Resposta: {{ $r->resposta }}</span>@endif
          @if ($r->tipo === 'promessa')<br><strong style="font-size:13px">Prometeu {{ brl($r->promessa_valor_centavos) }} até {{ $r->promessa_data->format('d/m/Y') }}</strong>@endif @endif</td>
      <td>@if ($s = $r->situacaoPromessa())<span class="tag {{ ['cumprida' => 'ok', 'quebrada' => 'bad', 'aguardando' => 'warn'][$s] }}">{{ ['cumprida' => 'Cumprida', 'quebrada' => 'Não cumprida', 'aguardando' => 'Aguardando'][$s] }}</span>@else — @endif</td>
      <td>{{ $r->criadoPor?->name ?? '—' }}</td></tr>
  @empty
    <tr><td colspan="6" class="vazio">Nenhum contato de cobrança registrado.</td></tr>
  @endforelse
  </tbody>
</table></div>
