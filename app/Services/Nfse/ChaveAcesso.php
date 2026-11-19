<?php

namespace App\Services\Nfse;

/**
 * Chave de acesso da NFS-e nacional (50 dígitos).
 *
 * Composição (leiaute do Emissor Nacional):
 *  cMun(7) + ambGer(1) + tpInscFed(1) + inscFed(14) + nNFSe(13) + AAMM(4) + cNum(9) + DV(1)
 *
 * A decomposição é usada apenas como verificação auxiliar (aviso), nunca
 * como única fonte: a fonte de verdade é o XML assinado.
 */
final class ChaveAcesso
{
    private function __construct(public readonly string $valor) {}

    public static function tentar(?string $entrada): ?self
    {
        $v = preg_replace('/\D/', '', (string) $entrada);

        return strlen($v) === 50 ? new self($v) : null;
    }

    public function municipio(): string
    {
        return substr($this->valor, 0, 7);
    }

    public function inscricaoFederal(): string
    {
        $insc = substr($this->valor, 9, 14);

        // CPF vem completado com zeros à esquerda
        return substr($this->valor, 8, 1) === '1' ? substr($insc, 3) : $insc;
    }

    public function numero(): string
    {
        return ltrim(substr($this->valor, 23, 13), '0') ?: '0';
    }

    /** AAMM da emissão. */
    public function anoMes(): string
    {
        return substr($this->valor, 36, 4);
    }
}
