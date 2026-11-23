@php($cl = match ($etapa) { \App\Enums\EtapaContabil::Fechada => 'ok', \App\Enums\EtapaContabil::Revisao => 'info', default => 'warn' })
<span class="tag {{ $cl }}">{{ $etapa->rotulo() }}</span>
