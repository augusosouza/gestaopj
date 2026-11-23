@php($s = $t->situacao())
@if ($t->vencido())<span class="tag bad">Vencido</span>
@else<span class="tag {{ ['liquidado' => 'ok', 'parcial' => 'info', 'aberto' => 'warn', 'cancelado' => ''][$s] }}">{{ ['liquidado' => $t->receber() ? 'Recebido' : 'Pago', 'parcial' => 'Parcial', 'aberto' => 'Em aberto', 'cancelado' => 'Cancelado'][$s] }}</span>@endif
