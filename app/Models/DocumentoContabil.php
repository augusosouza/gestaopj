<?php

namespace App\Models;

use App\Enums\CategoriaDocumento;
use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoContabil extends Model
{
    use DaOrganizacao;

    protected $table = 'documentos_contabeis';

    protected $fillable = ['organizacao_id', 'competencia_contabil_id', 'solicitacao_id', 'categoria', 'descricao',
        'nome_original', 'arquivo_path', 'tamanho', 'sha256', 'enviado_por'];

    protected function casts(): array
    {
        return ['categoria' => CategoriaDocumento::class];
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function tamanhoLegivel(): string
    {
        return $this->tamanho >= 1048576 ? number_format($this->tamanho / 1048576, 1, ',', '.').' MB'
            : max(1, (int) round($this->tamanho / 1024)).' KB';
    }
}
