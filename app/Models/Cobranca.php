<?php

namespace App\Models;

use App\Enums\StatusCobranca;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cobranca extends Model
{
    protected $fillable = [
        'alocacao_id', 'prestador_id', 'contrato_id', 'competencia', 'valor_centavos', 'status',
        'nota_fiscal_id', 'conferencia', 'motivo_rejeicao', 'valor_pago_centavos', 'paga_em',
    ];

    protected function casts(): array
    {
        return [
            'competencia' => 'date',
            'paga_em' => 'date',
            'aprovada_em' => 'datetime',
            'conferencia' => 'array',
            'status' => StatusCobranca::class,
        ];
    }

    public function alocacao(): BelongsTo
    {
        return $this->belongsTo(Alocacao::class);
    }

    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function notaFiscal(): BelongsTo
    {
        return $this->belongsTo(NotaFiscal::class);
    }

    public function aprovadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovada_por');
    }

    /** A conferência automática passou em todos os itens obrigatórios? */
    public function conferenciaOk(): bool
    {
        return collect($this->conferencia ?? [])->every(fn ($i) => $i['ok'] || ! ($i['bloqueante'] ?? true));
    }
}
