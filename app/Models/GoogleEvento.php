<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;

class GoogleEvento extends Model
{
    use DaOrganizacao;

    protected $table = 'google_eventos';

    protected $fillable = ['organizacao_id', 'google_conexao_id', 'alvo', 'alvo_id', 'event_id', 'hash', 'estado', 'sincronizado_em', 'erro'];

    protected function casts(): array
    {
        return ['sincronizado_em' => 'datetime'];
    }
}
