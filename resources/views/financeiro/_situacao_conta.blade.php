{{-- Situação do extrato de uma conta: estado do lembrete + conciliação pendente (alertas separados). --}}
@php($cl = ['em_dia' => 'ok', 'desatualizado' => 'bad', 'dispensado' => '', 'sem_lembrete' => ''][$s['estado']])
<span class="tag {{ $cl }}">{{ $s['rotulo'] }}</span>
@if ($s['a_conciliar'] > 0)<span class="tag warn" style="margin-left:6px">{{ $s['a_conciliar'] }} a conciliar</span>@endif
