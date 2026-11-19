<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alocacao extends Model
{
    protected $table = 'alocacoes';

    protected $fillable = ['contrato_id', 'prestador_id', 'descricao', 'valor_mensal_centavos', 'inicio', 'fim'];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fim' => 'date'];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    public function scopeVigenteEm(Builder $q, \DateTimeInterface $competencia): Builder
    {
        $fimDoMes = CarbonImmutable::instance($competencia)->endOfMonth();

        return $q->whereDate('inicio', '<=', $fimDoMes)
            ->where(fn (Builder $w) => $w->whereNull('fim')->orWhereDate('fim', '>=', $competencia));
    }
}
