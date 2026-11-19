<?php

namespace App\Models;

use App\Models\Concerns\TemVigencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutorizacaoEscritorio extends Model
{
    use TemVigencia;

    protected $table = 'autorizacoes_escritorio';

    protected $fillable = ['empresa_id', 'escritorio_id', 'inicio', 'fim', 'concedida_por', 'revogada_por'];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fim' => 'date'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Organizacao::class, 'empresa_id');
    }

    public function escritorio(): BelongsTo
    {
        return $this->belongsTo(Organizacao::class, 'escritorio_id');
    }

    public function concedidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concedida_por');
    }
}
