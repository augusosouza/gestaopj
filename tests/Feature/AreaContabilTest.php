<?php

namespace Tests\Feature;

use App\Enums\EtapaContabil;
use App\Enums\StatusSolicitacao;
use App\Models\CompetenciaContabil;
use App\Models\DocumentoContabil;
use App\Models\Solicitacao;
use App\Models\User;
use App\Models\VersaoFechamento;
use App\Models\Vinculo;
use App\Services\Faturamento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** Entrega 3: empresa envia, escritório pede, confere, fecha (versão) e reabre com justificativa. */
class AreaContabilTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private const MES = '2026-10';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::parse('2026-10-01'));
    }

    private function pdf(string $nome = 'extrato.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, "%PDF-1.4\nconteudo de teste");
    }

    private function cc(string $cnpj = self::A): CompetenciaContabil
    {
        return CompetenciaContabil::where('organizacao_id', $this->empresa($cnpj)->id)->sole();
    }

    public function test_ciclo_completo_ate_o_fechamento_versionado(): void
    {
        // Empresa envia documento e conclui o envio
        $this->como('financeiro@augi.local')->post(route('contabil.enviar_documento', self::MES), [
            'arquivo' => $this->pdf(), 'categoria' => 'extrato_bancario', 'descricao' => 'Banco X',
        ])->assertSessionHasNoErrors();
        $this->como('financeiro@augi.local')->post(route('contabil.enviar', self::MES))->assertSessionHasNoErrors();
        $this->assertSame(EtapaContabil::Conferencia, $this->cc()->etapa);
        $doc = DocumentoContabil::sole();
        $this->assertSame(hash('sha256', "%PDF-1.4\nconteudo de teste"), $doc->sha256);
        $this->assertDatabaseHas('eventos', ['acao' => 'contabil.documento_enviado']);
        $this->como('financeiro@augi.local')->get(route('contabil.show', self::MES))->assertSee('extrato.pdf · Extrato bancário');

        // Analista vê, baixa e pede mais um documento
        $this->como('contabilidade@augi.local')->get(route('contabil.show', self::MES))->assertOk()->assertSee('extrato.pdf');
        $this->como('contabilidade@augi.local')->get(route('contabil.documento', $doc))->assertOk();
        $this->como('contabilidade@augi.local')->post(route('contabil.solicitar', self::MES), ['titulo' => 'Guia do DAS paga'])->assertSessionHasNoErrors();
        $sol = Solicitacao::sole();
        $this->assertSame(EtapaContabil::Coleta, $this->cc()->etapa, 'pedir algo devolve à coleta');

        // Aparece como pendência no painel da empresa
        $this->como('financeiro@augi.local')->get(route('painel', ['competencia' => self::MES]))->assertSee('Pedido do contador')->assertSee('Guia do DAS paga');

        // Empresa responde com anexo
        $this->como('financeiro@augi.local')->post(route('contabil.responder', $sol), ['resposta' => 'Segue a guia', 'arquivo' => $this->pdf('das.pdf')])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatusSolicitacao::Respondida, $sol->fresh()->status);
        $this->assertSame(1, $sol->fresh()->documentos()->count());

        // Analista aceita e leva à revisão; analista não fecha
        $this->como('contabilidade@augi.local')->post(route('contabil.aceitar', $sol))->assertSessionHasNoErrors();
        $this->como('contabilidade@augi.local')->post(route('contabil.etapa', self::MES), ['etapa' => 'revisao'])->assertSessionHasNoErrors();
        $this->como('contabilidade@augi.local')->post(route('contabil.fechar', self::MES))->assertForbidden();

        // Revisor fecha: versão 1 com pacote completo
        $this->como('revisor@augi.local')->post(route('contabil.fechar', self::MES), ['observacao' => 'ok'])->assertSessionHasNoErrors();
        $cc = $this->cc();
        $this->assertSame(EtapaContabil::Fechada, $cc->etapa);
        $v1 = VersaoFechamento::sole();
        $this->assertSame(1, $v1->versao);
        $this->assertSame(1_200_000, $v1->resumo['faturado']);
        $zipPath = Storage::disk('local')->path($v1->arquivo_path);
        $this->assertSame($v1->sha256, hash_file('sha256', $zipPath));
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        foreach (['resumo.csv', 'faturas_receber.csv', 'cobrancas_pagar.csv', 'solicitacoes.csv', 'LEIA-ME.txt'] as $f) {
            $this->assertNotFalse($zip->locateName($f), $f);
        }
        $this->assertNotFalse($zip->locateName("documentos/extrato_bancario/{$doc->id}-extrato.pdf"));
        $this->assertStringContainsString($doc->sha256, $zip->getFromName('LEIA-ME.txt'));
        $zip->close();

        // Fechada: empresa não envia mais nada
        $this->como('financeiro@augi.local')->post(route('contabil.enviar_documento', self::MES), ['arquivo' => $this->pdf('tarde.pdf'), 'categoria' => 'outro'])
            ->assertSessionHasErrors('arquivo');
        $this->assertSame(2, DocumentoContabil::count());
    }

    public function test_reabertura_exige_justificativa_e_preserva_a_versao_anterior(): void
    {
        $this->como('revisor@augi.local')->post(route('contabil.etapa', self::MES), ['etapa' => 'revisao']);
        $this->como('revisor@augi.local')->post(route('contabil.fechar', self::MES))->assertSessionHasNoErrors();
        $v1 = VersaoFechamento::sole();

        $this->como('contabilidade@augi.local')->post(route('contabil.reabrir', self::MES), ['justificativa' => 'x'])->assertForbidden();
        $this->como('revisor@augi.local')->post(route('contabil.reabrir', self::MES))->assertSessionHasErrors('justificativa');
        $this->como('revisor@augi.local')->post(route('contabil.reabrir', self::MES), ['justificativa' => 'Nota substituída'])->assertSessionHasNoErrors();
        $this->assertSame(EtapaContabil::Revisao, $this->cc()->etapa);
        $this->assertDatabaseHas('eventos', ['acao' => 'contabil.reaberta']);

        $this->como('financeiro@augi.local')->post(route('contabil.enviar_documento', self::MES), ['arquivo' => $this->pdf('nova.pdf'), 'categoria' => 'nota_entrada'])
            ->assertSessionHasNoErrors();
        $this->como('revisor@augi.local')->post(route('contabil.fechar', self::MES))->assertSessionHasNoErrors();

        $this->assertSame([1, 2], VersaoFechamento::orderBy('versao')->pluck('versao')->all());
        $this->assertSame(2, $this->cc()->versao_atual);
        $this->assertTrue(Storage::disk('local')->exists($v1->fresh()->arquivo_path), 'v1 continua guardada');
        $this->assertSame(0, $v1->fresh()->resumo['documentos']);
        $this->assertSame(1, VersaoFechamento::where('versao', 2)->sole()->resumo['documentos']);
        $this->como('revisor@augi.local')->get(route('contabil.versao', $v1))->assertOk();
    }

    public function test_nao_fecha_com_solicitacao_pendente_nem_fora_da_revisao(): void
    {
        $this->como('revisor@augi.local')->post(route('contabil.fechar', self::MES))->assertSessionHasErrors('fechar');
        $this->como('revisor@augi.local')->post(route('contabil.solicitar', self::MES), ['titulo' => 'Folha']);
        $this->como('revisor@augi.local')->post(route('contabil.etapa', self::MES), ['etapa' => 'revisao']);
        $this->como('revisor@augi.local')->post(route('contabil.fechar', self::MES))->assertSessionHasErrors('fechar');
        $this->assertSame(0, VersaoFechamento::count());

        $this->como('revisor@augi.local')->post(route('contabil.cancelar', Solicitacao::sole()));
        $this->como('revisor@augi.local')->post(route('contabil.fechar', self::MES))->assertSessionHasNoErrors();
    }

    public function test_devolver_resposta_insuficiente(): void
    {
        $this->como('contabilidade@augi.local')->post(route('contabil.solicitar', self::MES), ['titulo' => 'Extrato']);
        $sol = Solicitacao::sole();
        $this->como('financeiro@augi.local')->post(route('contabil.responder', $sol), [])->assertSessionHasErrors('resposta');
        $this->como('financeiro@augi.local')->post(route('contabil.responder', $sol), ['resposta' => 'Não tenho']);
        $this->como('contabilidade@augi.local')->post(route('contabil.devolver', $sol), ['motivo' => 'Precisamos do PDF do banco'])->assertSessionHasNoErrors();
        $sol->refresh();
        $this->assertSame(StatusSolicitacao::Aberta, $sol->status);
        $this->assertStringContainsString('Precisamos do PDF do banco', $sol->descricao);
    }

    public function test_papeis_e_isolamento(): void
    {
        // Empresa não pede nem fecha; contador não envia como empresa; prestador fora.
        $this->como('financeiro@augi.local')->post(route('contabil.solicitar', self::MES), ['titulo' => 'x'])->assertForbidden();
        $this->como('admin@augi.local')->post(route('contabil.etapa', self::MES), ['etapa' => 'revisao'])->assertForbidden();
        $this->como('contabilidade@augi.local')->post(route('contabil.enviar_documento', self::MES), ['arquivo' => $this->pdf(), 'categoria' => 'outro'])->assertForbidden();
        $this->como('prestador@augi.local')->get(route('contabil.index'))->assertForbidden();

        // Documento da empresa B não abre para a empresa A nem para o analista (que só atende A)
        $this->como('admin.b@augi.local', self::B)->post(route('contabil.enviar_documento', self::MES), ['arquivo' => $this->pdf('b.pdf'), 'categoria' => 'outro']);
        $docB = DocumentoContabil::where('organizacao_id', $this->empresa(self::B)->id)->sole();
        $this->como('financeiro@augi.local')->get(route('contabil.documento', $docB))->assertNotFound();
        $this->como('contabilidade@augi.local')->get(route('contabil.documento', $docB))->assertNotFound();
        $this->como('revisor@augi.local', self::B)->get(route('contabil.documento', $docB))->assertOk();
        $this->assertStringStartsWith('contabil/'.$this->empresa(self::B)->id.'/', $docB->arquivo_path);
    }

    public function test_tipo_de_arquivo_nao_aceito(): void
    {
        $this->como('financeiro@augi.local')->post(route('contabil.enviar_documento', self::MES), [
            'arquivo' => UploadedFile::fake()->createWithContent('virus.exe', 'MZ'), 'categoria' => 'outro',
        ])->assertSessionHasErrors('arquivo');
    }

    /** Escritório na carteira, sem empresa escolhida (como fica logo após o login). */
    private function naCarteira(string $email): static
    {
        $u = User::where('email', $email)->sole();
        $v = Vinculo::where('user_id', $u->id)->sole();

        return $this->actingAs($u)->withSession(['ctx' => ['vinculo' => $v->id, 'empresa' => null]]);
    }

    public function test_carteira_mostra_situacao_por_empresa_e_filtra(): void
    {
        $this->como('admin.b@augi.local', self::B)->post(route('contabil.enviar_documento', self::MES), ['arquivo' => $this->pdf(), 'categoria' => 'outro']);
        $this->como('admin.b@augi.local', self::B)->post(route('contabil.enviar', self::MES));
        $this->como('revisor@augi.local')->post(route('contabil.solicitar', self::MES), ['titulo' => 'Folha']);

        $this->naCarteira('revisor@augi.local')->get(route('carteira', ['mes' => self::MES]))->assertOk()
            ->assertSee('Minha Empresa de Suporte')->assertSee('Outra Empresa de Serviços');
        $this->naCarteira('revisor@augi.local')->get(route('carteira', ['mes' => self::MES, 'filtro' => 'com_cliente']))
            ->assertSee('Minha Empresa de Suporte')->assertDontSee('Outra Empresa de Serviços');
        $this->naCarteira('revisor@augi.local')->get(route('carteira', ['mes' => self::MES, 'filtro' => 'com_contador']))
            ->assertSee('Outra Empresa de Serviços')->assertDontSee('Minha Empresa de Suporte');

        // Analista só vê a empresa atribuída
        $this->naCarteira('contabilidade@augi.local')->get(route('carteira', ['mes' => self::MES]))->assertDontSee('Outra Empresa de Serviços');

        // Abrir da carteira leva direto à competência
        $this->naCarteira('revisor@augi.local')->post(route('carteira.entrar', $this->empresa(self::B)), ['mes' => self::MES])
            ->assertRedirect(route('contabil.show', self::MES));
    }
}
