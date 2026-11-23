<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtratoImportacao extends Model
{
    use DaOrganizacao;

    public const UPDATED_AT = null;

    protected $table = 'extrato_importacoes';

    protected $fillable = ['organizacao_id', 'conta_id', 'arquivo', 'periodo_inicio', 'periodo_fim', 'linhas_novas', 'linhas_repetidas', 'enviado_por'];

    protected function casts(): array
    {
        return ['periodo_inicio' => 'date', 'periodo_fim' => 'date'];
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
