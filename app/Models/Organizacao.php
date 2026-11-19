<?php

namespace App\Models;

use App\Enums\TipoOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organizacao extends Model
{
    protected $table = 'organizacoes';

    protected $fillable = ['tipo', 'razao_social', 'cnpj', 'inscricao_municipal', 'municipio_ibge'];

    protected function casts(): array
    {
        return ['tipo' => TipoOrganizacao::class];
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(Vinculo::class);
    }

    /** Autorizações que esta empresa concedeu a escritórios. */
    public function autorizacoesConcedidas(): HasMany
    {
        return $this->hasMany(AutorizacaoEscritorio::class, 'empresa_id');
    }

    /** Autorizações recebidas por este escritório. */
    public function autorizacoesRecebidas(): HasMany
    {
        return $this->hasMany(AutorizacaoEscritorio::class, 'escritorio_id');
    }

    public function ehEscritorio(): bool
    {
        return $this->tipo === TipoOrganizacao::Escritorio;
    }
}
