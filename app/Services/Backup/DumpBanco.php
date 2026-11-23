<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exporta e importa o banco sem depender de mysqldump (funciona em cPanel).
 *
 * Formato: esquema.json (DDL de cada tabela, na ordem) + dados/{tabela}.jsonl (uma linha JSON
 * por registro, lido em blocos para não estourar a memória). Suporta MariaDB/MySQL e SQLite.
 */
final class DumpBanco
{
    /** Tabelas transitórias: não fazem sentido num backup (e sessões seriam um risco). */
    private const IGNORAR = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** @return array{tabelas: int, linhas: int} */
    public function exportar(string $dir): array
    {
        @mkdir($dir.'/dados', 0750, true);
        $esquema = ['driver' => $this->driver(), 'tabelas' => [], 'indices' => []];
        $linhas = 0;

        foreach ($this->tabelas() as $t) {
            $esquema['tabelas'][$t] = $this->ddl($t);
            $h = fopen("{$dir}/dados/{$t}.jsonl", 'wb');
            $q = DB::table($t);
            $temId = in_array('id', DB::getSchemaBuilder()->getColumnListing($t), true);
            foreach ($temId ? $q->lazyById(1000) : $q->orderByRaw('1')->cursor() as $r) {
                fwrite($h, json_encode((array) $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                $linhas++;
            }
            fclose($h);
        }
        if ($this->driver() === 'sqlite') {
            $esquema['indices'] = collect(DB::select("select sql from sqlite_master where type = 'index' and sql is not null"))
                ->pluck('sql')->filter(fn ($s) => ! $this->ignorado($s))->values()->all();
        }
        file_put_contents("{$dir}/esquema.json", json_encode($esquema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return ['tabelas' => count($esquema['tabelas']), 'linhas' => $linhas];
    }

    /** Substitui TODO o banco atual pelo conteúdo do backup. */
    public function importar(string $dir): array
    {
        $esquema = json_decode((string) file_get_contents("{$dir}/esquema.json"), true, flags: JSON_THROW_ON_ERROR);
        if ($esquema['driver'] !== $this->driver() && ! ($this->ehMysql($esquema['driver']) && $this->ehMysql($this->driver()))) {
            throw new RuntimeException("Backup é de {$esquema['driver']}, mas o banco atual é {$this->driver()}.");
        }
        $this->fk(false);
        try {
            foreach ($this->tabelas() as $t) {
                DB::statement('DROP TABLE IF EXISTS '.$this->q($t));
            }
            $linhas = 0;
            foreach ($esquema['tabelas'] as $t => $ddl) {
                DB::unprepared($ddl);
                $h = fopen("{$dir}/dados/{$t}.jsonl", 'rb');
                $lote = [];
                while (($l = fgets($h)) !== false) {
                    $lote[] = json_decode($l, true, flags: JSON_THROW_ON_ERROR);
                    if (count($lote) === 500) {
                        DB::table($t)->insert($lote);
                        $linhas += 500;
                        $lote = [];
                    }
                }
                if ($lote) {
                    DB::table($t)->insert($lote);
                    $linhas += count($lote);
                }
                fclose($h);
            }
            foreach ($esquema['indices'] as $sql) {
                DB::unprepared($sql);
            }
        } finally {
            $this->fk(true);
        }

        return ['tabelas' => count($esquema['tabelas']), 'linhas' => $linhas];
    }

    /** @return list<string> */
    private function tabelas(): array
    {
        $nomes = $this->driver() === 'sqlite'
            ? collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))->pluck('name')
            : collect(DB::select('SHOW FULL TABLES'))->filter(fn ($r) => array_values((array) $r)[1] === 'BASE TABLE')->map(fn ($r) => array_values((array) $r)[0]);

        return $nomes->reject(fn ($t) => in_array($t, self::IGNORAR, true))->sort()->values()->all();
    }

    private function ddl(string $t): string
    {
        if ($this->driver() === 'sqlite') {
            return DB::selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$t])->sql;
        }
        $r = (array) DB::selectOne('SHOW CREATE TABLE '.$this->q($t));

        return $r['Create Table'];
    }

    private function ignorado(string $sqlIndice): bool
    {
        foreach (self::IGNORAR as $t) {
            if (preg_match('/\bon\s+["`]?'.preg_quote($t, '/').'["`]?\s*\(/i', $sqlIndice)) {
                return true;
            }
        }

        return false;
    }

    private function fk(bool $ligar): void
    {
        $this->driver() === 'sqlite'
            ? DB::statement('PRAGMA foreign_keys = '.($ligar ? 'ON' : 'OFF'))
            : DB::statement('SET FOREIGN_KEY_CHECKS = '.($ligar ? '1' : '0'));
    }

    private function q(string $t): string
    {
        return $this->driver() === 'sqlite' ? '"'.str_replace('"', '""', $t).'"' : '`'.str_replace('`', '``', $t).'`';
    }

    private function ehMysql(string $d): bool
    {
        return in_array($d, ['mysql', 'mariadb'], true);
    }
}
