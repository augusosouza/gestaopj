<?php

namespace App\Enums;

enum TipoPrestador: string
{
    case PJ = 'PJ';
    case MEI = 'MEI';
    case PF = 'PF';

    /** Pessoa física não emite NFS-e de PJ: fluxo de documentação próprio (fora do piloto). */
    public function emiteNfse(): bool
    {
        return $this !== self::PF;
    }
}
