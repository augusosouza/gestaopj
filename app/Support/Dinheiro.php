<?php

namespace App\Support;

use InvalidArgumentException;

final class Dinheiro
{
    public static function formatar(?int $centavos): string
    {
        if ($centavos === null) {
            return '—';
        }
        $sinal = $centavos < 0 ? '-' : '';

        return $sinal.'R$ '.number_format(abs($centavos) / 100, 2, ',', '.');
    }

    /**
     * Converte entrada do usuário ("12.000,00", "12000.00", "12000") em centavos
     * sem passar por float.
     */
    public static function paraCentavos(string|int|null $valor): int
    {
        if ($valor === null || $valor === '') {
            throw new InvalidArgumentException('Valor vazio.');
        }
        if (is_int($valor)) {
            return $valor * 100;
        }
        $v = trim(str_replace(['R$', ' '], '', $valor));
        if (str_contains($v, ',')) {
            // formato brasileiro: ponto é milhar, vírgula é decimal
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $v)) {
            throw new InvalidArgumentException("Valor inválido: {$valor}");
        }
        [$inteiro, $dec] = array_pad(explode('.', $v), 2, '0');

        return (int) $inteiro * 100 + (int) str_pad($dec, 2, '0');
    }

    /** "12000.00" vindo do XML da NFS-e. */
    public static function deXml(?string $valor): ?int
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return self::paraCentavos(trim($valor));
    }
}
