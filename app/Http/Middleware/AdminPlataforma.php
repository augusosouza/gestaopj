<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Área da plataforma: só administradores da plataforma, sempre com 2FA ativo. */
class AdminPlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->admin_plataforma, 403, 'Área restrita ao administrador da plataforma.');

        if (config('gestao.exigir_2fa') && ! $user->temDoisFatores()) {
            return redirect()->route('seguranca')
                ->with('aviso', 'Ative a verificação em duas etapas para acessar as configurações da plataforma.');
        }

        return $next($request);
    }
}
