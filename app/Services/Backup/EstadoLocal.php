<?php

namespace App\Services\Backup;

/**
 * Manifesto do último backup concluído (fora da pasta de arquivos, para não entrar no backup).
 * Serve só para decidir o que mudou; sem ele, o próximo backup vira base automaticamente.
 */
final class EstadoLocal
{
    private function caminho(): string
    {
        return storage_path('app/backup-estado/ultimo-manifesto.json');
    }

    public function ler(): ?array
    {
        $p = $this->caminho();

        return is_file($p) ? json_decode((string) file_get_contents($p), true) : null;
    }

    public function gravar(array $manifesto): void
    {
        @mkdir(dirname($this->caminho()), 0750, true);
        file_put_contents($this->caminho(), json_encode($manifesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function limpar(): void
    {
        @unlink($this->caminho());
    }
}
