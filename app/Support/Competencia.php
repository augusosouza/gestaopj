<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Competência é sempre o primeiro dia do mês. */
final class Competencia
{
    public static function de(string|\DateTimeInterface|null $valor = null): CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return CarbonImmutable::now()->startOfMonth();
        }
        if (is_string($valor) && preg_match('/^\d{4}-\d{2}$/', $valor)) {
            $valor .= '-01';
        }

        return CarbonImmutable::parse($valor)->startOfMonth();
    }

    public static function rotulo(\DateTimeInterface $c): string
    {
        return CarbonImmutable::instance($c)->format('m/Y');
    }
}
