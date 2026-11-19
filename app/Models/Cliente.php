<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use DaOrganizacao;

    protected $fillable = ['organizacao_id', 'razao_social', 'documento', 'email', 'municipio_ibge'];

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
    }
}
