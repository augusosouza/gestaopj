<?php

namespace App\Models;

use App\Enums\StatusFatura;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fatura extends Model
{
    protected $fillable = [
        'contrato_id', 'competencia', 'valor_centavos', 'vencimento', 'status',
        'nota_fiscal_id', 'valor_recebido_centavos', 'recebida_em',
    ];

    protected function casts(): array
    {
        return [
            'competencia' => 'date',
            'vencimento' => 'date',
            'recebida_em' => 'date',
            'status' => StatusFatura::class,
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function notaFiscal(): BelongsTo
    {
        return $this->belongsTo(NotaFiscal::class);
    }

    public function vencida(): bool
    {
        return $this->status !== StatusFatura::Recebida
            && $this->status !== StatusFatura::Cancelada
            && $this->vencimento->isPast() && ! $this->vencimento->isToday();
    }
}
