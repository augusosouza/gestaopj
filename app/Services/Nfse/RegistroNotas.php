<?php

namespace App\Services\Nfse;

use App\Models\NotaFiscal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Registra NFS-e no sistema a partir do XML ou só da chave de acesso,
 * impedindo duplicidade pela chave.
 */
final class RegistroNotas
{
    public function __construct(private NfseXmlParser $parser) {}

    public function deXml(int $organizacaoId, UploadedFile|string $arquivo, string $direcao): NotaFiscal
    {
        $xml = $arquivo instanceof UploadedFile ? $arquivo->get() : $arquivo;
        try {
            $dados = $this->parser->ler($xml);
        } catch (NfseXmlInvalido $e) {
            throw ValidationException::withMessages(['xml' => $e->getMessage()]);
        }

        $existente = NotaFiscal::where('organizacao_id', $organizacaoId)->where('chave_acesso', $dados->chaveAcesso)->first();
        if ($existente !== null) {
            // Nota já informada só pela chave: completa com o XML.
            if (! $existente->temXml() && $existente->direcao === $direcao && $existente->emitente_documento === $dados->emitenteDocumento) {
                $existente->fill($dados->paraModelo() + $this->salvar($organizacaoId, $xml, $direcao, $dados->chaveAcesso) + ['origem' => 'xml'])->save();

                return $existente;
            }
            throw ValidationException::withMessages(['xml' => "Esta NFS-e (chave {$dados->chaveAcesso}) já foi registrada no sistema."]);
        }

        return NotaFiscal::create($dados->paraModelo() + $this->salvar($organizacaoId, $xml, $direcao, $dados->chaveAcesso) + [
            'organizacao_id' => $organizacaoId,
            'direcao' => $direcao,
            'origem' => 'xml',
            'enviada_por' => auth()->id(),
        ]);
    }

    /** Prestador informa apenas a chave; o XML pode ser anexado depois (ou obtido no ADN, fase 2). */
    public function deChave(int $organizacaoId, string $chave, string $direcao): NotaFiscal
    {
        $c = ChaveAcesso::tentar($chave);
        if ($c === null) {
            throw ValidationException::withMessages(['chave_acesso' => 'A chave de acesso da NFS-e deve ter 50 dígitos.']);
        }
        if (NotaFiscal::where('organizacao_id', $organizacaoId)->where('chave_acesso', $c->valor)->exists()) {
            throw ValidationException::withMessages(['chave_acesso' => 'Esta NFS-e já foi registrada no sistema.']);
        }

        return NotaFiscal::create([
            'organizacao_id' => $organizacaoId,
            'direcao' => $direcao,
            'chave_acesso' => $c->valor,
            'numero' => $c->numero(),
            'emitente_documento' => $c->inscricaoFederal(),
            'origem' => 'chave',
            'enviada_por' => auth()->id(),
        ]);
    }

    /** Arquivo original preservado no disco privado, separado por organização. */
    private function salvar(int $organizacaoId, string $xml, string $direcao, string $chave): array
    {
        $caminho = "nfse/{$organizacaoId}/{$direcao}/{$chave}.xml";
        Storage::disk('local')->put($caminho, $xml);

        return ['xml_path' => $caminho, 'xml_sha256' => hash('sha256', $xml)];
    }
}
