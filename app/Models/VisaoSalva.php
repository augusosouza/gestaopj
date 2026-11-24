<?php

namespace App\Models;

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
        return ['filtros' => 'array'];
    }

    public static function pessoais(string $modulo): Builder
    {
        return static::query()->where('organizacao_id', ctx()->organizacaoId())
            ->where('user_id', auth()->id())->where('modulo', $modulo);
    }
}
