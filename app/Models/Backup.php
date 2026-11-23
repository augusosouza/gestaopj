<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro de um backup da plataforma (global: não pertence a nenhuma empresa). */
class Backup extends Model
{
    public $timestamps = false;

    protected $fillable = ['nome', 'tipo', 'base_nome', 'status', 'destino', 'acionado_por', 'tamanho_bytes', 'arquivos_enviados',
        'arquivos_total', 'arquivos_removidos', 'tabelas', 'linhas', 'sha256', 'erro', 'iniciado_em', 'concluido_em'];

    protected function casts(): array
    {
        return ['iniciado_em' => 'datetime', 'concluido_em' => 'datetime'];
    }

    public function duracao(): ?string
    {
        if (! $this->concluido_em) {
            return null;
        }
        $s = (int) $this->iniciado_em->diffInSeconds($this->concluido_em);

        return $s < 60 ? "{$s}s" : intdiv($s, 60).'min '.($s % 60).'s';
    }

    public function tamanhoLegivel(): string
    {
        $b = (int) $this->tamanho_bytes;

        return match (true) {
            $b >= 1073741824 => number_format($b / 1073741824, 2, ',', '.').' GB',
            $b >= 1048576 => number_format($b / 1048576, 1, ',', '.').' MB',
            default => max(1, (int) round($b / 1024)).' KB',
        };
    }
}
