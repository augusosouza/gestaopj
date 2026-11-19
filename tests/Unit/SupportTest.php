<?php

namespace Tests\Unit;

use App\Support\Dinheiro;
use App\Support\Documento;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_converte_valores_sem_float(): void
    {
        $this->assertSame(1_200_000, Dinheiro::paraCentavos('12.000,00'));
        $this->assertSame(1_200_000, Dinheiro::paraCentavos('12000'));
        $this->assertSame(1_200_050, Dinheiro::paraCentavos('12000.5'));
        $this->assertSame(1, Dinheiro::paraCentavos('0,01'));
        $this->assertSame(750_000, Dinheiro::paraCentavos('R$ 7.500,00'));
        $this->assertSame('R$ 12.000,00', Dinheiro::formatar(1_200_000));
    }

    public function test_rejeita_valor_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dinheiro::paraCentavos('12,000.00.1');
    }

    public function test_valida_documentos(): void
    {
        $this->assertTrue(Documento::cnpjValido('11.222.333/0001-81'));
        $this->assertFalse(Documento::cnpjValido('11.222.333/0001-82'));
        $this->assertFalse(Documento::cnpjValido('00000000000000'));
        $this->assertTrue(Documento::cpfValido('529.982.247-25'));
        $this->assertFalse(Documento::cpfValido('529.982.247-24'));
        $this->assertSame('11.222.333/0001-81', Documento::formatar('11222333000181'));
    }
}
