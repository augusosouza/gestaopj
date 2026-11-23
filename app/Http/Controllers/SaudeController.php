<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;

/** Administração Augi → Saúde do sistema: verificações (Laravel Health) e rotinas agendadas (Schedule Monitor). */
class SaudeController extends Controller
{
    /** Nomes das rotinas em português. */
    public const ROTINAS = [
        'backup:agenda' => 'Backups (base e incrementais)', 'backup:verificar' => 'Teste semanal de restauração do backup',
        'financeiro:lembretes' => 'Lembretes de extrato e conciliação', 'gestao:gerar-competencia' => 'Geração da competência mensal',
        'exportacoes:limpar' => 'Limpeza de exportações temporárias', 'health:check' => 'Verificações de saúde',
        'health:schedule-check-heartbeat' => 'Sinal de vida do agendador', 'model:prune' => 'Limpeza do histórico das rotinas',
    ];

    public function index(ResultStore $store): View
    {
        return view('plataforma.saude', [
            'resultados' => $store->latestResults(),
            'rotinas' => MonitoredScheduledTask::orderBy('name')->get()->map(fn ($t) => ['t' => $t, 'situacao' => self::situacao($t)]),
        ]);
    }

    /** ok | falhou | nao_rodou | aguardando (ainda não chegou a primeira execução) */
    public static function situacao(MonitoredScheduledTask $t): string
    {
        if ($t->last_failed_at && (! $t->last_finished_at || $t->last_failed_at->gt($t->last_finished_at))) {
            return 'falhou';
        }
        $anterior = (new CronExpression($t->cron_expression))->getPreviousRunDate(now(), 0, true, $t->timezone ?: config('app.timezone'));
        $limite = CarbonImmutable::instance($anterior)->addMinutes($t->grace_time_in_minutes);
        if ($limite->isFuture() && ! $t->last_started_at) {
            return 'aguardando';
        }
        if (! $t->last_started_at || ($limite->isPast() && $t->last_started_at->lt($anterior))) {
            return $t->created_at && $t->created_at->gt($anterior) ? 'aguardando' : 'nao_rodou';
        }

        return 'ok';
    }

    public function verificar(): RedirectResponse
    {
        Artisan::call('health:check');
        Evento::registrar('plataforma.saude_verificada', User::findOrFail(auth()->id()));

        return back()->with('ok', 'Verificações executadas.');
    }
}
