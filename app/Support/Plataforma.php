<?php

namespace App\Support;

use App\Models\Configuracao;
use App\Models\Evento;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Identidade da plataforma, editável pelo administrador da plataforma.
 *
 * O valor salvo no banco prevalece sobre o .env (APP_NAME). Lido uma vez e
 * guardado em cache; salvar limpa o cache, então a troca vale na próxima página.
 */
final class Plataforma
{
    public const CACHE = 'plataforma.configuracoes';

    /** chave => [rótulo, máximo de caracteres, obrigatório] */
    public const CAMPOS = [
        'nome_sistema' => ['Nome do sistema', 40, true],
        'slogan' => ['Slogan', 120, false],
        'empresa_responsavel' => ['Desenvolvido por', 80, false],
    ];

    /** @return array<string, string|null> */
    public static function valores(): array
    {
        try {
            return Cache::rememberForever(self::CACHE, fn () => Configuracao::whereIn('chave', array_keys(self::CAMPOS))->pluck('valor', 'chave')->all());
        } catch (\Throwable) {
            return []; // banco ainda sem a tabela (instalação, migrações)
        }
    }

    public static function get(string $chave): ?string
    {
        $v = self::valores()[$chave] ?? null;

        return $v === null || $v === '' ? null : $v;
    }

    /** Aplica o nome salvo à configuração da aplicação (títulos, e-mails, 2FA). */
    public static function aplicar(): void
    {
        if ($nome = self::get('nome_sistema')) {
            config(['app.name' => $nome, 'mail.from.name' => $nome]);
        }
    }

    /**
     * Salva e registra na auditoria só o que mudou.
     *
     * @param  array<string, string|null>  $dados
     * @return array<string, array{de: ?string, para: ?string}>
     */
    public static function salvar(array $dados, User $autor): array
    {
        $mudancas = [];
        DB::transaction(function () use ($dados, $autor, &$mudancas) {
            foreach (array_keys(self::CAMPOS) as $chave) {
                if (! array_key_exists($chave, $dados)) {
                    continue;
                }
                $novo = trim((string) $dados[$chave]);
                $novo = $novo === '' ? null : $novo;
                $registro = Configuracao::firstOrNew(['chave' => $chave]);
                $antigo = $registro->exists ? $registro->valor : ($chave === 'nome_sistema' ? config('app.name') : null);
                if ($registro->exists && $registro->valor === $novo) {
                    continue;
                }
                $registro->fill(['valor' => $novo, 'atualizado_por' => $autor->id])->save();
                if ($antigo !== $novo) {
                    $mudancas[$chave] = ['de' => $antigo, 'para' => $novo];
                }
            }
            if ($mudancas) {
                $registro = Configuracao::where('chave', array_key_first($mudancas))->first();
                Evento::create([
                    'user_id' => $autor->id, 'ip' => request()?->ip(), 'acao' => 'plataforma.configuracao',
                    'alvo_type' => $registro->getMorphClass(), 'alvo_id' => $registro->id, 'dados' => $mudancas,
                ]);
            }
        });
        Cache::forget(self::CACHE);
        self::aplicar();

        return $mudancas;
    }
}
