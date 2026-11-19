<?php

namespace App\Models;

use App\Enums\StatusCobranca;
use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cobranca extends Model
{
    use DaOrganizacao;

    protected $fillable = ['organizacao_id',
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

    /** Itens não atendidos de uma natureza (fiscal, comercial, verificacao, aviso). */
    public function falhas(string $natureza): array
    {
        return collect($this->conferencia ?? [])
            ->filter(fn ($i) => ! $i['ok'] && ($i['natureza'] ?? 'fiscal') === $natureza)
            ->pluck('item')->values()->all();
    }

    /** Documento inválido para esta cobrança: não pode ser aprovado. */
    public function invalidaFiscalmente(): bool
    {
        return $this->falhas('fiscal') !== [];
    }

    public function temDivergenciaComercial(): bool
    {
        return $this->falhas('comercial') !== [];
    }

    /** Só a chave foi informada: pendência fiscal até o XML ser anexado. */
    public function naoVerificada(): bool
    {
        return $this->falhas('verificacao') !== [];
    }

    /** Passou em tudo que não é mero aviso. */
    public function conferenciaOk(): bool
    {
        return ! $this->invalidaFiscalmente() && ! $this->temDivergenciaComercial() && ! $this->naoVerificada();
    }
}
