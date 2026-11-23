<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;

class CentroCusto extends Model
{
    use DaOrganizacao;

    protected $table = 'centros_custo';

    public const TIPOS = ['departamento' => 'Departamento', 'projeto' => 'Projeto', 'contrato' => 'Contrato', 'filial' => 'Filial'];

    protected $fillable = ['organizacao_id', 'nome', 'codigo', 'tipo', 'contrato_id', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function rotulo(): string
    {
        return ($this->codigo ? $this->codigo.' · ' : '').$this->nome;
    }
}
