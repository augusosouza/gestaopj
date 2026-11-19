<?php

namespace App\Models;

use App\Enums\TipoPrestador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prestador extends Model
{
    protected $table = 'prestadores';

    protected $fillable = ['nome', 'tipo', 'documento', 'email', 'chave_pix'];

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

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
