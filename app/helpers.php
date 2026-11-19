<?php

use App\Support\Contexto;
use App\Support\Dinheiro;
use App\Support\Documento;

if (! function_exists('brl')) {
    function brl(?int $centavos): string
    {
        return Dinheiro::formatar($centavos);
    }
}

if (! function_exists('doc')) {
    function doc(?string $documento): string
    {
        return Documento::formatar($documento);
    }
}

/** Centavos → "12.000,00" para preencher campos de formulário. */
if (! function_exists('valor_campo')) {
    function valor_campo(?int $centavos): string
    {
        return $centavos === null ? '' : number_format($centavos / 100, 2, ',', '.');
    }
}

if (! function_exists('ctx')) {
    function ctx(): Contexto
    {
        return app(Contexto::class);
    }
}
