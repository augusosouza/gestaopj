<?php

namespace Tests\Feature;

use App\Enums\EtapaContabil;
use App\Models\Anexo;
use App\Models\CompetenciaContabil;
use App\Models\Compra;
use App\Models\Evento;
use App\Models\Titulo;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** Anexos: disco privado por empresa, validação de conteúdo, auditoria, isolamento e mês fechado. */
class AnexosTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private Titulo $titulo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
        $this->como('financeiro@augi.local')->post(route('financeiro.store'), ['tipo' => 'pagar', 'descricao' => 'Aluguel', 'competencia' => '2026-11',
            'valor' => '1.000,00', 'parcelas' => 1, 'vencimento' => '2026-11-25'])->assertSessionHasNoErrors();
        $this->titulo = Titulo::where('descricao', 'Aluguel')->sole();
    }

    private function pdf(string $nome = 'comprovante.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function enviar(UploadedFile $f, string $tipo = 'titulo', ?int $id = null, string $email = 'financeiro@augi.local')
    {
        return $this->como($email)->post(route('anexos.store', [$tipo, $id ?? $this->titulo->id]), ['arquivo' => $f, 'descricao' => 'Comprovante Pix']);
    }

    public function test_anexa_em_area_privada_com_hash_e_auditoria(): void
    {
        $this->enviar($this->pdf())->assertRedirect()->assertSessionHasNoErrors();
        $a = Anexo::sole();
        $this->assertSame(['comprovante.pdf', 'application/pdf', 'Comprovante Pix', $this->titulo->id], [$a->nome_original, $a->mime, $a->descricao, $a->anexavel_id]);
        $this->assertMatchesRegularExpression('#^anexos/'.$this->empresa()->id.'/[0-9a-f-]{36}\.pdf$#', $a->caminho, 'pasta da empresa, nome aleatório');
        Storage::disk('local')->assertExists($a->caminho);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($a->caminho)), $a->sha256);
        $this->assertSame(1, Evento::where('acao', 'financeiro.anexo_enviado')->count());

        $r = $this->como('financeiro@augi.local')->get(route('anexos.baixar', $a));
        $r->assertOk()->assertDownload('comprovante.pdf');
        $this->assertSame(1, Evento::where('acao', 'financeiro.anexo_baixado')->count());
        $this->como('financeiro@augi.local')->get(route('financeiro.titulo', [$this->titulo, 'aba' => 'documentos']))->assertOk()->assertSee('comprovante.pdf');
    }

    public function test_recusa_tipo_e_conteudo_falsificado(): void
    {
        $this->enviar(UploadedFile::fake()->createWithContent('programa.exe', 'MZ'))->assertSessionHasErrors(['arquivo' => 'Tipo não aceito. Envie PDF, JPG, PNG, WEBP ou XML.']);
        $this->enviar(UploadedFile::fake()->createWithContent('falso.pdf', "<?php echo 'oi';"))
            ->assertSessionHasErrors(['arquivo' => 'O conteúdo do arquivo não é pdf (detectado: text/x-php).']);
        $this->enviar(UploadedFile::fake()->createWithContent('vazio.pdf', ''))->assertSessionHasErrors('arquivo');
        $this->enviar(UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf'))->assertSessionHasErrors('arquivo');
        $this->assertSame(0, Anexo::count());
        $this->assertSame([], Storage::disk('local')->allFiles('anexos'));
    }

    public function test_isolamento_e_permissoes(): void
    {
        $this->enviar($this->pdf());
        $a = Anexo::sole();
        $this->como('admin.b@augi.local', self::B)->get(route('anexos.baixar', $a))->assertNotFound();
        $this->como('admin.b@augi.local', self::B)->delete(route('anexos.remover', $a))->assertNotFound();
        $this->enviar($this->pdf(), 'titulo', $this->titulo->id, 'admin.b@augi.local')->assertNotFound(); // ID de outra empresa
        $this->como('prestador@augi.local')->get(route('anexos.baixar', $a))->assertForbidden();
        $this->como('contabilidade@augi.local')->get(route('anexos.baixar', $a))->assertOk(); // escritório consulta
        $this->enviar($this->pdf(), 'titulo', null, 'contabilidade@augi.local')->assertForbidden(); // mas não envia
        $this->como('contabilidade@augi.local')->delete(route('anexos.remover', $a))->assertForbidden();
        $this->como('financeiro@augi.local')->post(route('anexos.store', ['parcela', $this->titulo->id]), ['arquivo' => $this->pdf()])->assertNotFound();
    }

    public function test_remover_e_mes_fechado(): void
    {
        $this->enviar($this->pdf());
        $a = Anexo::sole();
        CompetenciaContabil::create(['organizacao_id' => $this->empresa()->id, 'competencia' => '2026-11-01', 'etapa' => EtapaContabil::Fechada]);
        $this->como('financeiro@augi.local')->delete(route('anexos.remover', $a))->assertSessionHasErrors('arquivo');
        $this->enviar($this->pdf('outro.pdf'))->assertSessionHasErrors('arquivo');
        $this->assertSame(1, Anexo::count());

        CompetenciaContabil::query()->update(['etapa' => EtapaContabil::Coleta]);
        $this->como('financeiro@augi.local')->delete(route('anexos.remover', $a))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, Anexo::count());
        Storage::disk('local')->assertMissing($a->caminho);
        $this->assertSame(['arquivo' => 'comprovante.pdf', 'sha256' => substr($a->sha256, 0, 12)], Evento::where('acao', 'financeiro.anexo_removido')->sole()->dados);
    }

    public function test_anexo_da_compra_aparece_no_lancamento(): void
    {
        $this->como('financeiro@augi.local')->post(route('compras.store'), ['descricao' => 'Notebook', 'data_compra' => '2026-11-20', 'modalidade' => 'pix',
            'valor_bruto' => '4.500,00', 'parcelas' => 1, 'primeiro_vencimento' => '2026-12-10'])->assertSessionHasNoErrors();
        $c = Compra::sole();
        $this->enviar(UploadedFile::fake()->image('nota.png'), 'compra', $c->id)->assertSessionHasNoErrors();
        $this->como('financeiro@augi.local')->get(route('compras.show', [$c, 'aba' => 'documentos']))->assertOk()->assertSee('nota.png');
        $this->como('financeiro@augi.local')->get(route('financeiro.titulo', [$c->titulo, 'aba' => 'documentos']))->assertOk()->assertSee('nota.png')->assertSee('da compra');
    }
}
