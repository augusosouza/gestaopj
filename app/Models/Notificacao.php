<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Notificação interna de um usuário (canal padrão; e-mail entra quando configurado). */
class Notificacao extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'notificacoes';

    protected $fillable = ['organizacao_id', 'user_id', 'tipo', 'titulo', 'mensagem', 'url', 'chave', 'lida_em'];

    protected function casts(): array
    {
        return ['lida_em' => 'datetime'];
    }

    public function organizacao(): BelongsTo
    {
        return $this->belongsTo(Organizacao::class);
    }

    public static function naoLidasDe(User $u): int
    {
        return static::where('user_id', $u->id)->whereNull('lida_em')->count();
    }

    /** Cria só se ainda não existir a mesma chave para o usuário. */
    public static function avisar(int $userId, ?int $orgId, string $tipo, string $chave, string $titulo, string $mensagem, ?string $url): bool
    {
        $n = static::firstOrCreate(['user_id' => $userId, 'chave' => $chave],
            ['organizacao_id' => $orgId, 'tipo' => $tipo, 'titulo' => $titulo, 'mensagem' => $mensagem, 'url' => $url]);

        return $n->wasRecentlyCreated;
    }
}
