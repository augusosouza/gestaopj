<?php

namespace App\Http\Middleware;

use App\Support\Contexto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ativa e revalida o contexto (vínculo + empresa) a cada requisição.
 *
 * Uso: 'contexto' (basta um vínculo válido), 'contexto:empresa' (exige empresa escolhida)
 * ou 'contexto:opcional' (ativa se houver, nunca redireciona).
 */
class ExigeContexto
{
    public function __construct(private Contexto $ctx) {}

    public function handle(Request $request, Closure $next, ?string $exige = null): Response
    {
        $valido = $this->ctx->ativar($request->user(), $request->session()->get('ctx'));

        // 'opcional': usa o contexto se houver (para mostrar o menu), sem redirecionar nem exigir nada.
        // Usado em páginas que precisam abrir mesmo sem contexto (segurança da conta, administração Augi).
        if ($exige === 'opcional') {
            if (! $valido) {
                $this->ctx->desativar();
            }
            try {
                return $next($request);
            } finally {
                $this->ctx->desativar();
            }
        }

        if (! $valido) {
            $tinha = $request->session()->has('ctx');
            $request->session()->forget('ctx');
            $this->ctx->desativar();

            return redirect()->route('contexto.escolher')
                ->with('aviso', $tinha ? 'Seu acesso a este contexto foi encerrado ou revogado. Escolha outro.' : null);
        }

        // Perfis privilegiados só operam com verificação em duas etapas ativa.
        if ($this->ctx->faltaDoisFatores($request->user())) {
            $this->ctx->desativar();

            return redirect()->route('seguranca')
                ->with('aviso', 'Administradores e revisores precisam ativar a verificação em duas etapas antes de continuar.');
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
