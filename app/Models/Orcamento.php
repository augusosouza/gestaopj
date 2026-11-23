<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Orcamento extends Model
{
    use DaOrganizacao;

    protected $fillable = ['organizacao_id', 'competencia', 'categoria_id', 'centro_custo_id', 'valor_centavos', 'criado_por'];

    protected function casts(): array
    {
        return ['competencia' => 'date'];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanceira::class);
    }

    public function centro(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }
}
