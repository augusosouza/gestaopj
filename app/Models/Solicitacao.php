<?php

namespace App\Models;

use App\Enums\StatusSolicitacao;
use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Solicitacao extends Model
{
    use DaOrganizacao;

    protected $table = 'solicitacoes';

    protected $fillable = ['organizacao_id', 'competencia_contabil_id', 'titulo', 'descricao', 'prazo', 'status',
        'resposta', 'criada_por', 'respondida_por', 'respondida_em', 'resolvida_por', 'resolvida_em'];

    protected function casts(): array
    {
        return ['prazo' => 'date', 'status' => StatusSolicitacao::class, 'respondida_em' => 'datetime', 'resolvida_em' => 'datetime'];
    }

    public function competenciaContabil(): BelongsTo
    {
        return $this->belongsTo(CompetenciaContabil::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoContabil::class);
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    public function respondidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondida_por');
    }

    public function atrasada(): bool
    {
        return $this->status === StatusSolicitacao::Aberta && $this->prazo && $this->prazo->lt(now()->startOfDay());
    }
}
