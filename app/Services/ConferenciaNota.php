<?php

namespace App\Services;

use App\Models\Cobranca;
use App\Models\NotaFiscal;
use App\Models\PerfilFiscal;
use App\Services\Nfse\ChaveAcesso;
use App\Support\Dinheiro;
use App\Support\Documento;

/**
 * Natureza de cada item (define o que a aprovação permite):
 *  - fiscal:      documento inválido para esta cobrança (emitente, tomador, ambiente).
 *                 Nunca aprovável: rejeitar e pedir nova nota.
 *  - comercial:   divergência de valor ou competência. Aprovável só com justificativa
 *                 e alçada de administrador.
 *  - verificacao: documento não verificado (só a chave). Aprovável com justificativa,
 *                 mas a pendência fiscal continua até o XML ser anexado.
 *  - aviso:       informativo.
 *
 * Checklist automático que o financeiro vê antes de aprovar uma cobrança.
 * Cada item: ['item' => ..., 'ok' => bool, 'bloqueante' => bool, 'detalhe' => ...].
 */
final class ConferenciaNota
{
    public function conferir(Cobranca $cobranca, NotaFiscal $nota): array
    {
        $cobranca->loadMissing('prestador', 'contrato.empresa');
        $prestador = $cobranca->prestador;
        $empresa = $cobranca->contrato->empresa;
        $itens = [];

        $itens[] = $this->item(
            'Emitente é o prestador',
            $nota->emitente_documento === $prestador->documento,
            'Nota: '.Documento::formatar($nota->emitente_documento).' · Prestador: '.Documento::formatar($prestador->documento),
            'fiscal',
        );

        if (! $nota->temXml()) {
            $itens[] = $this->item('XML da nota anexado', false, 'Somente a chave foi informada: valor, tomador e competência não puderam ser conferidos.', 'verificacao');
        } else {
            $itens[] = $this->item(
                'Tomador é a empresa contratante',
                $nota->tomador_documento === $empresa->cnpj,
                'Nota: '.Documento::formatar($nota->tomador_documento).' · Empresa: '.Documento::formatar($empresa->cnpj),
                'fiscal',
            );
            $itens[] = $this->item(
                'Valor do serviço confere',
                $nota->valor_servicos_centavos === $cobranca->valor_centavos,
                'Nota: '.Dinheiro::formatar($nota->valor_servicos_centavos).' · Cobrança: '.Dinheiro::formatar($cobranca->valor_centavos)
                    .$this->diferenca($nota->valor_servicos_centavos, $cobranca->valor_centavos),
                'comercial',
            );
            // Compara a competência declarada na nota (dCompet), não a data de emissão.
            $itens[] = $this->item(
                'Competência confere',
                $nota->competencia?->format('Y-m') === $cobranca->competencia->format('Y-m'),
                'Nota: '.($nota->competencia?->format('m/Y') ?? '—').' · Cobrança: '.$cobranca->competencia->format('m/Y'),
                'comercial',
            );
            $aceitaRestrita = (bool) config('gestao.aceitar_producao_restrita');
            $itens[] = $this->item(
                'Emitida em ambiente de produção',
                $nota->ambiente === '1' || ($aceitaRestrita && $nota->ambiente === '2'),
                $nota->ambiente === '2' ? 'Nota de produção restrita (teste) — sem validade fiscal.' : 'Ambiente: '.($nota->ambiente ?? 'não informado'),
                'fiscal',
            );
        }

        $chave = ChaveAcesso::tentar($nota->chave_acesso);
        $itens[] = $this->item(
            'CNPJ contido na chave de acesso',
            $chave !== null && $chave->inscricaoFederal() === $prestador->documento,
            'Verificação auxiliar pela composição da chave.',
            'aviso',
        );

        $perfil = PerfilFiscal::vigente($cobranca->organizacao_id, $prestador->documento, $cobranca->competencia);
        $itens[] = $this->item(
            'Perfil fiscal do prestador confirmado pela contabilidade',
            $perfil !== null && $perfil->confirmado_em !== null,
            $perfil ? $perfil->regime->rotulo().($perfil->confirmado_em ? '' : ' (não confirmado)') : 'Nenhum perfil vigente na competência.',
            'aviso',
        );

        return $itens;
    }

    /** @param 'fiscal'|'comercial'|'verificacao'|'aviso' $natureza */
    private function item(string $item, bool $ok, string $detalhe, string $natureza): array
    {
        return compact('item', 'ok', 'natureza', 'detalhe');
    }

    private function diferenca(?int $nota, int $esperado): string
    {
        if ($nota === null || $nota === $esperado) {
            return '';
        }
        $d = $nota - $esperado;

        return ' · Diferença: '.($d > 0 ? '+' : '−').Dinheiro::formatar(abs($d));
    }
}
