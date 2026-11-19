<?php

namespace App\Rules;

use App\Support\Documento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DocumentoValido implements ValidationRule
{
    /** @param 'cnpj'|'cpf'|'qualquer' $tipo */
    public function __construct(private string $tipo = 'qualquer') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ok = match ($this->tipo) {
            'cnpj' => Documento::cnpjValido((string) $value),
            'cpf' => Documento::cpfValido((string) $value),
            default => Documento::valido((string) $value),
        };
        if (! $ok) {
            $fail($this->tipo === 'cnpj' ? 'CNPJ inválido.' : 'CPF/CNPJ inválido.');
        }
    }
}
