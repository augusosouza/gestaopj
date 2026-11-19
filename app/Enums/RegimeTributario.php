<?php

namespace App\Enums;

/**
 * Regime usado para decidir qual motor de cálculo (fase 3) atende o CNPJ.
 */
enum RegimeTributario: string
{
    case Mei = 'mei';
    case Simples = 'simples';
    // Optante do Simples que recolhe IBS/CBS pelo regime regular.
    case SimplesIbsCbsRegular = 'simples_ibscbs_regular';
    case Regular = 'regular';

    public function rotulo(): string
    {
        return match ($this) {
            self::Mei => 'MEI',
            self::Simples => 'Simples Nacional',
            self::SimplesIbsCbsRegular => 'Simples Nacional (IBS/CBS no regime regular)',
            self::Regular => 'Regime regular (Lucro Presumido/Real)',
        };
    }
}
