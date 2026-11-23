{{-- Lista de compromissos da agenda. Espera $itens (de PosicaoFinanceira::compromissos) e $mostrarData. --}}
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<div class="tabela"><table>
  <thead><tr>@if ($mostrarData)<th>Vencimento</th>@endif<th>Tipo</th><th>Descrição</th><th>Contraparte</th><th class="n">Em aberto</th><th></th></tr></thead>
  <tbody>
  @foreach ($itens as $e)
    <tr>
      @if ($mostrarData)<td>{{ $e['vencimento']->format('d/m/Y') }}<br><span class="sub" style="font-size:12px">{{ (int) $e['vencimento']->diffInDays(now()->startOfDay()) }} dia(s) de atraso</span></td>@endif
      <td><span @class(['tag', 'ok' => $e['tipo'] === 'receber', 'info' => $e['tipo'] === 'pagar'])>{{ $e['cartao'] ? 'Fatura' : ($e['tipo'] === 'receber' ? 'Receber' : 'Pagar') }}</span></td>
      <td><a href="{{ $e['url'] }}">{{ $e['descricao'] }}</a></td>
      <td>{{ $e['contraparte'] ?? '—' }}</td>
      <td class="n">{{ brl($e['valor']) }}</td>
      <td>@if ($opera)<a class="botao sec" href="{{ $e['url'] }}{{ $e['cartao'] ? '#pagar' : '?aba=pagamentos#registrar' }}">{{ $e['cartao'] ? 'Pagar fatura' : ($e['tipo'] === 'receber' ? 'Registrar recebimento' : 'Registrar pagamento') }}</a>@endif</td>
    </tr>
  @endforeach
  </tbody>
</table></div>
