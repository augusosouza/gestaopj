<?php

namespace App\Enums;

enum Papel: string
{
    // Papéis em uma empresa
    case Admin = 'admin';
    case Financeiro = 'financeiro';
    case Prestador = 'prestador';
    // Papéis em um escritório contábil
    case Analista = 'analista';
    case Revisor = 'revisor';

    public function rotulo(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Financeiro => 'Financeiro',
            self::Prestador => 'Prestador',
            self::Analista => 'Analista contábil',
            self::Revisor => 'Revisor do escritório',
        };
    }

    public function deEscritorio(): bool
    {
        return in_array($this, [self::Analista, self::Revisor], true);
    }

    /** @return list<self> */
    public static function daEmpresa(): array
    {
        return [self::Admin, self::Financeiro, self::Prestador];
    }

    /** @return list<self> */
    public static function doEscritorio(): array
    {
        return [self::Analista, self::Revisor];
    }
}
