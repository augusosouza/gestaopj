      <div class="ficha-secoes">
        @if ($l->movimento)
          <span class="tag ok">Conciliado</span> <a href="{{ route('financeiro.titulo', $l->movimento->parcela->titulo_id) }}">{{ $l->movimento->parcela->titulo->descricao }}</a>
          @if ($l->movimento->tarifa && !$l->movimento->tarifa_separada)
            <div class="sub">Bruto {{ brl($l->movimento->valor_centavos) }} · tarifa {{ brl($l->movimento->tarifa->valor_centavos) }} · líquido {{ brl($l->movimento->valorConciliavel()) }}</div>
          @endif
        @elseif ($l->transferencia)
          <span class="tag ok">Conciliado</span> <a href="{{ route('transferencias.index') }}">Transferência {{ $l->transferencia->origem->nome }} → {{ $l->transferencia->destino->nome }}</a>
        @elseif (($sug->isNotEmpty() || $sugT->isNotEmpty()) && $opera)
          @foreach ($sug as $m)
            <form method="post" action="{{ route('financeiro.conciliar', $l) }}" style="display:flex;gap:6px;align-items:center;margin-bottom:4px">@csrf
              <input type="hidden" name="movimento_id" value="{{ $m->id }}">
              <span style="font-size:13px">{{ $m->parcela->titulo->descricao }} · {{ $m->data->format('d/m') }}
                @if ($m->tarifa && !$m->tarifa_separada)<br>Bruto {{ brl($m->valor_centavos) }} · tarifa {{ brl($m->tarifa->valor_centavos) }}@endif
              </span><button class="sec">Conciliar</button></form>
          @endforeach
          @foreach ($sugT as $tr)
            <form method="post" action="{{ route('financeiro.conciliar_transferencia', $l) }}" style="display:flex;gap:6px;align-items:center;margin-bottom:4px">@csrf
              <input type="hidden" name="transferencia_id" value="{{ $tr->id }}">
              <span style="font-size:13px">Transferência {{ $tr->origem->nome }} → {{ $tr->destino->nome }} · {{ $tr->data->format('d/m') }}</span><button class="sec">Conciliar</button></form>
          @endforeach
        @else<span class="sub" style="font-size:13px">Nenhuma baixa com mesmo valor e data próxima. Registre a baixa no lançamento e volte aqui.</span>@endif
      </div>
      <div class="acoes">@if ($opera)
        @if ($l->conciliada())<form method="post" action="{{ route('financeiro.desconciliar', $l) }}">@csrf<button class="link">desfazer</button></form>
        @else<form method="post" action="{{ route('financeiro.ignorar', $l) }}">@csrf<button class="link">{{ $l->ignorada ? 'voltar a pendente' : 'sem correspondência' }}</button></form>@endif
      @endif</div>
