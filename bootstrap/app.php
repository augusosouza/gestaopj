<?php

use App\Http\Middleware\AdminPlataforma;
use App\Http\Middleware\CabecalhosSeguranca;
use App\Http\Middleware\ExigeContexto;
use App\Http\Middleware\ExigePapel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(CabecalhosSeguranca::class);
        $middleware->alias([
            'papel' => ExigePapel::class,
            'contexto' => ExigeContexto::class,
            'plataforma' => AdminPlataforma::class,
        ]);
        // O contexto precisa estar ativo ANTES do route model binding: assim um ID
        // de outra empresa na URL já é resolvido com o escopo e resulta em 404.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ExigeContexto::class);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
