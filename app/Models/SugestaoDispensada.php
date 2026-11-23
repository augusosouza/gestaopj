<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;

class SugestaoDispensada extends Model
{
    use DaOrganizacao;

    protected $table = 'sugestoes_dispensadas';

    protected $fillable = ['organizacao_id', 'chave', 'ate', 'motivo', 'user_id'];

    protected function casts(): array
    {
        return ['ate' => 'date'];
    }
}
