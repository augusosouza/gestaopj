<?php

namespace App\Http\Middleware;

use App\Enums\Papel;
use App\Support\Contexto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('papel:admin,financeiro'). Verifica o papel do vínculo ativo. */
class ExigePapel
{
    public function __construct(private Contexto $ctx) {}

    public function handle(Request $request, Closure $next, string ...$papeis): Response
    {
        abort_unless(self::permite($this->ctx, ...$papeis), 403, 'Seu perfil não tem acesso a esta área.');

        return $next($request);
    }

    public static function permite(Contexto $contexto, string ...$papeis): bool
    {
        return $contexto->tem(...array_map(Papel::from(...), $papeis));
    }
}
