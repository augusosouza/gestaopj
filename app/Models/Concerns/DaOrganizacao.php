<?php

namespace App\Models\Concerns;

use App\Models\Organizacao;
use App\Support\Contexto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isola o modelo pela empresa do contexto ativo.
 *
 * Aplica-se também ao route model binding: um ID de outra empresa
 * resulta em 404, inclusive em downloads e exportações.
 */
trait DaOrganizacao
{
    protected static function bootDaOrganizacao(): void
    {
        static::addGlobalScope('organizacao', function (Builder $q) {
            $ctx = app(Contexto::class);
            if (! $ctx->ativo()) {
                return; // console, filas e seeders
            }
            $id = $ctx->organizacaoId();
            $id === null
                ? $q->whereRaw('1 = 0') // requisição sem empresa escolhida: não vê nada
                : $q->where($q->qualifyColumn('organizacao_id'), $id);
        });

        static::creating(function ($model) {
            if (! $model->organizacao_id) {
                $model->organizacao_id = app(Contexto::class)->organizacaoId()
                    ?? throw new \LogicException('Registro sem organização: '.static::class);
            }
        });
    }

    public function organizacao(): BelongsTo
    {
        return $this->belongsTo(Organizacao::class);
    }
}
