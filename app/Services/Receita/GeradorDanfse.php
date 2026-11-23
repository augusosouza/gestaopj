<?php

namespace App\Services\Receita;

use App\Models\Evento;
use App\Models\NotaFiscal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Adaptador do gerador oficial de DANFSe da Receita Federal
 * (github.com/ReceitaFederal/calculadora-rtc-gerador-danfse, licença MIT).
 *
 * Roda como serviço interno (POST /api/danfse, corpo = XML da NFS-e, resposta = PDF).
 * O PDF é gerado uma vez, guardado ao lado do XML e marcado com a versão do componente.
 * Nenhum dado sai para a internet: o serviço fica na rede interna do Docker.
 */
final class GeradorDanfse
{
    public function habilitado(): bool
    {
        return filled(config('gestao.receita.danfse_url'));
    }

    public function versao(): string
    {
        return (string) config('gestao.receita.danfse_versao');
    }

    /** Caminho do PDF no disco privado; gera se ainda não existir. */
    public function pdf(NotaFiscal $nota): string
    {
        if ($nota->danfse_path && Storage::disk('local')->exists($nota->danfse_path)) {
            return $nota->danfse_path;
        }
        if (! $nota->temXml()) {
            throw new ComponenteIndisponivel('Esta nota foi informada só pela chave. Anexe o XML para gerar o DANFSe.');
        }
        if (! $this->habilitado()) {
            throw new ComponenteIndisponivel('O gerador de DANFSe não está configurado neste servidor.');
        }

        try {
            $resp = Http::timeout(config('gestao.receita.timeout'))
                ->withBody(Storage::disk('local')->get($nota->xml_path), 'application/xml')
                ->accept('application/pdf')
                ->post(rtrim(config('gestao.receita.danfse_url'), '/').'/api/danfse');
        } catch (ConnectionException) {
            throw new ComponenteIndisponivel('O gerador de DANFSe não respondeu. Tente novamente em instantes.');
        }

        $pdf = $resp->body();
        if (! $resp->successful() || ! str_starts_with($pdf, '%PDF')) {
            // 4xx: o próprio componente recusou o XML (leiaute); 5xx: falha do serviço.
            throw new ComponenteIndisponivel($resp->clientError()
                ? 'O gerador da Receita recusou o XML desta nota (HTTP '.$resp->status().'). Confira se é o XML da NFS-e autorizada.'
                : 'O gerador de DANFSe falhou (HTTP '.$resp->status().'). Tente novamente em instantes.');
        }

        $caminho = preg_replace('/\.xml$/', '', $nota->xml_path).'.pdf';
        Storage::disk('local')->put($caminho, $pdf);
        $nota->forceFill(['danfse_path' => $caminho, 'danfse_versao' => $this->versao(), 'danfse_gerado_em' => now()])->save();
        Evento::registrar('nota.danfse_gerado', $nota, ['versao' => $this->versao(), 'sha256' => hash('sha256', $pdf)]);

        return $caminho;
    }
}
