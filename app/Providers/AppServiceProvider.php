<?php

namespace App\Providers;

use App\Support\Contexto;
use App\Support\Plataforma;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Contexto::class);
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));
        CarbonImmutable::setLocale(config('app.locale'));
        Date::use(CarbonImmutable::class);

        // Nome do sistema definido na área de configurações (prevalece sobre o .env).
        Plataforma::aplicar();
    }
}
