<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AlteracaoPix extends Model
{
    use DaOrganizacao;

    protected $table = 'alteracoes_pix';

    protected $fillable = ['organizacao_id', 'prestador_id', 'solicitado_por', 'decidido_por', 'chave_anterior',
        'chave_proposta', 'estado', 'motivo', 'justificativa_decisao', 'decidido_em'];

    protected $hidden = ['chave_anterior', 'chave_proposta'];

    protected function casts(): array
    {
        return ['chave_anterior' => 'encrypted', 'chave_proposta' => 'encrypted', 'decidido_em' => 'datetime'];
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }
}
