<?php

namespace App\Models;

use App\Enums\Papel;
use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VisaoSalva extends Model
{
    use DaOrganizacao;

    protected $table = 'visoes_salvas';

    protected $fillable = ['modulo', 'nome', 'filtros', 'user_id'];

    protected function casts(): array
    {
        return ['filtros' => 'array', 'compartilhada' => 'boolean'];
    }

    public static function pessoais(string $modulo): Builder
    {
        return static::query()->where('organizacao_id', ctx()->organizacaoId())
            ->where('user_id', auth()->id())->where('modulo', $modulo);
    }

    public static function equipe(string $modulo): Builder
    {
        return static::query()->where('organizacao_id', ctx()->organizacaoId())->where('modulo', $modulo)
            ->where('compartilhada', true)->where('user_id', '!=', auth()->id())
            ->whereIn('user_id', Vinculo::vigentes()->where('organizacao_id', ctx()->organizacaoId())
                ->whereIn('papel', $modulo === 'clientes' ? [Papel::Admin] : [Papel::Admin, Papel::Financeiro])->select('user_id'));
    }
}
