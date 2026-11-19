<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusCobranca;
use App\Enums\StatusFatura;
use App\Models\Cobranca;
use App\Models\Fatura;
use App\Models\PerfilFiscal;
use App\Models\Prestador;
use App\Models\User;
use App\Services\Fechamento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NfseXml;
use Tests\TestCase;

/** Piloto: fechar um mês inteiro com um CNPJ e um prestador PJ. */
class FechamentoMensalTest extends TestCase
{
    use RefreshDatabase;

    private User $financeiro;

    private User $prestador;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00'));
        $this->seed(DatabaseSeeder::class);
        $this->financeiro = User::where('papel', Papel::Financeiro)->first();
        $this->prestador = User::where('papel', Papel::Prestador)->first();
    }

    private function xml(array $o = []): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('nfse.xml', NfseXml::gerar($o));
    }

    private function gerar(string $comp = '2026-10'): void
    {
        $this->actingAs($this->financeiro)->post(route('competencias.gerar'), ['competencia' => $comp])->assertRedirect();
    }

    public function test_fecha_um_mes_de_ponta_a_ponta(): void
    {
        // 1. Abrir competência: 1 fatura de R$ 12.000 e 1 cobrança de R$ 7.500
        $this->gerar();
        $fatura = Fatura::sole();
        $cobranca = Cobranca::sole();
        $this->assertSame(1_200_000, $fatura->valor_centavos);
        $this->assertSame('2026-11-10', $fatura->vencimento->toDateString());
        $this->assertSame(750_000, $cobranca->valor_centavos);

        // 2. Empresa vincula a NFS-e emitida ao cliente
        $this->actingAs($this->financeiro)->post(route('faturas.nota', $fatura), ['xml' => $this->xml([
            'emitente' => '11222333000181', 'tomador' => '11444777000161', 'valor' => '12000.00', 'numero' => 10,
        ])])->assertSessionHasNoErrors();
        $this->assertSame(StatusFatura::Emitida, $fatura->fresh()->status);

        // 3. Prestador envia a nota dele
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', $cobranca), ['xml' => $this->xml(['valor' => '7500.00', 'valor_liquido' => '7012.50'])])
            ->assertSessionHasNoErrors();
        $cobranca->refresh();
        $this->assertSame(StatusCobranca::EmConferencia, $cobranca->status);
        $this->assertTrue($cobranca->conferenciaOk(), json_encode($cobranca->conferencia));

        // 4. Financeiro aprova e paga o líquido
        $this->actingAs($this->financeiro)->post(route('cobrancas.aprovar', $cobranca))->assertSessionHasNoErrors();
        $this->actingAs($this->financeiro)->post(route('cobrancas.pagar', $cobranca), ['valor' => '7.012,50', 'data' => '2026-11-15'])->assertSessionHasNoErrors();

        // 5. Cliente paga
        $this->actingAs($this->financeiro)->post(route('faturas.receber', $fatura), ['valor' => '12.000,00', 'data' => '2026-11-10'])->assertSessionHasNoErrors();

        // As quatro perguntas
        $r = app(Fechamento::class)->resumo(CarbonImmutable::parse('2026-10-01'));
        $this->assertSame(1_200_000, $r['faturado']);
        $this->assertSame(1_200_000, $r['recebido']);
        $this->assertSame(0, $r['devido_prestadores']);
        $this->assertCount(0, $r['pendencias']);
        $this->assertSame(450_000, $r['contratos'][0]['margem']);

        $this->actingAs($this->financeiro)->get(route('painel', ['competencia' => '2026-10']))
            ->assertOk()->assertSee('R$ 12.000,00')->assertSee('Nenhuma pendência');
    }

    public function test_gerar_competencia_e_idempotente(): void
    {
        $this->gerar();
        $this->gerar();
        $this->assertSame(1, Fatura::count());
        $this->assertSame(1, Cobranca::count());
    }

    public function test_painel_mostra_as_pendencias_do_mes(): void
    {
        $this->gerar();
        $r = app(Fechamento::class)->resumo(CarbonImmutable::parse('2026-10-01'));
        $this->assertSame(1_200_000, $r['faturado']);
        $this->assertSame(0, $r['recebido']);
        $this->assertSame(750_000, $r['devido_prestadores']);
        $this->assertSame(['Nota a emitir', 'Nota do prestador não enviada'], $r['pendencias']->pluck('tipo')->all());
    }

    public function test_conferencia_aponta_divergencias_e_exige_justificativa(): void
    {
        $this->gerar();
        $cobranca = Cobranca::sole();
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', $cobranca), ['xml' => $this->xml([
            'valor' => '7000.00', 'competencia' => '2026-09-01', 'tomador' => '11444777000161',
        ])]);
        $cobranca->refresh();
        $falhas = collect($cobranca->conferencia)->where('ok', false)->pluck('item')->all();
        $this->assertContains('Valor do serviço confere', $falhas);
        $this->assertContains('Competência confere', $falhas);
        $this->assertContains('Tomador é a empresa contratante', $falhas);

        $this->actingAs($this->financeiro)->post(route('cobrancas.aprovar', $cobranca))->assertSessionHasErrors('justificativa');
        $this->assertSame(StatusCobranca::EmConferencia, $cobranca->fresh()->status);

        // Rejeita; prestador reenvia corrigida
        $this->actingAs($this->financeiro)->post(route('cobrancas.rejeitar', $cobranca), ['motivo' => 'Valor e tomador errados'])->assertSessionHasNoErrors();
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', $cobranca), ['xml' => $this->xml(['numero' => 2])])->assertSessionHasNoErrors();
        $this->assertTrue($cobranca->fresh()->conferenciaOk());
        $this->assertDatabaseCount('notas_fiscais', 1);
    }

    public function test_nota_duplicada_e_recusada(): void
    {
        $this->gerar();
        $this->gerar('2026-11');
        [$out, $nov] = Cobranca::orderBy('competencia')->get();
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', $out), ['xml' => $this->xml()])->assertSessionHasNoErrors();
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', $nov), ['xml' => $this->xml()])
            ->assertSessionHasErrors('xml');
        $this->assertSame(StatusCobranca::AguardandoNota, $nov->fresh()->status);
    }

    public function test_so_chave_permite_aprovar_com_ressalva_e_xml_completa_depois(): void
    {
        $this->gerar();
        $cobranca = Cobranca::sole();
        $chave = NfseXml::chave('45723174000110', 1);
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', $cobranca), ['chave_acesso' => $chave])->assertSessionHasNoErrors();
        $cobranca->refresh();
        $this->assertFalse($cobranca->conferenciaOk());
        $this->assertSame('chave', $cobranca->notaFiscal->origem);

        $this->actingAs($this->financeiro)->post(route('cobrancas.aprovar', $cobranca), ['justificativa' => 'XML será obtido no ADN'])->assertSessionHasNoErrors();
        $this->assertSame(StatusCobranca::Aprovada, $cobranca->fresh()->status);
    }

    public function test_chave_com_tamanho_errado_e_recusada(): void
    {
        $this->gerar();
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', Cobranca::sole()), ['chave_acesso' => '123'])->assertSessionHasErrors('chave_acesso');
    }

    public function test_nota_de_producao_restrita_nao_passa_na_conferencia(): void
    {
        $this->gerar();
        $cobranca = Cobranca::sole();
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', $cobranca), ['xml' => $this->xml(['ambiente' => '2'])]);
        $this->assertFalse($cobranca->fresh()->conferenciaOk());
    }

    public function test_perfil_fiscal_nao_confirmado_e_aviso_nao_bloqueante(): void
    {
        $this->gerar();
        $cobranca = Cobranca::sole();
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', $cobranca), ['xml' => $this->xml()]);
        $item = collect($cobranca->fresh()->conferencia)->firstWhere('item', 'Perfil fiscal do prestador confirmado pela contabilidade');
        $this->assertFalse($item['ok']);
        $this->assertFalse($item['bloqueante']);

        $contab = User::where('papel', Papel::Contabilidade)->first();
        $perfil = PerfilFiscal::where('cnpj', '45723174000110')->sole();
        $this->actingAs($contab)->post(route('perfis.confirmar', $perfil))->assertRedirect();
        $this->assertNotNull($perfil->fresh()->confirmado_em);
    }

    public function test_nota_emitida_com_dados_divergentes_nao_e_vinculada(): void
    {
        $this->gerar();
        $fatura = Fatura::sole();
        $this->actingAs($this->financeiro)->post(route('faturas.nota', $fatura), ['xml' => $this->xml([
            'emitente' => '11222333000181', 'tomador' => '11444777000161', 'valor' => '11000.00',
        ])])->assertSessionHasErrors('xml');
        $this->assertSame(StatusFatura::Aberta, $fatura->fresh()->status);
        $this->assertDatabaseCount('notas_fiscais', 0);
    }

    public function test_exporta_zip_para_contabilidade(): void
    {
        $this->gerar();
        $this->actingAs($this->prestador)->post(route('cobrancas.nota', Cobranca::sole()), ['xml' => $this->xml()]);
        $contab = User::where('papel', Papel::Contabilidade)->first();

        $resp = $this->actingAs($contab)->get(route('fechamento.exportar', ['competencia' => '2026-10']))->assertOk();
        $zip = new \ZipArchive;
        $zip->open($resp->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($zip->locateName('resumo.csv'));
        $this->assertNotFalse($zip->locateName('faturas_receber.csv'));
        $this->assertNotFalse($zip->locateName('cobrancas_pagar.csv'));
        $this->assertNotFalse($zip->locateName('xml/recebidas/'.NfseXml::chave('45723174000110', 1).'.xml'));
        $zip->close();
    }
}
