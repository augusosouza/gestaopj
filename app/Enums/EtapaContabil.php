<?php

namespace App\Enums;

enum EtapaContabil: string
{
    case Coleta = 'coleta';           // empresa reunindo e enviando documentos
    case Conferencia = 'conferencia'; // analista conferindo
    case Revisao = 'revisao';         // pronta para o revisor
    case Fechada = 'fechada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Coleta => 'Em coleta',
            self::Conferencia => 'Em conferência',
            self::Revisao => 'Em revisão',
            self::Fechada => 'Fechada',
        };
    }

    public function ordem(): int
    {
        return array_search($this, self::cases(), true);
    }
}
