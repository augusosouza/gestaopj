<?php

namespace Tests\Support;

/**
 * Monta XMLs de NFS-e no leiaute nacional para testes.
 * Estrutura baseada no XML retornado pela SEFIN Nacional (NFSe/infNFSe + DPS/infDPS).
 */
final class NfseXml
{
    public static function chave(string $cnpjEmitente, int $numero = 1, string $aamm = '2611', string $mun = '3550308', string $amb = '1'): string
    {
        return $mun.$amb.'2'.$cnpjEmitente.str_pad((string) $numero, 13, '0', STR_PAD_LEFT).$aamm.'123456789'.'0';
    }

    public static function gerar(array $o = []): string
    {
        $o += [
            'emitente' => '45723174000110',
            'emitente_nome' => 'Consultor Exemplo ME',
            'tomador' => '11222333000181',
            'tomador_nome' => 'Minha Empresa de Suporte e Consultoria Ltda',
            'competencia' => '2026-10-01',
            'valor' => '7500.00',
            'valor_liquido' => null,
            'numero' => 1,
            'ambiente' => '1',
            'cTribNac' => '010701',
        ];
        $o['valor_liquido'] ??= $o['valor'];
        $chave = $o['chave'] ?? self::chave($o['emitente'], $o['numero'], amb: $o['ambiente']);
        $tagToma = strlen($o['tomador']) === 11 ? 'CPF' : 'CNPJ';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00">
  <infNFSe Id="NFS{$chave}">
    <xLocEmi>São Paulo</xLocEmi>
    <xLocPrestacao>São Paulo</xLocPrestacao>
    <nNFSe>{$o['numero']}</nNFSe>
    <cLocIncid>3550308</cLocIncid>
    <xLocIncid>São Paulo</xLocIncid>
    <xTribNac>Suporte técnico em informática</xTribNac>
    <verAplic>SefinNac_1.0</verAplic>
    <ambGer>2</ambGer>
    <tpEmis>1</tpEmis>
    <procEmi>1</procEmi>
    <cStat>100</cStat>
    <dhProc>2026-11-03T10:15:00-03:00</dhProc>
    <nDFSe>123456</nDFSe>
    <emit>
      <CNPJ>{$o['emitente']}</CNPJ>
      <xNome>{$o['emitente_nome']}</xNome>
    </emit>
    <valores>
      <vBC>{$o['valor']}</vBC>
      <vLiq>{$o['valor_liquido']}</vLiq>
    </valores>
    <DPS versao="1.00">
      <infDPS Id="DPS355030824572317400011000001000000000000001">
        <tpAmb>{$o['ambiente']}</tpAmb>
        <dhEmi>2026-11-03T10:14:00-03:00</dhEmi>
        <verAplic>1.0</verAplic>
        <serie>1</serie>
        <nDPS>{$o['numero']}</nDPS>
        <dCompet>{$o['competencia']}</dCompet>
        <tpEmit>1</tpEmit>
        <cLocEmi>3550308</cLocEmi>
        <prest><CNPJ>{$o['emitente']}</CNPJ></prest>
        <toma><{$tagToma}>{$o['tomador']}</{$tagToma}><xNome>{$o['tomador_nome']}</xNome></toma>
        <serv>
          <locPrest><cLocPrestacao>3550308</cLocPrestacao></locPrest>
          <cServ><cTribNac>{$o['cTribNac']}</cTribNac><xDescServ>Suporte técnico N2 - competência</xDescServ></cServ>
        </serv>
        <valores><vServPrest><vServ>{$o['valor']}</vServ></vServPrest></valores>
      </infDPS>
    </DPS>
  </infNFSe>
</NFSe>
XML;
    }
}
