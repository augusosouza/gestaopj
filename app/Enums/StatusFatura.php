<?php

namespace App\Enums;

enum StatusFatura: string
{
    case Aberta = 'aberta';       // gerada, ainda sem NFS-e vinculada
    case Emitida = 'emitida';     // NFS-e vinculada, aguardando recebimento
    case Recebida = 'recebida';
    case Cancelada = 'cancelada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Aberta => 'Sem nota',
            self::Emitida => 'Nota emitida',
            self::Recebida => 'Recebida',
            self::Cancelada => 'Cancelada',
        };
    }
}
