<?php

namespace App\Models;

use App\Models\Concerns\TemVigencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Atribuicao extends Model
{
    use TemVigencia;

    protected $table = 'atribuicoes';

    protected $fillable = ['escritorio_id', 'empresa_id', 'user_id', 'inicio', 'fim', 'criado_por', 'encerrado_por'];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fim' => 'date'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Organizacao::class, 'empresa_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
