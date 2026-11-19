<?php

namespace App\Http\Middleware;

use App\Enums\Papel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('papel:admin,financeiro') */
class ExigePapel
{
    public function handle(Request $request, Closure $next, string ...$papeis): Response
    {
        $user = $request->user();
        $permitidos = array_map(fn ($p) => Papel::from($p), $papeis);
        abort_unless($user && $user->tem(...$permitidos), 403, 'Seu perfil não tem acesso a esta área.');

        return $next($request);
    }
}
