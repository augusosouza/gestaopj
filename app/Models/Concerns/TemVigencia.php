<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Vigência de vínculos, autorizações e atribuições.
 * "fim" é a data de encerramento: a partir dela o acesso deixa de valer,
 * então encerrar hoje corta o acesso imediatamente.
 */
trait TemVigencia
{
    public function scopeVigentes(Builder $q): Builder
    {
        $hoje = now()->toDateString();

        return $q->whereDate($q->qualifyColumn('inicio'), '<=', $hoje)
            ->where(fn (Builder $w) => $w->whereNull($q->qualifyColumn('fim'))->orWhereDate($q->qualifyColumn('fim'), '>', $hoje));
    }

    public function vigente(): bool
    {
        $hoje = now()->startOfDay();

        return $this->inicio->lte($hoje) && ($this->fim === null || $this->fim->gt($hoje));
    }
}
