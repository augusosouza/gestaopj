<?php

namespace App\Models;

use App\Enums\EtapaContabil;
use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetenciaContabil extends Model
{
    use DaOrganizacao;

    protected $table = 'competencias_contabeis';

    protected $fillable = ['organizacao_id', 'competencia', 'etapa', 'enviada_em', 'enviada_por', 'versao_atual'];

    protected function casts(): array
    {
        return ['competencia' => 'date', 'etapa' => EtapaContabil::class, 'enviada_em' => 'datetime'];
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoContabil::class);
    }

    public function solicitacoes(): HasMany
    {
        return $this->hasMany(Solicitacao::class);
    }

    public function versoes(): HasMany
    {
        return $this->hasMany(VersaoFechamento::class);
    }

    public function enviadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviada_por');
    }

    public function fechada(): bool
    {
        return $this->etapa === EtapaContabil::Fechada;
    }

    public function pendentes(): int
    {
        return $this->solicitacoes->filter(fn (Solicitacao $s) => $s->status->pendente())->count();
    }

    public function mes(): string
    {
        return $this->competencia->format('Y-m');
    }
}
