<?php

namespace App\Enums;

enum CategoriaDocumento: string
{
    case ExtratoBancario = 'extrato_bancario';
    case Folha = 'folha';
    case Guia = 'guia';
    case Comprovante = 'comprovante';
    case NotaEntrada = 'nota_entrada';
    case Contrato = 'contrato';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::ExtratoBancario => 'Extrato bancário',
            self::Folha => 'Folha de pagamento / pró-labore',
            self::Guia => 'Guia de imposto paga',
            self::Comprovante => 'Comprovante de despesa',
            self::NotaEntrada => 'Nota fiscal de compra',
            self::Contrato => 'Contrato ou aditivo',
            self::Outro => 'Outro',
        };
    }
}
