<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Dinheiro;
use Illuminate\Validation\ValidationException;

/** Converte valores digitados ("1.500,00") em centavos, com mensagem de erro no campo certo. */
trait LeValores
{
    private function centavos(?string $v, string $campo, bool $zeroOk = false): int
    {
        if ($zeroOk && trim((string) $v) === '') {
            return 0;
        }
        try {
            return Dinheiro::paraCentavos((string) $v);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$campo => 'Valor inválido. Use, por exemplo, 1.500,00.']);
        }
    }
}
