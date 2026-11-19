<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $fillable = ['razao_social', 'documento', 'email', 'municipio_ibge'];

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
    }
}
