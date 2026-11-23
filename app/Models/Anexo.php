<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Arquivo anexado a um lançamento ou compra. Fica em disco privado; só sai pela rota autenticada. */
class Anexo extends Model
{
    use DaOrganizacao;

    protected $fillable = ['organizacao_id', 'anexavel_type', 'anexavel_id', 'descricao', 'nome_original', 'caminho', 'mime', 'tamanho', 'sha256', 'enviado_por'];

    public function anexavel(): MorphTo
    {
        return $this->morphTo();
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function tamanhoLegivel(): string
    {
        return $this->tamanho >= 1048576 ? number_format($this->tamanho / 1048576, 1, ',', '.').' MB' : max(1, (int) round($this->tamanho / 1024)).' KB';
    }
}
