<?php

namespace App\Enums;

enum StatusCobranca: string
{
    case AguardandoNota = 'aguardando_nota';
    case EmConferencia = 'em_conferencia';
    case Aprovada = 'aprovada';
    case Rejeitada = 'rejeitada';
    case Paga = 'paga';

    public function rotulo(): string
    {
        return match ($this) {
            self::AguardandoNota => 'Aguardando nota',
            self::EmConferencia => 'Em conferência',
            self::Aprovada => 'Aprovada',
            self::Rejeitada => 'Rejeitada',
            self::Paga => 'Paga',
        };
    }

    /** Estados em que o prestador pode (re)enviar a nota. */
    public function aceitaNota(): bool
    {
        return in_array($this, [self::AguardandoNota, self::Rejeitada], true);
    }
}
