<?php

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Espaço livre no disco da aplicação, com disk_free_space() do PHP.
 * Substitui o UsedDiskSpaceCheck do pacote, que chama o comando `df` por
 * processo externo — bloqueado em muitos cPanel (proc_open desativado).
 */
class EspacoEmDiscoCheck extends Check
{
    private int $avisoPct = 80;

    private int $falhaPct = 90;

    public function limites(int $aviso, int $falha): static
    {
        [$this->avisoPct, $this->falhaPct] = [$aviso, $falha];

        return $this;
    }

    public function run(): Result
    {
        $dir = storage_path();
        $livre = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        $r = Result::make();
        if ($livre === false || ! $total) {
            return $r->warning('Não foi possível ler o espaço em disco (função bloqueada pela hospedagem).')->shortSummary('?');
        }
        $usado = (int) round(100 - $livre * 100 / $total);
        $r->meta(['usado_pct' => $usado, 'livre_gb' => round($livre / 1073741824, 1)])->shortSummary($usado.'% usado');

        return match (true) {
            $usado >= $this->falhaPct => $r->failed("Disco {$usado}% cheio. Libere espaço (backups locais antigos, exportações, logs)."),
            $usado >= $this->avisoPct => $r->warning("Disco {$usado}% cheio."),
            default => $r->ok(),
        };
    }
}
