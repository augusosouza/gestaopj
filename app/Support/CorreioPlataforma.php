<?php

namespace App\Support;

use App\Models\Configuracao;
use App\Models\Evento;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class CorreioPlataforma
{
    private const CHAVE = 'smtp_configuracao';

    public function salvo(): array
    {
        try {
            $valor = Configuracao::where('chave', self::CHAVE)->value('valor');
        } catch (QueryException) {
            return [];
        }

        return $valor ? json_decode(Crypt::decryptString($valor), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    public function disponivel(): bool
    {
        return filled(config('mail.painel_host'));
    }

    public function transporte(): ?array
    {
        if (! $this->disponivel()) {
            return null;
        }
        $dados = $this->salvo();
        if (! ($dados['ativo'] ?? false)) {
            return null;
        }

        return [
            'transport' => 'smtp',
            'host' => config('mail.painel_host'),
            'port' => $dados['porta'],
            'scheme' => $dados['porta'] === 465 ? 'smtps' : 'smtp',
            'require_tls' => true,
            'username' => $dados['usuario'] ?: null,
            'password' => $dados['senha'] ?: null,
            'timeout' => 10,
            'from' => ['address' => $dados['remetente'], 'name' => $dados['nome'] ?: config('app.name')],
        ];
    }

    public function salvar(array $campos, User $autor): void
    {
        DB::transaction(function () use ($campos, $autor) {
            Configuracao::query()->insertOrIgnore(['chave' => self::CHAVE, 'created_at' => now(), 'updated_at' => now()]);
            $registro = Configuracao::where('chave', self::CHAVE)->lockForUpdate()->firstOrFail();
            $anterior = $registro->valor ? json_decode(Crypt::decryptString($registro->valor), true, flags: JSON_THROW_ON_ERROR) : [];
            $dados = [
                'ativo' => (bool) $campos['ativo'],
                'porta' => (int) $campos['porta'],
                'remetente' => $campos['remetente'],
                'nome' => $campos['nome'] ?? '',
                'usuario' => $campos['usuario'] ?? '',
                'senha' => $campos['remover_senha'] || blank($campos['usuario'] ?? null)
                    ? '' : ($campos['smtp_password'] ?? $anterior['senha'] ?? ''),
            ];
            $registro->fill(['valor' => Crypt::encryptString(json_encode($dados, JSON_THROW_ON_ERROR)), 'atualizado_por' => $autor->id])->save();
            Evento::create(['user_id' => $autor->id, 'ip' => request()->ip(), 'acao' => 'plataforma.smtp_configurado',
                'alvo_type' => $registro->getMorphClass(), 'alvo_id' => $registro->id, 'dados' => ['ativo' => $dados['ativo']]]);
        });
    }
}
