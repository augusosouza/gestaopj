<p class="sub">Conferência por regras com os dados da conta. Sem alterações financeiras.</p>
@if ($evidencias['pares']->isNotEmpty())
  <h3>Possíveis lançamentos duplicados</h3>
  <p>Mesmo valor, sentido e descrição na mesma conta, em até sete dias. Confira os comprovantes antes de concluir.</p>
  <ul class="check">
    @foreach ($evidencias['pares'] as $par)
      <li><a href="{{ route('financeiro.conciliacao', [$conta, 'linha' => $par->id, 'mostrar' => 'todas', 'aba' => 'analise']).'#conferencia' }}">
        Linha #{{ $par->id }} · {{ $par->data->format('d/m/Y') }}
      </a></li>
    @endforeach
  </ul>
@endif
@if ($tarifa = $evidencias['tarifa'])
  <h3>Tarifa do recebimento</h3>
  <p>Bruto {{ brl($l->movimento->valor_centavos) }} · tarifa {{ brl($l->movimento->tarifa->valor_centavos) }}
    · taxa {{ number_format($tarifa['atual'] / 100, 2, ',', '.') }}%.</p>
  <p><a href="{{ route('financeiro.titulo', $l->movimento->tarifa->parcela->titulo_id) }}">Abrir despesa da tarifa</a></p>
  @if ($tarifa['mediana'] === null)
    <p class="sub">Histórico insuficiente para comparação. São necessários pelo menos três recebimentos tarifados comparáveis.</p>
  @else
    <p>Mediana de {{ number_format($tarifa['mediana'] / 100, 2, ',', '.') }}% em {{ $tarifa['amostra'] }} recebimentos tarifados na mesma conta, em até 90 dias anteriores.</p>
    @if ($tarifa['atual'] > $tarifa['mediana'])
      <p class="sub">Taxa acima da mediana. Confira se o tipo de operação e as condições contratadas são comparáveis.</p>
    @endif
  @endif
@endif
@if ($evidencias['pares']->isEmpty() && !$evidencias['tarifa'])
  <p class="sub">Nenhuma linha semelhante ou tarifa vinculada foi encontrada pelas regras atuais.</p>
@endif
