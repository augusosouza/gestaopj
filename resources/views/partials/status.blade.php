@php
  $classe = match ($status) {
    \App\Enums\StatusFatura::Recebida, \App\Enums\StatusCobranca::Paga => 'ok',
    \App\Enums\StatusCobranca::Aprovada, \App\Enums\StatusFatura::Emitida => 'info',
    \App\Enums\StatusCobranca::Rejeitada, \App\Enums\StatusFatura::Cancelada => 'bad',
    default => 'warn',
  };
@endphp
<span class="tag {{ $classe }}">{{ $status->rotulo() }}</span>
