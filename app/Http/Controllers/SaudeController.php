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

    /** Mensagens das verificações do pacote (em inglês) traduzidas para a tela. */
    public static function traduzir(?string $m): string
    {
        $m = (string) $m;
        $regras = [
            '/^The schedule did not run yet\.$/' => 'O agendador ainda não rodou. Confira o cron do servidor.',
            '/^The last run of the schedule was more than ([\d.]+) minutes ago\.$/' => 'O agendador não roda há mais de $1 minutos. Confira o cron do servidor (* * * * * php artisan schedule:run).',
            '/^Could not connect to the database: `(.*)`$/s' => 'Não foi possível conectar ao banco: $1',
            '/^Could not set or retrieve an application cache value\.$/' => 'O cache não grava ou não lê valores.',
            '/^An exception occurred with the application cache: `(.*)`$/s' => 'Erro no cache: $1',
            '/^The debug mode was expected to be .*$/' => 'O modo de depuração está ligado em produção (APP_DEBUG=true). Desligue no .env.',
            '/^Configs are not cached\.$/' => 'Configuração sem cache. Rode php artisan optimize no deploy.',
            '/^Routes are not cached\.$/' => 'Rotas sem cache. Rode php artisan optimize no deploy.',
            '/^The events are not cached\.$/' => 'Eventos sem cache. Rode php artisan optimize no deploy.',
        ];
        foreach ($regras as $de => $para) {
            if (preg_match($de, $m)) {
                return preg_replace($de, $para, $m);
            }
        }

        return $m;
    }

    /** Resumo curto: o do pacote vem em inglês ("Ok", "Failed", "Skipped"); os nossos já vêm em português. */
    public static function resumo(string $s): string
    {
        return ['Ok' => '—', 'Failed' => '—', 'Skipped' => '—', 'Warning' => '—', 'Crashed' => '—'][$s] ?? $s;
    }

    public static function rotulo(string $nome): string
    {
        foreach (self::ROTINAS as $chave => $rotulo) {
            if ($nome === $chave || str_starts_with($nome, $chave.' ')) {
                return $rotulo;
            }
        }

        return $nome;
    }

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
