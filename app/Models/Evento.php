<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Evento extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'acao', 'alvo_type', 'alvo_id', 'dados'];

    protected function casts(): array
    {
        return ['dados' => 'array'];
    }

    public function alvo(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function registrar(string $acao, Model $alvo, array $dados = []): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'acao' => $acao,
            'alvo_type' => $alvo->getMorphClass(),
            'alvo_id' => $alvo->getKey(),
            'dados' => $dados ?: null,
        ]);
    }
}
