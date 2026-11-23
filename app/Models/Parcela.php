<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Parcela extends Model
{
    use DaOrganizacao;

    protected $fillable = ['organizacao_id', 'titulo_id', 'numero', 'vencimento', 'valor_centavos', 'fatura_cartao_id'];

    protected function casts(): array
    {
        return ['vencimento' => 'date'];
    }

    public function titulo(): BelongsTo
    {
        return $this->belongsTo(Titulo::class);
    }

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(FaturaCartao::class, 'fatura_cartao_id');
    }

    public function movimentos(): HasMany
    {
        return $this->hasMany(Movimento::class)->orderBy('id');
    }

    /** Quanto já foi abatido (baixas menos estornos). */
    public function abatido(): int
    {
        return (int) $this->movimentos->sum(fn (Movimento $m) => $m->tipo === 'baixa' ? $m->abatimento() : -$m->estornoDe->abatimento());
    }

    public function saldo(): int
    {
        return $this->valor_centavos - $this->abatido();
    }

    public function vencida(): bool
    {
        return $this->saldo() > 0 && $this->vencimento->lt(now()->startOfDay());
    }
}
