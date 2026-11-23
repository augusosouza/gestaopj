<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** Aquisição de produtos ou serviços. O dinheiro fica no título que ela origina. */
class Compra extends Model
{
    use DaOrganizacao;

    public const MODALIDADES = ['pix' => 'Pix', 'boleto' => 'Boleto', 'carne' => 'Carnê', 'cartao' => 'Cartão de crédito',
        'financiamento' => 'Financiamento', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro', 'outro' => 'Outro'];

    protected $fillable = ['organizacao_id', 'descricao', 'fornecedor', 'fornecedor_documento', 'data_compra', 'valor_bruto_centavos',
        'desconto_centavos', 'juros_centavos', 'total_centavos', 'entrada_centavos', 'categoria_id', 'modalidade', 'conta_id', 'cartao_id',
        'nota_tipo', 'nota_numero', 'nota_chave', 'garantia_ate', 'observacao', 'cancelada_em', 'motivo_cancelamento', 'criado_por'];

    protected function casts(): array
    {
        return ['data_compra' => 'date', 'garantia_ate' => 'date', 'cancelada_em' => 'datetime'];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CompraItem::class)->orderBy('id');
    }

    public function titulo(): MorphOne
    {
        return $this->morphOne(Titulo::class, 'origem');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanceira::class);
    }

    public function cartao(): BelongsTo
    {
        return $this->belongsTo(Cartao::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_id');
    }

    public function cancelada(): bool
    {
        return $this->cancelada_em !== null;
    }

    public function noCartao(): bool
    {
        return $this->modalidade === 'cartao';
    }
}
