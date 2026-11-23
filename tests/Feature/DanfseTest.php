<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\NotaFiscal;
use App\Services\Faturamento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ComContexto;
use Tests\Support\NfseXml;
use Tests\TestCase;

/** Integração com o gerador oficial de DANFSe da Receita (serviço simulado nos testes). */
class DanfseTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private Cobranca $cob;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['gestao.receita.danfse_url' => 'http://danfse:8080', 'gestao.receita.danfse_versao' => '1.0.0 (8d79e6b)']);
        $this->seed(DatabaseSeeder::class);
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::parse('2026-10-01'));
        $this->cob = Cobranca::where('organizacao_id', $this->empresa()->id)->sole();
    }

    private function enviarXml(): NotaFiscal
    {
        $this->como('prestador@augi.local')->post(route('cobrancas.nota', $this->cob), [
            'xml' => UploadedFile::fake()->createWithContent('n.xml', NfseXml::gerar()),
        ])->assertSessionHasNoErrors();

        return $this->cob->fresh()->notaFiscal;
    }

    public function test_gera_o_pdf_uma_vez_guarda_e_registra_a_versao(): void
    {
        Http::fake(['danfse:8080/api/danfse' => Http::response("%PDF-1.7\nfake", 200, ['Content-Type' => 'application/pdf'])]);
        $nota = $this->enviarXml();

        $r = $this->como('financeiro@augi.local')->get(route('notas.danfse', $nota))->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('DANFSe-'.$nota->chave_acesso.'.pdf', $r->headers->get('Content-Disposition'));

        Http::assertSent(fn (Request $req) => $req->url() === 'http://danfse:8080/api/danfse'
            && $req->header('Content-Type')[0] === 'application/xml'
            && str_contains($req->body(), '<infNFSe'));

        $nota->refresh();
        $this->assertSame('1.0.0 (8d79e6b)', $nota->danfse_versao);
        $this->assertStringEndsWith('.pdf', $nota->danfse_path);
        $this->assertDatabaseHas('eventos', ['acao' => 'nota.danfse_gerado']);

        // Segunda vez: usa o PDF guardado, não chama o serviço de novo
        $this->como('financeiro@augi.local')->get(route('notas.danfse', $nota))->assertOk();
        Http::assertSentCount(1);
    }

    public function test_xml_recusado_pelo_componente_mostra_mensagem_clara(): void
    {
        Http::fake(['*' => Http::response('{"message":"xml inválido"}', 422)]);
        $nota = $this->enviarXml();
        $this->como('financeiro@augi.local')->from(route('cobrancas.show', $this->cob))->get(route('notas.danfse', $nota))
            ->assertRedirect()->assertSessionHasErrors('danfse');
        $this->assertNull($nota->fresh()->danfse_path);
    }

    public function test_servico_fora_do_ar(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $nota = $this->enviarXml();
        $this->como('financeiro@augi.local')->get(route('notas.danfse', $nota))->assertSessionHasErrors('danfse');
    }

    public function test_desligado_ou_so_chave(): void
    {
        Http::fake();
        config(['gestao.receita.danfse_url' => null]);
        $nota = $this->enviarXml();
        $this->como('financeiro@augi.local')->get(route('cobrancas.show', $this->cob))->assertDontSee('PDF (DANFSe)');
        $this->como('financeiro@augi.local')->get(route('notas.danfse', $nota))->assertSessionHasErrors('danfse');
        Http::assertNothingSent();
    }

    public function test_prestador_so_baixa_o_proprio_e_outra_empresa_nao_ve(): void
    {
        Http::fake(['*' => Http::response("%PDF-1.7\nfake", 200)]);
        $nota = $this->enviarXml();
        $this->como('prestador@augi.local')->get(route('notas.danfse', $nota))->assertOk();
        $this->como('admin.b@augi.local', self::B)->get(route('notas.danfse', $nota))->assertNotFound();
    }
}
