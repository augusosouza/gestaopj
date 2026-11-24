<?php

namespace App\Services;

use App\Models\Anexo;
use App\Models\Compra;
use App\Models\Evento;
use App\Models\Titulo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Comprovantes, notas e boletos anexados a lançamentos e compras.
 * Padrão dos documentos do contador: disco privado, pasta por empresa, nome aleatório,
 * sha256, auditoria. Download só pela rota autenticada (escopo da empresa).
 */
final class Anexos
{
    /** extensão => tipos MIME aceitos (conferidos pelo conteúdo do arquivo, não pelo nome). */
    public const TIPOS = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'xml' => ['application/xml', 'text/xml'],
    ];

    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private Financeiro $fin) {}

    public function anexar(Model $alvo, UploadedFile $arquivo, ?string $descricao = null): Anexo
    {
        $this->exigirAlvo($alvo);
        $ext = Str::lower($arquivo->getClientOriginalExtension());
        // Tipo detectado pelos bytes do arquivo (finfo), nunca pelo nome ou pelo que o navegador declarou.
        $mime = $arquivo->getRealPath() ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($arquivo->getRealPath()) : '';
        $erro = match (true) {
            ! $arquivo->isValid() => 'Falha no envio do arquivo. Tente de novo.',
            ! isset(self::TIPOS[$ext]) => 'Tipo não aceito. Envie PDF, JPG, PNG, WEBP ou XML.',
            ! in_array($mime, self::TIPOS[$ext], true) => "O conteúdo do arquivo não é {$ext} (detectado: {$mime}).",
            $arquivo->getSize() > self::MAX_BYTES => 'Arquivo acima de 10 MB.',
            $arquivo->getSize() === 0 => 'Arquivo vazio.',
            default => null,
        };
        if ($erro) {
            throw ValidationException::withMessages(['arquivo' => $erro]);
        }
        $this->exigirMesAberto($alvo);

        $conteudo = $arquivo->get();
        $caminho = sprintf('anexos/%d/%s.%s', $alvo->organizacao_id, Str::uuid(), $ext);
        $disco = Storage::disk('local');
        if (! $disco->put($caminho, $conteudo)) {
            throw ValidationException::withMessages(['arquivo' => 'Não foi possível guardar o arquivo. Tente de novo.']);
        }

        try {
            return DB::transaction(function () use ($alvo, $arquivo, $descricao, $caminho, $mime, $conteudo) {
                $a = Anexo::create([
                    'organizacao_id' => $alvo->organizacao_id, 'anexavel_type' => $alvo->getMorphClass(), 'anexavel_id' => $alvo->getKey(),
                    'descricao' => $descricao ? Str::limit($descricao, 120, '') : null,
                    'nome_original' => Str::limit(basename($arquivo->getClientOriginalName()), 180, ''),
                    'caminho' => $caminho, 'mime' => $mime, 'tamanho' => strlen($conteudo), 'sha256' => hash('sha256', $conteudo), 'enviado_por' => auth()->id(),
                ]);
                Evento::registrar('financeiro.anexo_enviado', $alvo, ['arquivo' => $a->nome_original, 'sha256' => substr($a->sha256, 0, 12)]);

                return $a;
            });
        } catch (\Throwable $erro) {
            $disco->delete($caminho);
            throw $erro;
        }
    }

    public function remover(Anexo $a): void
    {
        $alvo = $a->anexavel;
        $this->exigirMesAberto($alvo);
        Storage::disk('local')->delete($a->caminho);
        Evento::registrar('financeiro.anexo_removido', $alvo, ['arquivo' => $a->nome_original, 'sha256' => substr($a->sha256, 0, 12)]);
        $a->delete();
    }

    private function exigirAlvo(Model $alvo): void
    {
        if (! $alvo instanceof Titulo && ! $alvo instanceof Compra) {
            throw new \InvalidArgumentException('Anexos só em lançamentos e compras.');
        }
    }

    /** Mês fechado pelo contador: o pacote do fechamento não muda por baixo dos panos. */
    private function exigirMesAberto(Model $alvo): void
    {
        $data = $alvo instanceof Compra ? $alvo->data_compra : $alvo->competencia;
        $this->fin->exigirMesAberto($alvo->organizacao_id, CarbonImmutable::instance($data)->toDateString(), 'arquivo');
    }
}
