<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TituloRateio extends Model
{
    use DaOrganizacao;

    protected $table = 'titulo_rateios';

    protected $fillable = ['organizacao_id', 'titulo_id', 'centro_custo_id', 'percentual_bp', 'valor_centavos'];

    public function centro(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function titulo(): BelongsTo
    {
        return $this->belongsTo(Titulo::class);
    }
}
