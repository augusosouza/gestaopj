<?php

namespace App\Support;

final class Documento
{
    public static function limpar(?string $doc): string
    {
        return preg_replace('/\D/', '', (string) $doc);
    }

    public static function cnpjValido(string $cnpj): bool
    {
        $c = self::limpar($cnpj);
        if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) {
            return false;
        }
        foreach ([12, 13] as $t) {
            $soma = 0;
            $peso = $t - 7;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $c[$i] * $peso;
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
        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
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
