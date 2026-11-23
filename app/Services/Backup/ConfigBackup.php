<?php

namespace App\Services\Backup;

use App\Models\Configuracao;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Configuração do backup, editável em Plataforma → Backups.
 *
 * Destinos (todos pelo protocolo S3, puro PHP — funciona em cPanel e em container):
 *  - s3:         Amazon S3
 *  - gcs:        Google Cloud Storage (chaves HMAC de "interoperabilidade")
 *  - compativel: Cloudflare R2, Backblaze B2, Wasabi, MinIO… (informar endpoint)
 *  - local:      pasta no próprio servidor (só desenvolvimento e testes)
 *
 * Segredos (chave secreta e senha de criptografia) ficam criptografados com a APP_KEY.
 * Após perda total do servidor, as mesmas informações podem vir de variáveis BACKUP_*.
 */
final class ConfigBackup
{
    public const DESTINOS = [
        'desligado' => 'Desligado',
        's3' => 'Amazon S3',
        'gcs' => 'Google Cloud Storage',
        'compativel' => 'Outro compatível com S3 (R2, B2, Wasabi, MinIO…)',
    ];

    private const SEGREDOS = ['segredo', 'senha'];

    private const PADRAO = [
        'destino' => 'desligado', 'bucket' => null, 'regiao' => null, 'endpoint' => null, 'prefixo' => 'augi-backups',
        'chave' => null, 'segredo' => null, 'senha' => null, 'senhas_anteriores' => null,
        'horarios' => '06:00,13:00,20:00', 'dias_base' => '7', 'bases_mantidas' => '8', 'solicitado' => null,
    ];

    /** @return array<string, ?string> */
    public function todos(): array
    {
        $salvos = Configuracao::where('chave', 'like', 'backup.%')->pluck('valor', 'chave')
            ->mapWithKeys(fn ($v, $k) => [substr($k, 7) => $v])->all();
        $c = [];
        foreach (self::PADRAO as $k => $padrao) {
            $v = $salvos[$k] ?? null;
            if ($v !== null && (in_array($k, self::SEGREDOS, true) || $k === 'senhas_anteriores')) {
                try {
                    $v = Crypt::decryptString($v);
                } catch (\Throwable) {
                    $v = null;
                }
            }
            // Recuperação após perda total: variáveis de ambiente BACKUP_* (ex.: BACKUP_BUCKET).
            $c[$k] = $v ?? env('BACKUP_'.strtoupper($k)) ?? $padrao;
        }

        return $c;
    }

    public function get(string $k): ?string
    {
        return $this->todos()[$k] ?? null;
    }

    public function ativo(): bool
    {
        return $this->get('destino') !== 'desligado' && filled($this->get('senha'));
    }

    /**
     * Salva o que veio preenchido. Segredos em branco mantêm o valor atual.
     *
     * @return array{senha_gerada: ?string, base_forcada: bool}
     */
    public function salvar(array $dados, User $autor): array
    {
        $atual = $this->todos();
        $senhaGerada = null;
        $baseForcada = false;

        foreach (array_keys(self::PADRAO) as $k) {
            if (! array_key_exists($k, $dados) || in_array($k, ['senhas_anteriores', 'solicitado'], true)) {
                continue;
            }
            if (in_array($k, self::SEGREDOS, true) && blank($dados[$k])) {
                continue;
            }
            $this->gravar($k, $dados[$k], $autor);
        }

        if (! empty($dados['nova_senha']) || (blank($atual['senha']) && ($dados['destino'] ?? $atual['destino']) !== 'desligado')) {
            if (filled($atual['senha'])) {
                // Backups antigos continuam legíveis: a senha anterior é guardada.
                $anteriores = array_filter(explode("\n", (string) $atual['senhas_anteriores']));
                $anteriores[] = $atual['senha'];
                $this->gravar('senhas_anteriores', implode("\n", array_unique($anteriores)), $autor);
                $baseForcada = true;
            }
            $senhaGerada = self::gerarSenha();
            $this->gravar('senha', $senhaGerada, $autor);
        }
        if ($baseForcada) {
            app(EstadoLocal::class)->limpar();
        }

        return ['senha_gerada' => $senhaGerada, 'base_forcada' => $baseForcada];
    }

    public function gravar(string $k, ?string $v, ?User $autor = null): void
    {
        $v = $v === null || trim($v) === '' ? null : trim($v);
        if ($v !== null && (in_array($k, self::SEGREDOS, true) || $k === 'senhas_anteriores')) {
            $v = Crypt::encryptString($v);
        }
        Configuracao::updateOrCreate(['chave' => 'backup.'.$k], ['valor' => $v, 'atualizado_por' => $autor?->id]);
    }

    /** @return list<string> senha atual primeiro, depois as anteriores */
    public function senhas(): array
    {
        return array_values(array_filter([$this->get('senha'), ...explode("\n", (string) $this->get('senhas_anteriores'))]));
    }

    /** @return list<string> horários HH:MM */
    public function horarios(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->get('horarios'))),
            fn ($h) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)));
    }

    public function caminho(string $nome = ''): string
    {
        return trim((string) $this->get('prefixo'), '/').($nome !== '' ? '/'.$nome : '');
    }

    /** Disco Flysystem do destino configurado. */
    public function disco(): Filesystem
    {
        return Storage::build($this->configDisco($this->todos()));
    }

    /** Configuração do disco (separada para poder ser testada sem rede). */
    public function configDisco(array $c): array
    {
        if ($c['destino'] === 'local') {
            abort_unless(app()->environment('local', 'testing'), 403, 'Destino local só é permitido em desenvolvimento.');

            return ['driver' => 'local', 'root' => storage_path('app/backup-destino-local'), 'throw' => true];
        }
        $base = [
            'driver' => 's3', 'key' => $c['chave'], 'secret' => $c['segredo'], 'bucket' => $c['bucket'], 'throw' => true,
            // Compatibilidade com provedores S3 não-AWS (GCS, R2, B2): checksums só quando exigidos.
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ];

        return match ($c['destino']) {
            's3' => $base + ['region' => $c['regiao'] ?: 'us-east-1'],
            'gcs' => $base + ['region' => 'auto', 'endpoint' => 'https://storage.googleapis.com', 'use_path_style_endpoint' => true],
            'compativel' => $base + ['region' => $c['regiao'] ?: 'auto', 'endpoint' => $c['endpoint'], 'use_path_style_endpoint' => true],
            default => throw new \RuntimeException('Backup desligado.'),
        };
    }

    public static function gerarSenha(): string
    {
        // 6 grupos de 5 caracteres legíveis (~150 bits): fácil de copiar para a folha de recuperação.
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 30; $i++) {
            $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return implode('-', str_split($s, 5));
    }

    public static function mascarar(?string $v): string
    {
        return $v ? Str::mask($v, '•', 3, max(0, strlen($v) - 5)) : '';
    }
}
