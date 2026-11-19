<?php

namespace App\Enums;

enum TipoOrganizacao: string
{
    case Empresa = 'empresa';
    case Escritorio = 'escritorio';

    public function rotulo(): string
    {
        return $this === self::Empresa ? 'Empresa' : 'Escritório contábil';
    }
}
