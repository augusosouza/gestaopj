<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtratoLinha extends Model
{
    use DaOrganizacao;

    protected $table = 'extrato_linhas';

    protected $fillable = ['organizacao_id', 'conta_id', 'data', 'valor_centavos', 'descricao', 'identificador', 'arquivo',
        'movimento_id', 'transferencia_id', 'conciliado_por', 'conciliado_em', 'ignorada'];

    protected function casts(): array
    {
        return ['data' => 'date', 'conciliado_em' => 'datetime', 'ignorada' => 'boolean'];
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_id');
    }

    public function movimento(): BelongsTo
    {
        return $this->belongsTo(Movimento::class);
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class);
    }

    public function conciliada(): bool
    {
        return $this->movimento_id !== null || $this->transferencia_id !== null;
    }

    /** Linhas que ainda pedem conferência (sem baixa, sem transferência e não ignoradas). */
    public function scopePendentes($q)
    {
        return $q->whereNull('movimento_id')->whereNull('transferencia_id')->where('ignorada', false);
    }
}
