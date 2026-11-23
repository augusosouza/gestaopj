<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\User;
use App\Services\Backup\Backups;
use App\Services\Backup\ConfigBackup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Backup base + incremental, criptografado, com restauração completa a partir do destino. */
class BackupTest extends TestCase
{
    use DatabaseMigrations; // a restauração recria as tabelas: sem transação envolvendo o teste

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/augi-backup-teste-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app/private');
        $this->app->useStoragePath($this->storage);
        $this->seed(DatabaseSeeder::class);
        $cfg = app(ConfigBackup::class);
        $cfg->gravar('destino', 'local');
        $cfg->gravar('senha', 'SENHA-DE-TESTE-1234');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function arquivo(string $rel, string $conteudo, ?int $mtime = null): void
    {
        $p = $this->storage.'/app/private/'.$rel;
        File::ensureDirectoryExists(dirname($p));
        file_put_contents($p, $conteudo);
        if ($mtime) {
            touch($p, $mtime);
        }
    }

    private function ler(string $rel): ?string
    {
        $p = $this->storage.'/app/private/'.$rel;

        return is_file($p) ? file_get_contents($p) : null;
    }

    public function test_base_incremental_e_restauracao_de_qualquer_ponto(): void
    {
        $b = app(Backups::class);
        $this->arquivo('nfse/1/recebida/a.xml', '<a/>');
        $this->arquivo('contabil/1/2026-10/b.pdf', 'versao-1');
        $this->arquivo('nfse/1/emitida/d.xml', '<inalterado/>'); // só existe na base

        // BASE
        $base = $b->executar('manual');
        $this->assertSame('concluido', $base->status, (string) $base->erro);
        $this->assertSame('base', $base->tipo);
        $this->assertSame(3, $base->arquivos_enviados);
        $this->assertGreaterThan(10, $base->linhas);
        $this->assertFileExists($this->storage.'/app/backup-destino-local/augi-backups/'.$base->nome);
        $this->assertStringStartsWith('AUGIBK01', file_get_contents($this->storage.'/app/backup-destino-local/augi-backups/'.$base->nome, length: 8));

        // Mudanças: altera b, cria c, remove a, novo registro no banco
        $this->travel(2)->seconds();
        $this->arquivo('contabil/1/2026-10/b.pdf', 'versao-2-maior', time() + 5);
        $this->arquivo('contabil/1/2026-11/c.ofx', 'extrato');
        unlink($this->storage.'/app/private/nfse/1/recebida/a.xml');
        Cliente::create(['organizacao_id' => 1, 'razao_social' => 'Cliente Novo Depois da Base', 'documento' => '22333444000181']);

        // INCREMENTAL: só o que mudou
        $this->travel(2)->seconds();
        $inc = $b->executar('agenda');
        $this->assertSame('concluido', $inc->status, (string) $inc->erro);
        $this->assertSame('incremental', $inc->tipo);
        $this->assertSame($base->nome, $inc->base_nome);
        $this->assertSame(2, $inc->arquivos_enviados, 'só b (alterado) e c (novo)');
        $this->assertSame(3, $inc->arquivos_total);
        $this->assertSame(1, $inc->arquivos_removidos);

        // DESASTRE: some tudo
        File::cleanDirectory($this->storage.'/app/private');
        $this->arquivo('lixo/intruso.txt', 'não existia no backup');
        Cliente::where('razao_social', 'Cliente Novo Depois da Base')->delete();

        // Restaura o ponto mais recente (usa a base + o incremental)
        $r = $b->restaurar();
        $this->assertSame($inc->nome, $r['pacote']);
        $this->assertSame(2, $r['pacotes_usados'], 'd vem da base; b e c do incremental');
        $this->assertSame('<inalterado/>', $this->ler('nfse/1/emitida/d.xml'));
        $this->assertNull($this->ler('nfse/1/recebida/a.xml'), 'removido antes do incremental');
        $this->assertSame('versao-2-maior', $this->ler('contabil/1/2026-10/b.pdf'));
        $this->assertSame('extrato', $this->ler('contabil/1/2026-11/c.ofx'));
        $this->assertNull($this->ler('lixo/intruso.txt'));
        $this->assertTrue(Cliente::where('razao_social', 'Cliente Novo Depois da Base')->exists());
        $this->assertTrue(User::where('email', 'admin@augi.local')->exists());

        // Restaura o ponto da BASE (estado anterior)
        $b->restaurar($base->nome);
        $this->assertSame('<a/>', $this->ler('nfse/1/recebida/a.xml'));
        $this->assertSame('versao-1', $this->ler('contabil/1/2026-10/b.pdf'));
        $this->assertNull($this->ler('contabil/1/2026-11/c.ofx'));
        $this->assertFalse(Cliente::where('razao_social', 'Cliente Novo Depois da Base')->exists());
    }

    public function test_extrair_para_conferencia_nao_altera_o_sistema(): void
    {
        $this->arquivo('x/y.txt', 'conteudo');
        $b = app(Backups::class);
        $b->executar();
        $antes = Cliente::count();
        $pasta = $this->storage.'/conferencia';
        $b->restaurar(null, $pasta);
        $this->assertSame('conteudo', file_get_contents($pasta.'/arquivos/x/y.txt'));
        $this->assertFileExists($pasta.'/banco/esquema.json');
        $this->assertSame($antes, Cliente::count());
    }

    public function test_senha_errada_e_pacote_adulterado_sao_detectados(): void
    {
        $b = app(Backups::class);
        $bk = $b->executar();
        $arq = $this->storage.'/app/backup-destino-local/augi-backups/'.$bk->nome;

        // Adulteração: um byte no meio do pacote
        $orig = file_get_contents($arq);
        $mod = $orig;
        $mod[intdiv(strlen($mod), 2)] = chr(ord($mod[intdiv(strlen($mod), 2)]) ^ 1);
        file_put_contents($arq, $mod);
        try {
            $b->restaurar(null, $this->storage.'/x');
            $this->fail('pacote adulterado não foi detectado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('adulterado', $e->getMessage());
        }
        try {
            $b->verificar();
            $this->fail('hash diferente não foi detectado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('hash diferente', $e->getMessage());
        }

        // Senha errada
        file_put_contents($arq, $orig);
        app(ConfigBackup::class)->gravar('senha', 'OUTRA-SENHA-QUALQUER');
        $this->expectExceptionMessage('senha de criptografia incorreta');
        $b->restaurar(null, $this->storage.'/x');
    }

    public function test_nova_senha_mantem_backups_antigos_legiveis_e_forca_base(): void
    {
        $b = app(Backups::class);
        $b->executar();
        $admin = User::where('email', 'admin@augi.local')->sole();
        $r = app(ConfigBackup::class)->salvar(['nova_senha' => true], $admin);
        $this->assertNotNull($r['senha_gerada']);
        $this->assertTrue($r['base_forcada']);
        $this->assertSame('ok', $b->verificar()['ok'] ? 'ok' : 'falhou');
        $this->travel(2)->seconds();
        $this->assertSame('base', $b->executar()->tipo);
    }

    public function test_retencao_remove_cadeias_antigas(): void
    {
        app(ConfigBackup::class)->gravar('bases_mantidas', '1');
        $b = app(Backups::class);
        $primeira = $b->executar('manual', true);
        $this->travel(2)->seconds();
        $inc = $b->executar();
        $this->travel(2)->seconds();
        $b->executar('manual', true);
        $this->assertSame('removido', $primeira->fresh()->status);
        $this->assertSame('removido', $inc->fresh()->status);
        $this->assertFileDoesNotExist($this->storage.'/app/backup-destino-local/augi-backups/'.$primeira->nome);
        $this->assertCount(1, $b->remotos());
    }

    public function test_falha_fica_registrada_sem_expor_segredos(): void
    {
        // Destino inacessível: um arquivo no lugar da pasta
        File::ensureDirectoryExists($this->storage.'/app');
        file_put_contents($this->storage.'/app/backup-destino-local', 'não é pasta');
        $bk = app(Backups::class)->executar();
        $this->assertSame('falhou', $bk->status);
        $this->assertNotEmpty($bk->erro);
        $this->assertStringNotContainsString('SENHA-DE-TESTE-1234', $bk->erro);
        $this->assertDatabaseHas('eventos', ['acao' => 'backup.falhou']);
    }

    public function test_agenda_executa_no_horario_ou_quando_solicitado(): void
    {
        $cfg = app(ConfigBackup::class);
        $cfg->gravar('horarios', '06:00,13:00,20:00');
        $this->travelTo(now()->setTime(10, 0));
        $this->artisan('backup:agenda')->assertSuccessful();
        $this->assertSame(0, Backup::count(), 'fora do horário não roda');

        $this->travelTo(now()->setTime(13, 0));
        $this->artisan('backup:agenda')->assertSuccessful();
        $this->assertSame(1, Backup::count());

        $this->travelTo(now()->setTime(13, 5));
        $cfg->gravar('solicitado', 'base');
        $this->artisan('backup:agenda')->assertSuccessful();
        $this->assertSame(2, Backup::where('tipo', 'base')->count());
        $this->assertNull($cfg->get('solicitado'));
    }

    public function test_tela_de_configuracao(): void
    {
        $admin = User::where('email', 'admin@augi.local')->sole();
        $this->actingAs(User::where('email', 'financeiro@augi.local')->sole())->get(route('plataforma.backups'))->assertForbidden();

        $this->actingAs($admin)->put(route('plataforma.backups.salvar'), [
            'destino' => 'gcs', 'bucket' => 'augi-bk', 'prefixo' => 'producao', 'chave' => 'GOOG1EABCDEFGHIJ', 'segredo' => 'segredo-super-secreto-123',
            'horarios' => '06:00, 13:00, 20:00', 'dias_base' => 7, 'bases_mantidas' => 8,
        ])->assertSessionHasNoErrors();
        $pagina = $this->actingAs($admin)->get(route('plataforma.backups'))->assertOk();
        $pagina->assertDontSee('segredo-super-secreto-123')->assertDontSee('GOOG1EABCDEFGHIJ')->assertSee('Google Cloud Storage');
        $this->assertNotSame('segredo-super-secreto-123', Configuracao::where('chave', 'backup.segredo')->value('valor'), 'segredo criptografado no banco');

        // Segredo em branco mantém o atual
        $this->actingAs($admin)->put(route('plataforma.backups.salvar'), [
            'destino' => 'gcs', 'bucket' => 'augi-bk', 'horarios' => '06:00', 'dias_base' => 7, 'bases_mantidas' => 8, 'segredo' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('segredo-super-secreto-123', app(ConfigBackup::class)->get('segredo'));

        // Horário inválido
        $this->actingAs($admin)->put(route('plataforma.backups.salvar'), ['destino' => 'gcs', 'bucket' => 'x', 'horarios' => '25:00', 'dias_base' => 7, 'bases_mantidas' => 8])
            ->assertSessionHasErrors('horarios');

        // Folha de recuperação exige a senha da conta
        $this->actingAs($admin)->post(route('plataforma.backups.folha'), ['senha_conta' => 'errada'])->assertSessionHasErrors('senha_conta');
        $folha = $this->actingAs($admin)->post(route('plataforma.backups.folha'), ['senha_conta' => 'piloto-2026'])->assertOk();
        $this->assertStringContainsString('BACKUP_SEGREDO=segredo-super-secreto-123', $folha->getContent());
        $this->assertStringContainsString('backup:restaurar', $folha->getContent());
    }

    public function test_testar_conexao_e_executar_agora(): void
    {
        $admin = User::where('email', 'admin@augi.local')->sole();
        $this->actingAs($admin)->post(route('plataforma.backups.testar'))->assertSessionHas('ok');
        $this->actingAs($admin)->post(route('plataforma.backups.executar'), ['base' => 1])->assertSessionHas('ok');
        $this->assertSame('base', app(ConfigBackup::class)->get('solicitado'));
    }

    public function test_configuracao_dos_destinos_s3_e_google(): void
    {
        $cfg = app(ConfigBackup::class);
        $base = ['chave' => 'K', 'segredo' => 'S', 'bucket' => 'b', 'regiao' => null, 'endpoint' => null];
        $gcs = $cfg->configDisco(['destino' => 'gcs'] + $base);
        $this->assertSame('https://storage.googleapis.com', $gcs['endpoint']);
        $this->assertTrue($gcs['use_path_style_endpoint']);
        $this->assertSame('when_required', $gcs['request_checksum_calculation']);
        $this->assertSame('us-east-1', $cfg->configDisco(['destino' => 's3'] + $base)['region']);
        $r2 = $cfg->configDisco(['destino' => 'compativel', 'endpoint' => 'https://conta.r2.cloudflarestorage.com'] + array_diff_key($base, ['endpoint' => 1]));
        $this->assertSame('https://conta.r2.cloudflarestorage.com', $r2['endpoint']);
        // O disco S3 é construído sem erro (sem acessar a rede)
        $this->assertInstanceOf(Filesystem::class, Storage::build($gcs));
    }
}
