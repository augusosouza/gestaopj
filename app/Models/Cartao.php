<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cartão de crédito corporativo. Não guarda número completo, CVV nem senha. */
class Cartao extends Model
{
    use DaOrganizacao;

    protected $table = 'cartoes';

    protected $fillable = ['organizacao_id', 'nome', 'instituicao', 'final', 'responsavel_id', 'limite_centavos',
        'dia_fechamento', 'dia_vencimento', 'conta_pagamento_id', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(FaturaCartao::class)->orderBy('referencia');
    }

    public function contaPagamento(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_pagamento_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function rotulo(): string
    {
        return $this->nome.($this->final ? ' •••• '.$this->final : '');
    }

    /** Parcelas ainda não pagas (de todas as faturas): é o que consome o limite. */
    public function emAberto(): int
    {
        return (int) Parcela::with('movimentos.estornoDe')->whereIn('fatura_cartao_id', $this->faturas()->pluck('id'))
            ->whereHas('titulo', fn ($q) => $q->whereNull('cancelado_em'))->get()->sum(fn (Parcela $p) => $p->saldo());
    }

    public function limiteDisponivel(): int
    {
        return $this->limite_centavos - $this->emAberto();
    }
}
