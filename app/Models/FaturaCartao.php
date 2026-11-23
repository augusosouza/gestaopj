<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fatura do cartão. Não é um título: é o agrupamento das parcelas das compras
 * que vencem nela. Pagar a fatura = baixar essas parcelas (sem duplicar a despesa).
 */
class FaturaCartao extends Model
{
    use DaOrganizacao;

    protected $table = 'faturas_cartao';

    protected $fillable = ['organizacao_id', 'cartao_id', 'referencia', 'fechamento', 'vencimento', 'paga_em', 'paga_por', 'encargos_titulo_id'];

    protected function casts(): array
    {
        return ['referencia' => 'date', 'fechamento' => 'date', 'vencimento' => 'date', 'paga_em' => 'date'];
    }

    public function cartao(): BelongsTo
    {
        return $this->belongsTo(Cartao::class);
    }

    public function parcelas(): HasMany
    {
        return $this->hasMany(Parcela::class, 'fatura_cartao_id')
            ->whereHas('titulo', fn ($q) => $q->whereNull('cancelado_em'))->orderBy('id');
    }

    public function encargos(): BelongsTo
    {
        return $this->belongsTo(Titulo::class, 'encargos_titulo_id');
    }

    public function total(): int
    {
        return (int) $this->parcelas->sum('valor_centavos');
    }

    public function saldo(): int
    {
        return (int) $this->parcelas->sum(fn (Parcela $p) => $p->saldo());
    }

    /** futura, aberta, fechada, vencida, paga, vazia */
    public function situacao(): string
    {
        $hoje = now()->startOfDay();
        $total = $this->total();

        return match (true) {
            $total === 0 => 'vazia',
            $this->saldo() === 0 => 'paga',
            $this->vencimento->lt($hoje) => 'vencida',
            $this->fechamento->lte($hoje) => 'fechada',
            $this->fechamento->copy()->subMonthNoOverflow()->lte($hoje) => 'aberta',
            default => 'futura',
        };
    }

    public function rotulo(): string
    {
        $meses = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

        return $meses[(int) $this->referencia->format('n')].'/'.$this->referencia->format('Y');
    }
}
