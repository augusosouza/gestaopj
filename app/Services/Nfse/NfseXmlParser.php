<?php

namespace App\Services\Nfse;

use App\Support\Dinheiro;
use App\Support\Documento;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\Exception;
use DOMDocument;
use DOMXPath;

/**
 * Lê o XML da NFS-e do padrão nacional (SEFIN Nacional / ADN).
 *
 * As consultas usam local-name() para tolerar variações de prefixo de
 * namespace. Não valida assinatura digital (fica para a fase 2).
 */
final class NfseXmlParser
{
    public function ler(string $xml): DadosNfse
    {
        if (trim($xml) === '') {
            throw new NfseXmlInvalido('Arquivo vazio.');
        }
        // Bloqueia DTD/entidades (XXE e "billion laughs").
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new NfseXmlInvalido('XML com DOCTYPE não é aceito.');
        }

        $dom = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (! $ok) {
            throw new NfseXmlInvalido('O arquivo não é um XML válido.');
        }

        $xp = new DOMXPath($dom);
        $inf = $xp->query('//*[local-name()="NFSe"]/*[local-name()="infNFSe"]')->item(0);
        if ($inf === null) {
            if ($xp->query('//*[local-name()="DPS"]')->length > 0) {
                throw new NfseXmlInvalido('Este arquivo é uma DPS (declaração), não a NFS-e gerada. Envie o XML da nota autorizada.');
            }
            throw new NfseXmlInvalido('XML não segue o padrão da NFS-e nacional (elemento infNFSe ausente).');
        }

        $t = function (string $caminho) use ($xp, $inf): ?string {
            $partes = array_map(fn ($p) => '*[local-name()="'.$p.'"]', explode('/', $caminho));
            $n = $xp->query('./'.implode('/', $partes), $inf)->item(0);
            $v = $n?->textContent;

            return $v === null || trim($v) === '' ? null : trim($v);
        };

        $id = $inf->attributes?->getNamedItem('Id')?->nodeValue ?? '';
        $chave = ChaveAcesso::tentar(preg_replace('/^NFS/', '', $id));
        if ($chave === null) {
            throw new NfseXmlInvalido('Chave de acesso ausente ou inválida no atributo Id de infNFSe.');
        }

        $emitente = Documento::limpar($t('emit/CNPJ') ?? $t('emit/CPF') ?? $t('DPS/infDPS/prest/CNPJ') ?? $t('DPS/infDPS/prest/CPF'));
        if ($emitente === '') {
            throw new NfseXmlInvalido('Emitente (CNPJ/CPF) não encontrado no XML.');
        }
        $tomador = Documento::limpar($t('DPS/infDPS/toma/CNPJ') ?? $t('DPS/infDPS/toma/CPF'));

        $data = fn (?string $v) => $v ? CarbonImmutable::parse($v) : null;

        try {
            return new DadosNfse(
                chaveAcesso: $chave->valor,
                numero: $t('nNFSe'),
                emitenteDocumento: $emitente,
                emitenteNome: $t('emit/xNome'),
                tomadorDocumento: $tomador !== '' ? $tomador : null,
                tomadorNome: $t('DPS/infDPS/toma/xNome'),
                competencia: $data($t('DPS/infDPS/dCompet'))?->startOfMonth(),
                emitidaEm: $data($t('dhProc') ?? $t('DPS/infDPS/dhEmi')),
                codigoServico: $t('DPS/infDPS/serv/cServ/cTribNac'),
                descricaoServico: $t('DPS/infDPS/serv/cServ/xDescServ'),
                valorServicosCentavos: Dinheiro::deXml($t('DPS/infDPS/valores/vServPrest/vServ')),
                valorLiquidoCentavos: Dinheiro::deXml($t('valores/vLiq')),
                ambiente: $t('DPS/infDPS/tpAmb') ?? $t('ambGer'),
            );
        } catch (\InvalidArgumentException|Exception $e) {
            throw new NfseXmlInvalido('Campo com formato inválido no XML: '.$e->getMessage());
        }
    }
}
