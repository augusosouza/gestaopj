<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoriaFinanceira extends Model
{
    use DaOrganizacao;

    protected $table = 'categorias_financeiras';

    protected $fillable = ['organizacao_id', 'tipo', 'pai_id', 'grupo_dre', 'nome', 'chave', 'ativa'];

    /** Linhas da DRE gerencial (relatório de gestão — não é a DRE contábil oficial). */
    public const GRUPOS_DRE = [
        'receita_bruta' => ['Receita bruta', 'receita'], 'deducoes' => ['Deduções e impostos sobre a receita', 'despesa'],
        'custos' => ['Custos dos serviços', 'despesa'], 'despesas_operacionais' => ['Despesas operacionais', 'despesa'],
        'receitas_financeiras' => ['Receitas financeiras', 'receita'], 'despesas_financeiras' => ['Despesas financeiras', 'despesa'],
        'outras_receitas' => ['Outras receitas', 'receita'], 'outras_despesas' => ['Outras despesas', 'despesa'],
        'impostos_lucro' => ['IRPJ e CSLL', 'despesa'],
    ];

    /** Linha da DRE das categorias padrão (as demais herdam ou usam o padrão do tipo). */
    public const DRE_PADRAO = ['Prestadores de serviço' => 'custos', 'Tarifas bancárias' => 'despesas_financeiras', 'Juros e encargos financeiros' => 'despesas_financeiras',
        'Rendimentos financeiros' => 'receitas_financeiras', 'Impostos e taxas' => 'deducoes', 'Outras receitas' => 'outras_receitas'];

    /** Grupo efetivo: o próprio, o da mãe, ou o padrão do tipo. */
    public function grupoDre(): string
    {
        return $this->grupo_dre ?? $this->pai?->grupoDre() ?? ($this->tipo === 'receita' ? 'receita_bruta' : 'despesas_operacionais');
    }

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
