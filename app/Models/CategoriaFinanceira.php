<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;

class CategoriaFinanceira extends Model
{
    use DaOrganizacao;

    protected $table = 'categorias_financeiras';

    protected $fillable = ['organizacao_id', 'tipo', 'nome', 'chave', 'ativa'];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }

    /** Conjunto inicial de cada empresa. "chave" identifica as usadas pela integração. */
    public const PADRAO = [
        ['receita', 'Receita de serviços', 'servicos'],
        ['receita', 'Rendimentos financeiros', null],
        ['receita', 'Outras receitas', null],
        ['despesa', 'Prestadores de serviço', 'prestadores'],
        ['despesa', 'Folha e pró-labore', null],
        ['despesa', 'Impostos e taxas', null],
        ['despesa', 'Aluguel e condomínio', null],
        ['despesa', 'Software e assinaturas', null],
        ['despesa', 'Tarifas bancárias', 'tarifas'],
        ['despesa', 'Juros e encargos financeiros', 'encargos'],
        ['despesa', 'Contabilidade e jurídico', null],
        ['despesa', 'Marketing', null],
        ['despesa', 'Viagens e deslocamento', null],
        ['despesa', 'Outras despesas', null],
    ];
}
