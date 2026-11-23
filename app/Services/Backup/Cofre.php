<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Criptografia do pacote com libsodium (incluída no PHP).
 *
 * Chave derivada da senha (Argon2id) e cifra autenticada XChaCha20-Poly1305 em blocos
 * de 1 MB: arquivos grandes não são carregados inteiros na memória, e qualquer
 * alteração no pacote é detectada na restauração.
 *
 * Formato: "AUGIBK01" | sal(16) | verificador(16) | cabeçalho(24) | [tamanho(4, big-endian) | bloco cifrado]...
 * O verificador (hash da chave) separa "senha errada" de "pacote adulterado" sem revelar a chave.
 */
final class Cofre
{
    private const MAGICO = 'AUGIBK01';

    private const BLOCO = 1048576;

    public function cifrar(string $origem, string $destino, string $senha): void
    {
        $sal = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $chave = $this->chave($senha, $sal);
        [$estado, $cabecalho] = sodium_crypto_secretstream_xchacha20poly1305_init_push($chave);
        $in = fopen($origem, 'rb');
        $out = fopen($destino, 'wb');
        fwrite($out, self::MAGICO.$sal.$this->verificador($chave).$cabecalho);
        $tamanho = filesize($origem);
        $lido = 0;
        do {
            $bloco = fread($in, self::BLOCO) ?: '';
            $lido += strlen($bloco);
            $tag = $lido >= $tamanho ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0;
            $c = sodium_crypto_secretstream_xchacha20poly1305_push($estado, $bloco, '', $tag);
            fwrite($out, pack('N', strlen($c)).$c);
        } while ($tag === 0);
        fclose($in);
        fclose($out);
        sodium_memzero($chave);
    }

    /** @param list<string> $senhas tenta cada uma (senha atual e anteriores) */
    public function decifrar(string $origem, string $destino, array $senhas): void
    {
        foreach ($senhas as $senha) {
            try {
                $this->decifrarCom($origem, $destino, $senha);

                return;
            } catch (SenhaIncorreta) {
                continue;
            }
        }
        throw new RuntimeException('Não foi possível abrir o pacote: senha de criptografia incorreta.');
    }

    private function decifrarCom(string $origem, string $destino, string $senha): void
    {
        $in = fopen($origem, 'rb');
        if (fread($in, 8) !== self::MAGICO) {
            throw new RuntimeException('Arquivo não é um backup do Augi.');
        }
        $sal = fread($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $verificador = fread($in, 16);
        $cabecalho = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $chave = $this->chave($senha, $sal);
        if (! hash_equals($verificador, $this->verificador($chave))) {
            fclose($in);
            throw new SenhaIncorreta;
        }
        $estado = sodium_crypto_secretstream_xchacha20poly1305_init_pull($cabecalho, $chave);
        sodium_memzero($chave);
        $out = fopen($destino, 'wb');
        $final = false;
        while (! $final) {
            $len = fread($in, 4);
            if ($len === false || strlen($len) < 4) {
                throw new RuntimeException('Pacote incompleto ou corrompido.');
            }
            $c = fread($in, unpack('N', $len)[1]);
            $r = sodium_crypto_secretstream_xchacha20poly1305_pull($estado, $c);
            if ($r === false) {
                fclose($out);
                // A senha já foi conferida: falha aqui é alteração no conteúdo.
                throw new RuntimeException('Pacote adulterado ou corrompido (falha de autenticação).');
            }
            [$texto, $tag] = $r;
            fwrite($out, $texto);
            $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        fclose($in);
        fclose($out);
    }

    private function verificador(string $chave): string
    {
        return sodium_crypto_generichash('augi-backup-verificador', $chave, 16);
    }

    private function chave(string $senha, string $sal): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $senha, $sal,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }
}
