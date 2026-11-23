<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Dinheiro entre duas contas da mesma empresa. Imutável: estorno = transferência inversa. */
class Transferencia extends Model
{
    use DaOrganizacao;

    public const UPDATED_AT = null;

    protected $fillable = ['organizacao_id', 'conta_origem_id', 'conta_destino_id', 'data', 'valor_centavos', 'descricao',
        'estorno_de_id', 'chave_idempotencia', 'criado_por'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Transferências são imutáveis. Use estorno.'));
        static::deleting(fn () => throw new \LogicException('Transferências são imutáveis. Use estorno.'));
    }

    public function origem(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_origem_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_destino_id');
    }

    public function estornoDe(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class, 'estorno_de_id');
    }

    public function estorno(): HasOne
    {
        return $this->hasOne(Transferencia::class, 'estorno_de_id');
    }

    public function linhasExtrato(): HasMany
    {
        return $this->hasMany(ExtratoLinha::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /** Efeito na conta informada: + entrou, − saiu, 0 não envolve. */
    public function efeitoEm(int $contaId): int
    {
        return match ($contaId) {
            $this->conta_destino_id => $this->valor_centavos,
            $this->conta_origem_id => -$this->valor_centavos,
            default => 0,
        };
    }
}
