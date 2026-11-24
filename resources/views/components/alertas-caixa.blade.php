@props(['projecao', 'noCaixa' => false])

@if ($projecao['negativo'] || $projecao['saida_concentrada'])
  <section aria-label="Alertas de caixa">
    <h2>Alertas de caixa</h2>
    <p class="sub">{{ $projecao['hoje']->format('d/m/Y') }} a {{ $projecao['ate']->format('d/m/Y') }}{{ $projecao['simulacao'] ? ' · projeção com simulação' : '' }}</p>
    @if ($projecao['negativo'])
      <div class="acao acao-bad">
        <div>
          <strong>{{ $projecao['simulacao'] ? 'Déficit no cenário simulado' : 'Risco de saldo negativo' }}</strong>
          <p class="sub">Em {{ $projecao['negativo']['data']->format('d/m/Y') }}, faltam {{ brl($projecao['negativo']['deficit']) }}. Confira os vencimentos e a possibilidade de antecipar entradas ou renegociar pagamentos.</p>
        </div>
        <a class="botao sec" href="{{ $noCaixa ? '#compromissos-caixa' : route('financeiro.caixa').'#compromissos-caixa' }}">Conferir vencimentos</a>
      </div>
    @endif
    @if ($saida = $projecao['saida_concentrada'])
      <div class="acao acao-warn">
        <div>
          <strong>Saída prevista de alto impacto</strong>
          <p class="sub">{{ $saida['descricao'] }} · {{ brl($saida['valor']) }} · vencimento em {{ $saida['vencimento']->format('d/m/Y') }}. Representa {{ $saida['percentual'] }}% dos compromissos a pagar no período, sem contar simulações.</p>
        </div>
        <a class="botao sec" href="{{ $saida['url'] }}">Abrir compromisso</a>
      </div>
    @endif
  </section>
@endif
