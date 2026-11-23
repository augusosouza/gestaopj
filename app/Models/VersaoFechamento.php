<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retrato imutável de um fechamento: o pacote gerado nunca é sobrescrito. */
class VersaoFechamento extends Model
{
    use DaOrganizacao;

    public const UPDATED_AT = null;

    protected $table = 'fechamentos';

    protected $fillable = ['organizacao_id', 'competencia_contabil_id', 'versao', 'arquivo_path', 'sha256', 'resumo', 'observacao', 'fechado_por'];

    protected function casts(): array
    {
        return ['resumo' => 'array'];
    }

    public function fechadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechado_por');
    }
}
