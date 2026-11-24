<?php

namespace App\Models;

use App\Enums\TipoOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organizacao extends Model
{
    protected $table = 'organizacoes';

    protected $fillable = ['tipo', 'razao_social', 'cnpj', 'inscricao_municipal', 'municipio_ibge'];

    protected $hidden = ['ia_radar_chave'];

    protected $attributes = ['assistente_ativo' => true, 'sugestoes_ativas' => true];

    protected function casts(): array
    {
        return ['tipo' => TipoOrganizacao::class, 'assistente_ativo' => 'boolean', 'sugestoes_ativas' => 'boolean', 'ia_radar_ativa' => 'boolean',
            'ia_radar_chave' => 'encrypted', 'ia_radar_limite' => 'integer'];
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
