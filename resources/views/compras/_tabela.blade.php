<div class="tabela"><table>
  <thead><tr><th>Data</th><th>Compra</th><th>Fornecedor</th><th>Forma</th><th class="n">Total</th><th class="n">Restante</th><th>Situação</th></tr></thead>
  <tbody>
  @forelse ($lista as $c)
    <tr>
      <td>{{ $c->data_compra->format('d/m/Y') }}</td>
      <td><a href="{{ route('compras.show', $c) }}">{{ $c->descricao }}</a>@if ($c->categoria)<br><span class="sub" style="font-size:12.5px">{{ $c->categoria->nome }}</span>@endif</td>
      <td>{{ $c->fornecedor ?? '—' }}</td>
      <td>{{ $c->noCartao() ? ($c->cartao?->rotulo() ?? 'Cartão') : \App\Models\Compra::MODALIDADES[$c->modalidade] }}@if (($n = $c->titulo?->parcelas->count()) > 1) · {{ $n }}x @endif</td>
      <td class="n">{{ brl($c->total_centavos) }}</td>
      <td class="n">{{ $c->cancelada() ? '—' : brl($c->titulo?->saldo() ?? 0) }}</td>
      <td>@if ($c->cancelada())<span class="tag">Cancelada</span>@elseif (($c->titulo?->saldo() ?? 0) === 0)<span class="tag ok">Quitada</span>@elseif ($c->titulo->vencido())<span class="tag bad">Parcela vencida</span>@else<span class="tag warn">Em pagamento</span>@endif</td>
    </tr>
  @empty
    <tr><td colspan="7" class="vazio">Nenhuma compra registrada.</td></tr>
  @endforelse
  </tbody>
</table></div>
