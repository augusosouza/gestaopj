<?php

namespace App\Enums;

enum StatusSolicitacao: string
{
    case Aberta = 'aberta';         // aguardando a empresa
    case Respondida = 'respondida'; // aguardando o contador conferir
    case Aceita = 'aceita';
    case Cancelada = 'cancelada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Aberta => 'Aguardando a empresa',
            self::Respondida => 'Aguardando o contador',
            self::Aceita => 'Resolvida',
            self::Cancelada => 'Cancelada',
        };
    }

    public function pendente(): bool
    {
        return in_array($this, [self::Aberta, self::Respondida], true);
    }
}
