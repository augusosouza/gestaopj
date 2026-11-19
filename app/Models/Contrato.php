<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contrato extends Model
{
    protected $fillable = [
        'empresa_id', 'cliente_id', 'numero', 'descricao', 'codigo_servico',
        'valor_mensal_centavos', 'dia_vencimento', 'inicio', 'fim', 'status',
    ];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fim' => 'date'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function alocacoes(): HasMany
    {
        return $this->hasMany(Alocacao::class);
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class);
    }

    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class);
    }

    /** Contratos ativos que cobrem a competência informada. */
    public function scopeVigenteEm(Builder $q, \DateTimeInterface $competencia): Builder
    {
        $fimDoMes = CarbonImmutable::instance($competencia)->endOfMonth();

        return $q->where('status', 'ativo')
            ->whereDate('inicio', '<=', $fimDoMes)
            ->where(fn (Builder $w) => $w->whereNull('fim')->orWhereDate('fim', '>=', $competencia));
    }
}
