<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    protected $fillable = ['razao_social', 'cnpj', 'inscricao_municipal', 'municipio_ibge'];

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
    }
}
