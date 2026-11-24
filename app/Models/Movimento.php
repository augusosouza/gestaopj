<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Baixa ou estorno. Imutável: não há update nem delete na aplicação.
 * Abatimento da parcela = valor + desconto + retenção − acréscimo.
 */
class Movimento extends Model
{
    use DaOrganizacao;

    public const UPDATED_AT = null;

    protected $fillable = ['organizacao_id', 'parcela_id', 'conta_id', 'tipo', 'data', 'valor_centavos', 'desconto_centavos',
        'retencao_centavos', 'acrescimo_centavos', 'estorno_de_id', 'observacao', 'chave_idempotencia', 'criado_por', 'tarifa_movimento_id', 'tarifa_separada'];

    protected function casts(): array
    {
        return ['data' => 'date', 'tarifa_separada' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Movimentos financeiros são imutáveis. Use estorno.'));
        static::deleting(fn () => throw new \LogicException('Movimentos financeiros são imutáveis. Use estorno.'));
    }

    public function parcela(): BelongsTo
    {
        return $this->belongsTo(Parcela::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_id');
    }

    public function tarifa(): BelongsTo
    {
        return $this->belongsTo(self::class, 'tarifa_movimento_id');
    }

    public function recebimentoDaTarifa(): HasOne
    {
        return $this->hasOne(self::class, 'tarifa_movimento_id');
    }

    public function tarifaEmCreditoLiquido(): bool
    {
        return $this->recebimentoDaTarifa !== null && ! $this->recebimentoDaTarifa->tarifa_separada;
    }

    public function valorConciliavel(): int
    {
        $efeito = $this->efeitoNaConta();
        if ($this->tarifa && ! $this->tarifa_separada) {
            return $efeito - $this->tarifa->valor_centavos;
        }

        return $efeito;
    }

    public function estornoDe(): BelongsTo
    {
        return $this->belongsTo(Movimento::class, 'estorno_de_id');
    }

    public function estorno(): HasOne
    {
        return $this->hasOne(Movimento::class, 'estorno_de_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function linhaExtrato(): HasOne
    {
        return $this->hasOne(ExtratoLinha::class);
    }

    public function abatimento(): int
    {
        return $this->valor_centavos + $this->desconto_centavos + $this->retencao_centavos - $this->acrescimo_centavos;
    }

    /** + entrada / − saída na conta. */
    public function efeitoNaConta(): int
    {
        $base = $this->tipo === 'baixa' ? $this : $this->estornoDe;
        $sinal = $base->parcela->titulo->receber() ? 1 : -1;

        return $this->tipo === 'baixa' ? $sinal * $this->valor_centavos : -$sinal * $base->valor_centavos;
    }

    public function estornado(): bool
    {
        return $this->tipo === 'baixa' && $this->estorno !== null;
    }
}
