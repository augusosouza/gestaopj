<?php

namespace App\Providers;

use App\Health\BackupRecenteCheck;
use App\Health\EspacoEmDiscoCheck;
use App\Listeners\AvisarFalhaDeSaude;
use App\Support\Contexto;
use App\Support\GerenciadorCorreio;
use App\Support\Plataforma;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Events\CheckEndedEvent;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Contexto::class);
        $this->app->extend('mail.manager', fn ($manager, $app) => new GerenciadorCorreio($app));
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));
        CarbonImmutable::setLocale(config('app.locale'));
        Date::use(CarbonImmutable::class);

        RateLimiter::for('busca-global', static fn (Request $r) => $r->isMethod('post')
            ? Limit::perMinute(30)->by('usuario:'.$r->user()->id)
            : Limit::none());
        RateLimiter::for('visoes-salvas', static fn (Request $r) => Limit::perMinute(20)->by('usuario:'.$r->user()->id));

        // Nome do sistema definido na área de configurações (prevalece sobre o .env).
        Plataforma::aplicar();

        // Saúde do sistema (Administração Augi → Saúde do sistema). Só infraestrutura: nenhum dado de empresa.
        $producao = $this->app->environment('production');
        Health::checks([
            DatabaseCheck::new()->label('Banco de dados'),
            CacheCheck::new()->label('Cache'),
            ScheduleCheck::new()->label('Agendador (cron)')->heartbeatMaxAgeInMinutes(5),
            EspacoEmDiscoCheck::new()->label('Espaço em disco'),
            BackupRecenteCheck::new()->label('Backup recente'),
            DebugModeCheck::new()->label('Modo de depuração desligado')->if($producao),
            OptimizedAppCheck::new()->label('Configuração otimizada')->if($producao),
        ]);
        Event::listen(CheckEndedEvent::class, AvisarFalhaDeSaude::class);
    }
}
