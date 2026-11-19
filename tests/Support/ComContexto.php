<?php

namespace Tests\Support;

use App\Models\Organizacao;
use App\Models\User;
use App\Models\Vinculo;

/** Entra como um usuário do seed, já com o contexto escolhido. */
trait ComContexto
{
    public const A = '11222333000181';

    public const B = '22333444000181';

    protected function como(string $email, string $cnpjEmpresa = self::A): static
    {
        $user = User::where('email', $email)->firstOrFail();
        $empresa = Organizacao::where('cnpj', $cnpjEmpresa)->firstOrFail();

        $direto = Vinculo::vigentes()->where('user_id', $user->id)->where('organizacao_id', $empresa->id)->first();
        $vinculo = $direto ?? Vinculo::vigentes()->where('user_id', $user->id)->firstOrFail();

        return $this->actingAs($user)->withSession(['ctx' => [
            'vinculo' => $vinculo->id,
            'empresa' => $direto ? null : $empresa->id, // escritório acessando a empresa
        ]]);
    }

    protected function empresa(string $cnpj = self::A): Organizacao
    {
        return Organizacao::where('cnpj', $cnpj)->firstOrFail();
    }
}
