<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Evento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Backup completo da plataforma: banco de dados + arquivos privados (XMLs, PDFs, documentos, fechamentos).
 *
 *  - BASE (semanal, configurável): banco + todos os arquivos.
 *  - INCREMENTAL (nos horários configurados): banco + só arquivos novos/alterados + lista dos removidos.
 *
 * Cada pacote traz um manifesto com TODOS os arquivos daquele momento e em qual pacote está cada um,
 * então qualquer ponto restaura o estado completo, usando só o que está no destino.
 * Tudo em PHP puro (ZipArchive + libsodium + Flysystem S3): roda em cPanel e em container.
 */
final class Backups
{
    public const FORMATO = 1;

    public function __construct(
        private ConfigBackup $config,
        private Cofre $cofre,
        private DumpBanco $banco,
        private EstadoLocal $estado,
    ) {}

    private function raizArquivos(): string
    {
        return storage_path('app/private');
    }

    private function tmp(): string
    {
        // Dentro de storage/: em cPanel o /tmp costuma ser pequeno ou compartilhado.
        $d = storage_path('app/backup-tmp/'.Str::uuid());
        File::ensureDirectoryExists($d, 0750);

        return $d;
    }

    // ================= Executar =================

    public function executar(string $acionadoPor = 'agenda', bool $forcarBase = false): ?Backup
    {
        if (! $this->config->ativo()) {
            throw new RuntimeException('Backup desligado ou sem senha de criptografia configurada.');
        }
        $trava = Cache::lock('augi-backup', 6 * 3600);
        if (! $trava->get()) {
            return null; // já há um backup em andamento
        }
        @set_time_limit(0);
        $tmp = $this->tmp();
        $anterior = $this->estado->ler();
        $base = $forcarBase || $this->precisaBase($anterior);
        $agora = now();
        $nome = sprintf('augi-%s-%s.augibk', $agora->format('Ymd-His'), $base ? 'base' : 'inc');
        $registro = Backup::create([
            'nome' => $nome, 'tipo' => $base ? 'base' : 'incremental', 'base_nome' => $base ? $nome : $anterior['base'],
            'status' => 'executando', 'destino' => $this->config->get('destino'), 'acionado_por' => $acionadoPor, 'iniciado_em' => $agora,
        ]);

        try {
            $stats = $this->banco->exportar("{$tmp}/banco");
            [$manifesto, $enviar, $removidos] = $this->manifesto($registro, $base ? null : $anterior);

            $zipPath = "{$tmp}/pacote.zip";
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o pacote zip.');
            }
            foreach (File::allFiles("{$tmp}/banco") as $f) {
                $zip->addFile($f->getPathname(), 'banco/'.str_replace('\\', '/', $f->getRelativePathname()));
            }
            foreach ($enviar as $rel) {
                $zip->addFile($this->raizArquivos().'/'.$rel, 'arquivos/'.$rel);
            }
            $zip->addFromString('manifesto.json', json_encode($manifesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->close();

            $cifrado = "{$tmp}/{$nome}";
            $this->cofre->cifrar($zipPath, $cifrado, $this->config->get('senha'));
            $tamanho = filesize($cifrado);
            $hash = hash_file('sha256', $cifrado);

            $disco = $this->config->disco();
            $h = fopen($cifrado, 'rb');
            $disco->writeStream($this->config->caminho($nome), $h);
            if (is_resource($h)) {
                fclose($h);
            }
            if ($disco->size($this->config->caminho($nome)) !== $tamanho) {
                throw new RuntimeException('O tamanho no destino não confere com o pacote enviado.');
            }

            $this->estado->gravar($manifesto);
            $registro->update([
                'status' => 'concluido', 'concluido_em' => now(), 'tamanho_bytes' => $tamanho, 'sha256' => $hash,
                'arquivos_enviados' => count($enviar), 'arquivos_total' => count($manifesto['arquivos']),
                'arquivos_removidos' => count($removidos), 'tabelas' => $stats['tabelas'], 'linhas' => $stats['linhas'],
            ]);
            Evento::create(['acao' => 'backup.concluido', 'alvo_type' => $registro->getMorphClass(), 'alvo_id' => $registro->id,
                'dados' => ['tipo' => $registro->tipo, 'tamanho' => $tamanho]]);
            $this->aplicarRetencao();

            return $registro;
        } catch (\Throwable $e) {
            $registro->update(['status' => 'falhou', 'concluido_em' => now(), 'erro' => Str::limit($this->semSegredos($e->getMessage()), 1000)]);
            Evento::create(['acao' => 'backup.falhou', 'alvo_type' => $registro->getMorphClass(), 'alvo_id' => $registro->id]);
            report($e);

            return $registro;
        } finally {
            File::deleteDirectory($tmp);
            $trava->release();
        }
    }

    private function precisaBase(?array $anterior): bool
    {
        if (! $anterior) {
            return true;
        }
        $ultimaBase = Backup::where('tipo', 'base')->where('status', 'concluido')->latest('id')->first();
        if (! $ultimaBase || $ultimaBase->nome !== $anterior['base']) {
            return true;
        }

        return $ultimaBase->iniciado_em->lt(now()->subDays(max(1, (int) $this->config->get('dias_base'))));
    }

    /**
     * @return array{0: array, 1: list<string>, 2: list<string>} manifesto, arquivos a enviar, removidos
     */
    private function manifesto(Backup $b, ?array $anterior): array
    {
        $antes = $anterior['arquivos'] ?? [];
        $atual = [];
        $enviar = [];
        if (is_dir($this->raizArquivos())) {
            foreach (File::allFiles($this->raizArquivos(), true) as $f) {
                $rel = str_replace('\\', '/', $f->getRelativePathname());
                $tam = $f->getSize();
                $mtime = $f->getMTime();
                $velho = $antes[$rel] ?? null;
                if ($velho && $velho['t'] === $tam && $velho['m'] === $mtime) {
                    $atual[$rel] = $velho; // inalterado: aponta para o pacote que já o contém
                } else {
                    $atual[$rel] = ['t' => $tam, 'm' => $mtime, 'h' => hash_file('sha256', $f->getPathname()), 'p' => $b->nome];
                    $enviar[] = $rel;
                }
            }
        }
        ksort($atual);
        $removidos = array_values(array_diff(array_keys($antes), array_keys($atual)));

        return [[
            'formato' => self::FORMATO, 'nome' => $b->nome, 'tipo' => $b->tipo, 'base' => $b->base_nome,
            'criado_em' => $b->iniciado_em->toIso8601String(), 'app' => config('app.name'),
            'banco' => DB::connection()->getDriverName(), 'arquivos' => $atual, 'removidos' => $removidos,
            'pacotes' => array_values(array_unique(array_column($atual, 'p') ?: [$b->nome])),
        ], $enviar, $removidos];
    }

    // ================= Retenção =================

    /** Mantém as N bases mais recentes (com seus incrementais); remove as cadeias mais antigas do destino. */
    public function aplicarRetencao(): int
    {
        $manter = max(1, (int) $this->config->get('bases_mantidas'));
        $bases = Backup::where('tipo', 'base')->where('status', 'concluido')->orderByDesc('id')->pluck('nome');
        $remover = $bases->slice($manter);
        if ($remover->isEmpty()) {
            return 0;
        }
        $disco = $this->config->disco();
        $n = 0;
        foreach (Backup::whereIn('base_nome', $remover)->where('status', 'concluido')->get() as $b) {
            try {
                $disco->delete($this->config->caminho($b->nome));
            } catch (\Throwable $e) {
                report($e); // destino com bloqueio de retenção: o provedor apaga quando o prazo vencer
            }
            $b->update(['status' => 'removido']);
            $n++;
        }

        return $n;
    }

    // ================= Verificar =================

    /** Baixa o último pacote, confere o hash, decifra e abre o manifesto. */
    public function verificar(): array
    {
        $b = Backup::where('status', 'concluido')->latest('id')->firstOrFail();
        $tmp = $this->tmp();
        try {
            $zip = $this->baixarAbrir($b->nome, $tmp, $b->sha256);
            $m = json_decode($zip->getFromName('manifesto.json'), true);
            $zip->close();

            return ['nome' => $b->nome, 'arquivos' => count($m['arquivos']), 'ok' => true];
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    // ================= Restaurar =================

    /** @return list<string> nomes dos pacotes no destino, do mais antigo ao mais recente */
    public function remotos(): array
    {
        return collect($this->config->disco()->files($this->config->caminho()))
            ->map(fn ($p) => basename($p))->filter(fn ($n) => str_ends_with($n, '.augibk'))->sort()->values()->all();
    }

    /**
     * Restaura o estado completo de um ponto (padrão: o mais recente no destino).
     * Com $extrairEm, só extrai banco e arquivos para conferência, sem tocar no sistema.
     */
    public function restaurar(?string $nome = null, ?string $extrairEm = null): array
    {
        $nome ??= collect($this->remotos())->last() ?? throw new RuntimeException('Nenhum backup encontrado no destino.');
        $tmp = $this->tmp();
        try {
            $alvo = $this->baixarAbrir($nome, $tmp.'/alvo');
            $m = json_decode($alvo->getFromName('manifesto.json'), true, flags: JSON_THROW_ON_ERROR);
            $destinoArquivos = $extrairEm ? $extrairEm.'/arquivos' : $this->raizArquivos();
            File::ensureDirectoryExists($destinoArquivos, 0750);

            // Banco: sempre do pacote escolhido (cada pacote tem o banco completo).
            $alvo->extractTo($tmp.'/x', array_values(array_filter(
                array_map(fn ($i) => $alvo->getNameIndex($i), range(0, $alvo->numFiles - 1)), fn ($n) => str_starts_with($n, 'banco/'))));
            $alvo->close();
            $banco = $extrairEm ? ['extraido' => true] : $this->banco->importar($tmp.'/x/banco');
            if ($extrairEm) {
                File::copyDirectory($tmp.'/x/banco', $extrairEm.'/banco');
            }

            // Arquivos: cada um vem do pacote que o contém (base ou incremental).
            $porPacote = [];
            foreach ($m['arquivos'] as $rel => $info) {
                $porPacote[$info['p']][$rel] = $info['h'];
            }
            $restaurados = 0;
            foreach ($porPacote as $pacote => $arquivos) {
                $zip = $pacote === $nome ? $this->baixarAbrir($nome, $tmp.'/alvo2') : $this->baixarAbrir($pacote, $tmp.'/'.md5($pacote));
                foreach ($arquivos as $rel => $hash) {
                    $conteudo = $zip->getFromName('arquivos/'.$rel);
                    if ($conteudo === false || hash('sha256', $conteudo) !== $hash) {
                        throw new RuntimeException("Arquivo ausente ou corrompido no pacote {$pacote}: {$rel}");
                    }
                    File::ensureDirectoryExists(dirname($destinoArquivos.'/'.$rel), 0750);
                    file_put_contents($destinoArquivos.'/'.$rel, $conteudo);
                    $restaurados++;
                }
                $zip->close();
            }
            // Remove arquivos que não existiam naquele momento (só na restauração real).
            if (! $extrairEm) {
                foreach (File::allFiles($destinoArquivos, true) as $f) {
                    if (! isset($m['arquivos'][str_replace('\\', '/', $f->getRelativePathname())])) {
                        @unlink($f->getPathname());
                    }
                }
                $this->estado->limpar(); // o próximo backup será base
            }

            return ['pacote' => $nome, 'banco' => $banco, 'arquivos' => $restaurados, 'pacotes_usados' => count($porPacote)];
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    private function baixarAbrir(string $nome, string $dir, ?string $hashEsperado = null): ZipArchive
    {
        File::ensureDirectoryExists($dir, 0750);
        $cifrado = "{$dir}/{$nome}";
        $in = $this->config->disco()->readStream($this->config->caminho($nome));
        $out = fopen($cifrado, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }
        if ($hashEsperado && hash_file('sha256', $cifrado) !== $hashEsperado) {
            throw new RuntimeException("O pacote {$nome} no destino não confere com o registrado (hash diferente).");
        }
        $this->cofre->decifrar($cifrado, "{$dir}/pacote.zip", $this->config->senhas());
        $zip = new ZipArchive;
        if ($zip->open("{$dir}/pacote.zip") !== true) {
            throw new RuntimeException("Pacote {$nome} decifrado, mas o zip está inválido.");
        }

        return $zip;
    }

    private function semSegredos(string $msg): string
    {
        foreach (['segredo', 'senha', 'chave'] as $k) {
            if (($v = $this->config->get($k)) && strlen($v) >= 6) {
                $msg = str_replace($v, '[oculto]', $msg);
            }
        }

        return $msg;
    }
}
