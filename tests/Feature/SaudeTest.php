<?php

namespace Tests\Feature;

use App\Health\BackupRecenteCheck;
use App\Health\EspacoEmDiscoCheck;
use App\Http\Controllers\SaudeController;
use App\Models\Backup;
use App\Models\Notificacao;
use App\Models\User;
use App\Services\Backup\ConfigBackup;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Health\Enums\Status;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** Laravel Health + Schedule Monitor na Administração Augi. */
class SaudeTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_so_o_administrador_da_plataforma_ve_a_saude(): void
    {
        $this->como('admin@augi.local')->get(route('plataforma.saude'))->assertOk()->assertSee('Nenhuma verificação registrada');
        $this->como('financeiro@augi.local')->get(route('plataforma.saude'))->assertForbidden();
        $this->como('revisor@augi.local')->post(route('plataforma.saude.verificar'))->assertForbidden();
    }

    public function test_verificar_agora_grava_resultados_e_avisa_falha(): void
    {
        $this->como('admin@augi.local')->post(route('plataforma.saude.verificar'))->assertRedirect();
        $r = $this->como('admin@augi.local')->get(route('plataforma.saude'))->assertOk();
        $r->assertSee('Banco de dados')->assertSee('Agendador (cron)')->assertSee('Backup recente')->assertSee('Espaço em disco');
        $r->assertSee('Backup automático desativado.');
        $r->assertDontSee('Cliente Exemplo'); // só infraestrutura
        $r->assertSee('O agendador ainda não rodou. Confira o cron do servidor.')->assertDontSee('did not run');

        // O agendador nunca rodou neste teste: a verificação falha e vira notificação para o admin da plataforma (só para ele).
        $admin = User::where('email', 'admin@augi.local')->sole();
        $this->assertSame(1, Notificacao::where('user_id', $admin->id)->where('tipo', 'saude')->where('titulo', 'Saúde do sistema: Agendador (cron)')->count());
        $this->assertSame(0, Notificacao::where('tipo', 'saude')->where('user_id', '!=', $admin->id)->count());
        // Rodar de novo no mesmo dia não duplica o aviso.
        $this->como('admin@augi.local')->post(route('plataforma.saude.verificar'));
        $this->assertSame(1, Notificacao::where('tipo', 'saude')->where('titulo', 'Saúde do sistema: Agendador (cron)')->count());
    }

    public function test_espaco_em_disco_sem_processo_externo(): void
    {
        $this->assertSame(Status::failed()->value, EspacoEmDiscoCheck::new()->limites(0, 0)->run()->status->value);
        $this->assertSame(Status::warning()->value, EspacoEmDiscoCheck::new()->limites(0, 101)->run()->status->value);
        $ok = EspacoEmDiscoCheck::new()->limites(101, 101)->run();
        $this->assertSame(Status::ok()->value, $ok->status->value);
        $this->assertArrayHasKey('usado_pct', $ok->meta);
    }

    public function test_backup_recente(): void
    {
        $this->assertSame('Backup automático desativado.', BackupRecenteCheck::new()->run()->getNotificationMessage());
        $cfg = app(ConfigBackup::class);
        $cfg->gravar('destino', 'local');
        $cfg->gravar('senha', 'senha-de-teste-123');
        $this->assertTrue(app(ConfigBackup::class)->ativo());
        $this->assertSame('Nenhum backup concluído ainda.', BackupRecenteCheck::new()->run()->getNotificationMessage());

        $b = fn (string $status, string $quando) => Backup::create(['nome' => uniqid(), 'tipo' => 'incremental', 'status' => $status, 'destino' => 'local', 'acionado_por' => 'agenda',
            'iniciado_em' => $quando, 'concluido_em' => $quando]);
        $b('concluido', '2026-11-20 06:00');
        $this->assertSame(Status::ok()->value, BackupRecenteCheck::new()->run()->status->value);
        $b('falhou', '2026-11-20 08:00');
        $this->assertSame('Houve uma falha de backup depois do último concluído.', BackupRecenteCheck::new()->run()->getNotificationMessage());
        $this->travelTo(CarbonImmutable::parse('2026-11-20 19:00'));
        $this->assertSame('Último backup concluído há 13 horas (limite 12 h).', BackupRecenteCheck::new()->run()->getNotificationMessage());
    }

    public function test_rotinas_monitoradas_e_situacao(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:30'));
        $this->artisan('schedule-monitor:sync')->assertSuccessful();
        $nomes = MonitoredScheduledTask::pluck('name')->all();
        foreach (['backup:agenda', 'financeiro:lembretes', 'health:check', 'exportacoes:limpar'] as $n) {
            $this->assertContains($n, $nomes);
        }
        $t = MonitoredScheduledTask::where('name', 'financeiro:lembretes')->sole(); // de hora em hora
        $t->forceFill(['created_at' => '2026-11-19 00:00'])->save();
        $this->assertSame('nao_rodou', SaudeController::situacao($t->fresh()));
        $t->forceFill(['last_started_at' => '2026-11-20 10:00', 'last_finished_at' => '2026-11-20 10:00'])->save();
        $this->assertSame('ok', SaudeController::situacao($t->fresh()));
        $t->forceFill(['last_failed_at' => '2026-11-20 10:00:30'])->save();
        $this->assertSame('falhou', SaudeController::situacao($t->fresh()));
        $novo = MonitoredScheduledTask::where('name', 'backup:verificar')->sole(); // semanal, recém-cadastrada
        $this->assertSame('aguardando', SaudeController::situacao($novo));

        $this->como('admin@augi.local')->get(route('plataforma.saude'))->assertOk()
            ->assertSee('Lembretes de extrato e conciliação')->assertSee('Falhou na última execução')->assertSee('Limpeza do histórico das rotinas');
    }
}
