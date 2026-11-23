<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Autorização do Google Agenda de um usuário numa empresa. Tokens criptografados com a APP_KEY. */
class GoogleConexao extends Model
{
    use DaOrganizacao;

    protected $table = 'google_conexoes';

    protected $fillable = ['organizacao_id', 'user_id', 'email_google', 'access_token', 'refresh_token', 'token_expira_em', 'escopos', 'modo',
        'calendar_id', 'calendar_nome', 'sincronizar', 'titulos_discretos', 'ao_quitar', 'status', 'ultimo_erro', 'sincronizado_em', 'pausada_ate'];

    protected $hidden = ['access_token', 'refresh_token'];

    /** Mesmos padrões do banco, já no objeto: título discreto vale desde a criação (privacidade). */
    protected $attributes = ['status' => 'ativa', 'modo' => 'dedicada', 'titulos_discretos' => true, 'ao_quitar' => 'remover'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'token_expira_em' => 'datetime', 'sincronizar' => 'array',
            'titulos_discretos' => 'boolean', 'sincronizado_em' => 'datetime', 'pausada_ate' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(GoogleEvento::class);
    }

    public function sincroniza(string $tipo): bool
    {
        return (bool) (($this->sincronizar ?? ['pagar' => true])[$tipo] ?? false);
    }
}
