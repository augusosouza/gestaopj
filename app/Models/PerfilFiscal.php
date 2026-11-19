<?php

namespace App\Models;

use App\Enums\RegimeTributario;
use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerfilFiscal extends Model
{
    use DaOrganizacao;

    protected $table = 'perfis_fiscais';

    protected $fillable = ['organizacao_id', 'cnpj', 'regime', 'vigencia_inicio', 'vigencia_fim', 'observacao'];

    protected function casts(): array
    {
        return [
            'regime' => RegimeTributario::class,
            'vigencia_inicio' => 'date',
            'vigencia_fim' => 'date',
            'confirmado_em' => 'datetime',
        ];
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    /** Perfil vigente de um CNPJ em uma data (ex.: competência). */
    public static function vigente(int $organizacaoId, string $cnpj, \DateTimeInterface $data): ?self
    {
        return static::query()
            ->where('organizacao_id', $organizacaoId)
            ->where('cnpj', $cnpj)
            ->whereDate('vigencia_inicio', '<=', $data)
            ->where(fn (Builder $q) => $q->whereNull('vigencia_fim')->orWhereDate('vigencia_fim', '>=', $data))
            ->orderByDesc('vigencia_inicio')
            ->first();
    }
}
