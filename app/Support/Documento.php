<?php

namespace App\Support;

final class Documento
{
    /**
     * Remove pontuação e normaliza para maiúsculas, preservando letras:
     * o CNPJ alfanumérico (emitido a partir de julho/2026) tem letras nas 12 primeiras posições.
     */
    public static function limpar(?string $doc): string
    {
        return preg_replace('/[^0-9A-Z]/', '', mb_strtoupper((string) $doc));
    }

    /**
     * Valida CNPJ numérico ou alfanumérico.
     * Formato: 12 posições [0-9A-Z] + 2 dígitos verificadores numéricos.
     * Cada caractere vale (código ASCII − 48): '0'..'9' = 0..9, 'A' = 17 … 'Z' = 42;
     * pesos e módulo 11 iguais ao cálculo tradicional, então CNPJs antigos seguem válidos.
     */
    public static function cnpjValido(string $cnpj): bool
    {
        $c = self::limpar($cnpj);
        if (! preg_match('/^[0-9A-Z]{12}\d{2}$/', $c) || preg_match('/^(.)\1{13}$/', $c)) {
            return false;
        }
        foreach ([12, 13] as $t) {
            $soma = 0;
            $peso = $t - 7;
            for ($i = 0; $i < $t; $i++) {
                $soma += (ord($c[$i]) - 48) * $peso;
                $peso = $peso === 2 ? 9 : $peso - 1;
            }
            $dv = ($soma % 11) < 2 ? 0 : 11 - ($soma % 11);
            if ((int) $c[$t] !== $dv) {
                return false;
            }
        }

        return true;
    }

    public static function cpfValido(string $cpf): bool
    {
        $c = self::limpar($cpf);
        if (! ctype_digit($c) || strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }
        foreach ([9, 10] as $t) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $c[$i] * (($t + 1) - $i);
            }
            $dv = ((10 * $soma) % 11) % 10;
            if ((int) $c[$t] !== $dv) {
                return false;
            }
        }

        return true;
    }

    public static function valido(string $doc): bool
    {
        $c = self::limpar($doc);

        return strlen($c) === 14 ? self::cnpjValido($c) : self::cpfValido($c);
    }

    public static function formatar(?string $doc): string
    {
        $c = self::limpar($doc);
        if (strlen($c) === 14) {
            return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($c));
        }
        if (strlen($c) === 11) {
            return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($c));
        }

        return (string) $doc;
    }
}
