<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;

class CompraItem extends Model
{
    use DaOrganizacao;

    public $timestamps = false;

    protected $table = 'compra_itens';

    protected $fillable = ['organizacao_id', 'compra_id', 'descricao', 'quantidade', 'valor_unitario_centavos', 'total_centavos'];
}
