<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoriaFinanceira extends Model
{
    use DaOrganizacao;

    protected $table = 'categorias_financeiras';

    protected $fillable = ['organizacao_id', 'tipo', 'pai_id', 'nome', 'chave', 'ativa'];

    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pai_id');
    }

    /** Categoria principal (o topo da hierarquia). */
    public function raiz(): self
    {
        return $this->pai ? $this->pai->raiz() : $this;
    }

    /** "Tecnologia → Servidores": caminho completo para exibir. */
    public function caminho(): string
    {
        return $this->pai ? $this->pai->caminho().' → '.$this->nome : $this->nome;
    }

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
