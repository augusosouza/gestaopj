<?php

namespace App\Support;

use App\Enums\Papel;
use App\Models\Atribuicao;
use App\Models\AutorizacaoEscritorio;
use App\Models\Organizacao;
use App\Models\User;
use App\Models\Vinculo;

/**
 * Contexto ativo da requisição: qual vínculo o usuário está usando e,
 * quando houver, qual empresa está sendo acessada.
 *
 * Estados:
 *  - inativo: fora de uma requisição autenticada (console, seeders). Sem escopo.
 *  - ativo sem empresa: escolha de contexto ou carteira do escritório.
 *    Consultas a modelos da empresa retornam vazio (falha fechada).
 *  - ativo com empresa: consultas filtradas pela empresa.
 *
 * Revalidado em toda requisição: revogar um vínculo, autorização ou
 * atribuição corta o acesso na próxima ação.
 */
final class Contexto
{
    private bool $ativo = false;

    private ?Vinculo $vinculo = null;

    private ?Organizacao $empresa = null;

    public function ativo(): bool
    {
        return $this->ativo;
    }

    /** Organização usada para filtrar os dados (null = nenhuma). */
    public function organizacaoId(): ?int
    {
        return $this->empresa?->id;
    }

    public function empresa(): ?Organizacao
    {
        return $this->empresa;
    }

    public function vinculo(): ?Vinculo
    {
        return $this->vinculo;
    }

    public function papel(): ?Papel
    {
        return $this->vinculo?->papel;
    }

    public function tem(Papel ...$papeis): bool
    {
        return $this->vinculo !== null && in_array($this->vinculo->papel, $papeis, true);
    }

    public function ehPrestador(): bool
    {
        return $this->tem(Papel::Prestador);
    }

    public function ehInterno(): bool
    {
        return $this->vinculo !== null && ! $this->ehPrestador();
    }

    public function ehEscritorio(): bool
    {
        return $this->vinculo?->papel->deEscritorio() ?? false;
    }

    public function prestadorId(): ?int
    {
        return $this->vinculo?->prestador_id;
    }

    /**
     * Ativa o contexto a partir do que está na sessão.
     * Retorna false se o vínculo ou o acesso à empresa não for mais válido.
     *
     * @param  array{vinculo?: int, empresa?: int|null}|null  $sessao
     */
    public function ativar(User $user, ?array $sessao): bool
    {
        $this->ativo = true;
        $this->vinculo = null;
        $this->empresa = null;

        if (! $sessao || empty($sessao['vinculo'])) {
            return false;
        }
        $vinculo = Vinculo::vigentes()->with('organizacao')->where('user_id', $user->id)->find($sessao['vinculo']);
        if (! $vinculo) {
            return false;
        }
        $this->vinculo = $vinculo;

        if (! $vinculo->papel->deEscritorio()) {
            $this->empresa = $vinculo->organizacao;

            return true;
        }

        // Escritório: empresa opcional (carteira); se escolhida, precisa estar acessível.
        if (! empty($sessao['empresa'])) {
            if (! self::escritorioAcessa($vinculo, (int) $sessao['empresa'])) {
                return false;
            }
            $this->empresa = Organizacao::find($sessao['empresa']);
        }

        return true;
    }

    public function desativar(): void
    {
        $this->ativo = false;
        $this->vinculo = null;
        $this->empresa = null;
    }

    /** Empresa autorizou o escritório e, se analista, a empresa está atribuída a ele. */
    public static function escritorioAcessa(Vinculo $vinculo, int $empresaId): bool
    {
        if (! $vinculo->papel->deEscritorio()) {
            return false;
        }
        $autorizada = AutorizacaoEscritorio::vigentes()
            ->where('escritorio_id', $vinculo->organizacao_id)
            ->where('empresa_id', $empresaId)
            ->exists();
        if (! $autorizada) {
            return false;
        }
        if ($vinculo->papel === Papel::Revisor) {
            return true;
        }

        return Atribuicao::vigentes()
            ->where('escritorio_id', $vinculo->organizacao_id)
            ->where('empresa_id', $empresaId)
            ->where('user_id', $vinculo->user_id)
            ->exists();
    }
}
