<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;

class ClienteRelacionamento extends Model
{
    use DaOrganizacao;

    public const FINALIDADES = [
        'contato' => ['principal' => 'Principal', 'financeiro' => 'Financeiro', 'cobranca' => 'Cobrança', 'fiscal' => 'Fiscal',
            'comercial' => 'Comercial', 'administrativo' => 'Administrativo', 'tecnico' => 'Técnico', 'outros' => 'Outros'],
        'endereco' => ['principal' => 'Principal', 'cobranca' => 'Cobrança', 'fiscal' => 'Fiscal',
            'correspondencia' => 'Correspondência', 'servico' => 'Prestação de serviço'],
    ];

    public const CAMPOS = [
        'contato' => ['nome' => 'Nome', 'funcao' => 'Função', 'email' => 'E-mail', 'telefone' => 'Telefone', 'whatsapp' => 'WhatsApp', 'departamento' => 'Departamento'],
        'endereco' => ['cep' => 'CEP', 'logradouro' => 'Logradouro', 'numero' => 'Número', 'complemento' => 'Complemento', 'bairro' => 'Bairro',
            'municipio' => 'Município', 'uf' => 'UF', 'ibge' => 'Código IBGE', 'pais' => 'País'],
    ];

    protected $fillable = ['tipo', 'finalidade', 'dados', 'ativo'];

    protected function casts(): array
    {
        return ['dados' => 'array', 'ativo' => 'boolean'];
    }
}
