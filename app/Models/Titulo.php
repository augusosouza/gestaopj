<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Obrigação a receber ou a pagar. Competência fica aqui; vencimento nas parcelas; liquidação nos movimentos. */
class Titulo extends Model
{
    use DaOrganizacao;

    protected $fillable = ['organizacao_id', 'tipo', 'descricao', 'categoria_id', 'contraparte', 'competencia',
        'valor_total_centavos', 'documento', 'forma', 'origem_type', 'origem_id', 'cancelado_em', 'motivo_cancelamento', 'criado_por'];

    public const FORMAS = ['pix' => 'Pix', 'boleto' => 'Boleto', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro',
        'cartao_debito' => 'Cartão de débito', 'cartao_credito' => 'Cartão de crédito', 'deposito' => 'Depósito', 'outro' => 'Outro'];

    protected function casts(): array
    {
        return ['competencia' => 'date', 'cancelado_em' => 'datetime'];
    }

    public function parcelas(): HasMany
    {
        return $this->hasMany(Parcela::class)->orderBy('numero');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanceira::class);
    }

    public function origem(): MorphTo
    {
        return $this->morphTo();
    }

    public function receber(): bool
    {
        return $this->tipo === 'receber';
    }

    public function cancelado(): bool
    {
        return $this->cancelado_em !== null;
    }

    public function saldo(): int
    {
        return (int) $this->parcelas->sum(fn (Parcela $p) => $p->saldo());
    }

    public function liquidado(): int
    {
        return $this->valor_total_centavos - $this->saldo();
    }

    /** Soma de um campo das baixas ativas (não estornadas). */
    private function somaBaixas(string $campo): int
    {
        return (int) $this->parcelas->flatMap->movimentos
            ->filter(fn (Movimento $m) => $m->tipo === 'baixa' && ! $m->estornado())->sum($campo);
    }

    /** Dinheiro que efetivamente entrou/saiu da conta. */
    public function pagoEmDinheiro(): int
    {
        return $this->somaBaixas('valor_centavos');
    }

    public function descontos(): int
    {
        return $this->somaBaixas('desconto_centavos');
    }

    public function retencoes(): int
    {
        return $this->somaBaixas('retencao_centavos');
    }

    public function acrescimos(): int
    {
        return $this->somaBaixas('acrescimo_centavos');
    }

    /** aberto, parcial, liquidado, cancelado */
    public function situacao(): string
    {
        if ($this->cancelado()) {
            return 'cancelado';
        }
        $saldo = $this->saldo();

        return $saldo === 0 ? 'liquidado' : ($saldo < $this->valor_total_centavos ? 'parcial' : 'aberto');
    }

    public function vencido(): bool
    {
        return ! $this->cancelado() && $this->parcelas->contains(fn (Parcela $p) => $p->vencida());
    }

    public function anexos(): MorphMany
    {
        return $this->morphMany(Anexo::class, 'anexavel')->latest('id');
    }

    public function rateios(): HasMany
    {
        return $this->hasMany(TituloRateio::class)->orderByDesc('valor_centavos');
    }
}
