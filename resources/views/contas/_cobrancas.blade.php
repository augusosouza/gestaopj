{{-- Aba Cobranças da central de recebimentos: quem cobrar agora e o histórico de contatos e promessas. --}}
<div class="duas">
  <section class="painel">
    <strong>Quem cobrar agora</strong>
    @forelse ($vencidasCobrar as $l)
      <div class="venc-linha"><div><a href="{{ $l['url'] }}?aba=cobranca">{{ $l['fornecedor'] ?? $l['descricao'] }}</a>
        <div class="sub" style="margin:0;font-size:12.5px">{{ $l['descricao'] }} · venceu {{ $l['vencimento']->format('d/m/Y') }} ({{ (int) $l['vencimento']->diffInDays($hoje) }} dia(s))</div></div>
        <div class="n"><strong>{{ brl($l['saldo']) }}</strong><br><a href="{{ $l['url'] }}?aba=cobranca" style="font-size:12.5px">registrar contato</a></div></div>
    @empty
      <p class="vazio">Nenhum recebimento atrasado.</p>
    @endforelse
  </section>
  <section class="painel">
    <strong>Promessas de pagamento</strong>
    @php($promessas = $registros->where('tipo', 'promessa')->take(12))
    @forelse ($promessas as $r)
      @php($s = $r->situacaoPromessa())
      <div class="venc-linha"><div><a href="{{ route('financeiro.titulo', [$r->titulo_id, 'aba' => 'cobranca']) }}">{{ $r->titulo->contraparte ?? $r->titulo->descricao }}</a>
        <div class="sub" style="margin:0;font-size:12.5px">prometeu {{ brl($r->promessa_valor_centavos) }} até {{ $r->promessa_data->format('d/m/Y') }}</div></div>
        <div class="n"><span class="tag {{ ['cumprida' => 'ok', 'quebrada' => 'bad', 'aguardando' => 'warn'][$s] }}">{{ ['cumprida' => 'Cumprida', 'quebrada' => 'Não cumprida', 'aguardando' => 'Aguardando'][$s] }}</span></div></div>
    @empty
      <p class="vazio">Nenhuma promessa registrada.</p>
    @endforelse
    <p class="sub" style="font-size:12.5px;margin-top:8px">Promessa não é recebimento: só vira "cumprida" quando há baixa no valor prometido até a data.</p>
  </section>
</div>
<h2>Histórico de contatos</h2>
<div class="tabela"><table>
  <thead><tr><th>Data</th><th>Cliente</th><th>Tipo</th><th>Canal</th><th>Mensagem / resposta</th><th>Responsável</th></tr></thead>
  <tbody>
  @forelse ($registros as $r)
    <tr><td>{{ $r->data->format('d/m/Y H:i') }}</td><td><a href="{{ route('financeiro.titulo', [$r->titulo_id, 'aba' => 'cobranca']) }}">{{ $r->titulo->contraparte ?? $r->titulo->descricao }}</a></td>
      <td>{{ \App\Models\CobrancaRegistro::TIPOS[$r->tipo] }}@if ($r->tipo === 'promessa')<br><span class="sub" style="font-size:12px">{{ brl($r->promessa_valor_centavos) }} até {{ $r->promessa_data->format('d/m') }}</span>@endif</td>
      <td>{{ \App\Models\CobrancaRegistro::CANAIS[$r->canal] ?? '—' }}</td>
      <td>{{ $r->mensagem ?? '—' }}@if ($r->resposta)<br><span class="sub" style="font-size:12.5px">Resposta: {{ $r->resposta }}</span>@endif</td><td>{{ $r->responsavel?->name ?? '—' }}</td></tr>
  @empty
    <tr><td colspan="6" class="vazio">Nenhum contato registrado.</td></tr>
  @endforelse
  </tbody>
</table></div>
