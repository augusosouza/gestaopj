<?php

namespace App\Enums;

enum Papel: string
{
    case Admin = 'admin';
    case Financeiro = 'financeiro';
    case Contabilidade = 'contabilidade';
    case Prestador = 'prestador';

    public function rotulo(): string
    {
        return match ($this) {
            self::Admin => 'Empresa',
            self::Financeiro => 'Financeiro',
            self::Contabilidade => 'Contabilidade',
            self::Prestador => 'Prestador',
        };
    }
}
