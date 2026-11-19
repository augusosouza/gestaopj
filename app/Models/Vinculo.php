<?php

namespace App\Models;

use App\Enums\Papel;
use App\Models\Concerns\TemVigencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vinculo extends Model
{
    use TemVigencia;

    protected $fillable = ['user_id', 'organizacao_id', 'papel', 'prestador_id', 'inicio', 'fim', 'criado_por', 'encerrado_por'];

    protected function casts(): array
    {
        return ['papel' => Papel::class, 'inicio' => 'date', 'fim' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organizacao(): BelongsTo
    {
        return $this->belongsTo(Organizacao::class);
    }

    public function prestador(): BelongsTo
    {
        // Sem o escopo de contexto: o vínculo é lido antes de a empresa ser escolhida.
        return $this->belongsTo(Prestador::class)->withoutGlobalScope('organizacao');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
