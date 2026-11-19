<?php

namespace App\Models;

use App\Enums\TipoPrestador;
use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prestador extends Model
{
    use DaOrganizacao;

    protected $table = 'prestadores';

    protected $fillable = ['organizacao_id', 'nome', 'tipo', 'documento', 'email', 'chave_pix'];

    protected function casts(): array
    {
        return ['tipo' => TipoPrestador::class];
    }

    public function alocacoes(): HasMany
    {
        return $this->hasMany(Alocacao::class);
    }

    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class);
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(Vinculo::class);
    }
}
