<?php

namespace Tests\Unit;

use App\Services\Nfse\ChaveAcesso;
use App\Services\Nfse\NfseXmlInvalido;
use App\Services\Nfse\NfseXmlParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\NfseXml;

class NfseXmlParserTest extends TestCase
{
    public function test_le_campos_da_nfse_nacional(): void
    {
        $d = (new NfseXmlParser)->ler(NfseXml::gerar(['valor' => '7500.00', 'valor_liquido' => '7012.50', 'numero' => 42]));

        $this->assertSame(NfseXml::chave('45723174000110', 42), $d->chaveAcesso);
        $this->assertSame('42', $d->numero);
        $this->assertSame('45723174000110', $d->emitenteDocumento);
        $this->assertSame('11222333000181', $d->tomadorDocumento);
        $this->assertSame('2026-10-01', $d->competencia->toDateString());
        $this->assertSame(750000, $d->valorServicosCentavos);
        $this->assertSame(701250, $d->valorLiquidoCentavos);
        $this->assertSame('010701', $d->codigoServico);
        $this->assertSame('1', $d->ambiente);
    }

    public function test_aceita_tomador_pessoa_fisica(): void
    {
        $d = (new NfseXmlParser)->ler(NfseXml::gerar(['tomador' => '52998224725']));
        $this->assertSame('52998224725', $d->tomadorDocumento);
    }

    public function test_rejeita_dps_sem_nota_gerada(): void
    {
        $this->expectException(NfseXmlInvalido::class);
        $this->expectExceptionMessage('DPS');
        (new NfseXmlParser)->ler('<DPS xmlns="http://www.sped.fazenda.gov.br/nfse"><infDPS/></DPS>');
    }

    public function test_rejeita_doctype_para_evitar_xxe(): void
    {
        $this->expectException(NfseXmlInvalido::class);
        (new NfseXmlParser)->ler('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><NFSe>&e;</NFSe>');
    }

    public function test_rejeita_arquivo_que_nao_e_xml(): void
    {
        $this->expectException(NfseXmlInvalido::class);
        (new NfseXmlParser)->ler('%PDF-1.4 não é xml');
    }

    public function test_decompoe_chave_de_acesso(): void
    {
        $c = ChaveAcesso::tentar(NfseXml::chave('45723174000110', 987));
        $this->assertSame('3550308', $c->municipio());
        $this->assertSame('45723174000110', $c->inscricaoFederal());
        $this->assertSame('987', $c->numero());
        $this->assertSame('2611', $c->anoMes());
        $this->assertNull(ChaveAcesso::tentar('123'));
    }
}
