<?php

namespace App\Http\Middleware;

use App\Support\Contexto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ativa e revalida o contexto (vínculo + empresa) a cada requisição.
 *
 * Uso: 'contexto' (basta um vínculo válido) ou 'contexto:empresa' (exige empresa escolhida).
 */
class ExigeContexto
{
    public function __construct(private Contexto $ctx) {}

    public function handle(Request $request, Closure $next, ?string $exige = null): Response
    {
        $valido = $this->ctx->ativar($request->user(), $request->session()->get('ctx'));

        if (! $valido) {
            $tinha = $request->session()->has('ctx');
            $request->session()->forget('ctx');
            $this->ctx->desativar();

            return redirect()->route('contexto.escolher')
                ->with('aviso', $tinha ? 'Seu acesso a este contexto foi encerrado ou revogado. Escolha outro.' : null);
        }

        if ($exige === 'empresa' && $this->ctx->organizacaoId() === null) {
            $this->ctx->desativar();

            return redirect()->route('carteira');
        }

        try {
            return $next($request);
        } finally {
            // Nada de escopo herdado por código que rode depois da requisição.
            $this->ctx->desativar();
        }
    }
}
