<?php

namespace App\Health;

use App\Models\Backup;
use App\Services\Backup\ConfigBackup;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/** O último backup concluído é recente? Usa o histórico do próprio módulo de backup do Augi. */
class BackupRecenteCheck extends Check
{
    private int $horas = 12;

    public function horas(int $horas): static
    {
        $this->horas = $horas;

        return $this;
    }

    public function run(): Result
    {
        $r = Result::make();
        if (! app(ConfigBackup::class)->ativo()) {
            return $r->warning('Backup automático desativado.')->shortSummary('desativado');
        }
        $ultimo = Backup::where('status', 'concluido')->latest('concluido_em')->first();
        if (! $ultimo) {
            return $r->failed('Nenhum backup concluído ainda.')->shortSummary('nenhum');
        }
        $h = (int) $ultimo->concluido_em->diffInHours(now());
        $r->meta(['ultimo' => $ultimo->concluido_em->toIso8601String(), 'tipo' => $ultimo->tipo])->shortSummary("há {$h} h");
        $falhou = Backup::where('status', 'falhou')->where('iniciado_em', '>', $ultimo->concluido_em)->exists();

        return match (true) {
            $h >= $this->horas => $r->failed("Último backup concluído há {$h} horas (limite {$this->horas} h)."),
            $falhou => $r->warning('Houve uma falha de backup depois do último concluído.'),
            default => $r->ok(),
        };
    }
}
