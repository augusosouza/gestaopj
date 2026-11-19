<?php

namespace Tests\Feature;

use App\Models\Atribuicao;
use App\Models\AutorizacaoEscritorio;
use App\Models\Cobranca;
use App\Models\Contrato;
use App\Models\Fatura;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Faturamento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ComContexto;
use Tests\Support\NfseXml;
use Tests\TestCase;

/**
 * Critério de aceite da entrega 1: com duas empresas e um escritório,
 * acesso direto a URL, arquivo ou exportação fora da carteira é recusado.
 */
class IsolamentoTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private Cobranca $cobB;

    private Fatura $fatB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::parse('2026-10-01'));
        $b = $this->empresa(self::B)->id;
        $this->cobB = Cobranca::where('organizacao_id', $b)->sole();
        $this->fatB = Fatura::where('organizacao_id', $b)->sole();
    }

    public function test_url_direta_de_outra_empresa_responde_404(): void
    {
        $admA = $this->como('admin@augi.local');
        $admA->get(route('cobrancas.show', $this->cobB))->assertNotFound();
        $this->como('admin@augi.local')->get(route('faturas.show', $this->fatB))->assertNotFound();
        $this->como('admin@augi.local')->get(route('contratos.show', $this->cobB->contrato_id))->assertNotFound();
        $this->como('admin@augi.local')->get(route('contratos.edit', $this->cobB->contrato_id))->assertNotFound();
        $this->como('admin@augi.local')->get(route('clientes.edit', Contrato::find($this->cobB->contrato_id)->cliente_id))->assertNotFound();
    }

    public function test_acao_em_registro_de_outra_empresa_responde_404_e_nada_muda(): void
    {
        $this->como('financeiro@augi.local')->post(route('faturas.receber', $this->fatB), ['valor' => '1,00', 'data' => '2026-10-10'])->assertNotFound();
        $this->como('admin@augi.local')->post(route('contratos.alocar', $this->cobB->contrato_id), [])->assertNotFound();
        $this->assertNull($this->fatB->fresh()->recebida_em);
    }

    public function test_listas_e_painel_mostram_so_a_empresa_do_contexto(): void
    {
        $this->como('admin@augi.local')->get(route('contratos.index'))->assertSee('CT-2026-001')->assertDontSee('CT-B-001');
        $this->como('admin.b@augi.local', self::B)->get(route('contratos.index'))->assertSee('CT-B-001')->assertDontSee('CT-2026-001');
        $this->como('admin@augi.local')->get(route('painel', ['competencia' => '2026-10']))->assertSee('R$ 12.000,00')->assertDontSee('R$ 8.000,00');
    }

    public function test_xml_de_outra_empresa_nao_baixa(): void
    {
        $this->como('prestador@augi.local', self::B)->post(route('cobrancas.nota', $this->cobB), ['xml' => UploadedFile::fake()->createWithContent('n.xml', NfseXml::gerar([
            'tomador' => '22333444000181', 'valor' => '5000.00',
        ]))])->assertSessionHasNoErrors();
        $notaB = $this->cobB->fresh()->notaFiscal;

        $this->como('admin@augi.local')->get(route('notas.xml', $notaB))->assertNotFound();
        $this->como('admin.b@augi.local', self::B)->get(route('notas.xml', $notaB))->assertOk();
        $this->assertStringStartsWith('nfse/'.$this->empresa(self::B)->id.'/', $notaB->xml_path);
    }

    public function test_exportacao_traz_so_a_empresa_do_contexto(): void
    {
        $resp = $this->como('admin@augi.local')->get(route('fechamento.exportar', ['competencia' => '2026-10']))->assertOk();
        $zip = new \ZipArchive;
        $zip->open($resp->baseResponse->getFile()->getPathname());
        $csv = $zip->getFromName('faturas_receber.csv');
        $this->assertStringContainsString('CT-2026-001', $csv);
        $this->assertStringNotContainsString('CT-B-001', $csv);
        $this->assertDatabaseHas('eventos', ['acao' => 'fechamento.exportado', 'organizacao_id' => $this->empresa()->id]);
    }

    public function test_mesma_nota_pode_ser_registrada_em_empresas_diferentes_mas_nao_duas_vezes_na_mesma(): void
    {
        $chave = NfseXml::chave('45723174000110', 77);
        $this->como('prestador@augi.local', self::B)->post(route('cobrancas.nota', $this->cobB), ['chave_acesso' => $chave])->assertSessionHasNoErrors();
        $cobA = Cobranca::where('organizacao_id', $this->empresa()->id)->sole();
        $this->como('prestador@augi.local')->post(route('cobrancas.nota', $cobA), ['chave_acesso' => $chave])->assertSessionHasNoErrors();
    }

    public function test_prestador_nao_troca_para_vinculo_alheio(): void
    {
        $alheio = Vinculo::where('user_id', User::where('email', 'admin.b@augi.local')->sole()->id)->sole();
        $this->como('prestador@augi.local')->post(route('contexto.selecionar'), ['vinculo' => $alheio->id])->assertForbidden();

        // Sessão adulterada com vínculo de outra pessoa: contexto descartado.
        $this->actingAs(User::where('email', 'prestador@augi.local')->sole())
            ->withSession(['ctx' => ['vinculo' => $alheio->id, 'empresa' => null]])
            ->get(route('contratos.index'))->assertRedirect(route('contexto.escolher'));
    }

    public function test_analista_so_acessa_empresas_atribuidas(): void
    {
        $b = $this->empresa(self::B);
        $this->como('contabilidade@augi.local')->get(route('carteira'))->assertSee('Minha Empresa de Suporte')->assertDontSee('Outra Empresa de Serviços');
        $this->como('contabilidade@augi.local')->post(route('carteira.entrar', $b))->assertForbidden();
        // Sessão adulterada apontando para B
        $this->como('contabilidade@augi.local', self::B)->get(route('cobrancas.index'))->assertRedirect(route('contexto.escolher'));

        $this->como('revisor@augi.local')->get(route('carteira'))->assertSee('Outra Empresa de Serviços');
        $this->como('revisor@augi.local', self::B)->get(route('cobrancas.show', $this->cobB))->assertOk();
    }

    public function test_escritorio_sem_empresa_escolhida_nao_ve_dados(): void
    {
        $revisora = User::where('email', 'revisor@augi.local')->sole();
        $v = Vinculo::where('user_id', $revisora->id)->sole();
        $this->actingAs($revisora)->withSession(['ctx' => ['vinculo' => $v->id, 'empresa' => null]])
            ->get(route('cobrancas.show', $this->cobB))->assertRedirect(route('carteira'));
    }

    public function test_revogar_autorizacao_corta_o_escritorio_na_proxima_acao(): void
    {
        $this->como('revisor@augi.local', self::B)->get(route('cobrancas.index'))->assertOk();

        $aut = AutorizacaoEscritorio::where('empresa_id', $this->empresa(self::B)->id)->sole();
        $this->como('admin.b@augi.local', self::B)->patch(route('organizacao.revogar', $aut))->assertRedirect();
        $this->assertNotNull($aut->fresh()->fim);

        $this->como('revisor@augi.local', self::B)->get(route('cobrancas.index'))->assertRedirect(route('contexto.escolher'));
        $this->como('revisor@augi.local')->get(route('carteira'))->assertDontSee('Outra Empresa de Serviços');
        $this->assertDatabaseHas('eventos', ['acao' => 'autorizacao.revogada']);
    }

    public function test_admin_nao_revoga_autorizacao_de_outra_empresa(): void
    {
        $autB = AutorizacaoEscritorio::where('empresa_id', $this->empresa(self::B)->id)->sole();
        $this->como('admin@augi.local')->patch(route('organizacao.revogar', $autB))->assertNotFound();
        $this->assertNull($autB->fresh()->fim);
    }

    public function test_encerrar_vinculo_corta_o_acesso_e_preserva_historico(): void
    {
        $fin = User::where('email', 'financeiro@augi.local')->sole();
        $v = Vinculo::where('user_id', $fin->id)->sole();
        $this->como('admin@augi.local')->patch(route('usuarios.encerrar', $v))->assertRedirect();

        // Mesma sessão que a pessoa já tinha aberta antes do encerramento.
        $this->actingAs($fin)->withSession(['ctx' => ['vinculo' => $v->id, 'empresa' => null]])
            ->get(route('cobrancas.index'))->assertRedirect(route('contexto.escolher'));
        $this->assertDatabaseHas('vinculos', ['id' => $v->id]);
        $this->assertDatabaseHas('eventos', ['acao' => 'vinculo.encerrado', 'alvo_id' => $v->id]);
    }

    public function test_encerrar_atribuicao_corta_o_analista(): void
    {
        $at = Atribuicao::sole();
        $this->como('revisor@augi.local')->patch(route('escritorio.encerrar', $at))->assertRedirect();
        $this->como('contabilidade@augi.local')->get(route('cobrancas.index'))->assertRedirect(route('contexto.escolher'));
    }

    public function test_escritorio_acessando_empresa_fica_na_auditoria(): void
    {
        $this->como('revisor@augi.local')->get(route('carteira')); // ativa vínculo
        $this->como('revisor@augi.local')->post(route('carteira.entrar', $this->empresa(self::B)))->assertRedirect(route('painel'));
        $this->assertDatabaseHas('eventos', ['acao' => 'escritorio.acesso', 'organizacao_id' => $this->empresa(self::B)->id]);
    }
}
