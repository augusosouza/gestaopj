<?php

namespace App\Http\Controllers;

use App\Models\AutorizacaoEscritorio;
use App\Models\Evento;
use App\Models\Organizacao;
use App\Models\Vinculo;
use App\Support\Contexto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Escolha do contexto ativo e carteira do escritório. */
class ContextoController extends Controller
{
    public function escolher(Request $request): View|RedirectResponse
    {
        $vinculos = Vinculo::vigentes()->with('organizacao', 'prestador')
            ->where('user_id', $request->user()->id)->get();

        // Um único vínculo: entra direto.
        if ($vinculos->count() === 1 && ! session('aviso')) {
            return $this->ativar($request, $vinculos->first());
        }

        return view('contexto.escolher', compact('vinculos'));
    }

    public function selecionar(Request $request): RedirectResponse
    {
        $d = $request->validate(['vinculo' => ['required', 'integer']]);
        $vinculo = Vinculo::vigentes()->where('user_id', $request->user()->id)->find($d['vinculo']);
        abort_if($vinculo === null, 403, 'Vínculo inexistente, encerrado ou de outra pessoa.');

        return $this->ativar($request, $vinculo);
    }

    private function ativar(Request $request, Vinculo $vinculo): RedirectResponse
    {
        $request->session()->put('ctx', ['vinculo' => $vinculo->id, 'empresa' => null]);

        return $vinculo->papel->deEscritorio() ? redirect()->route('carteira') : redirect()->route('painel');
    }

    /** Carteira do escritório: empresas que autorizaram o escritório (e, para analistas, atribuídas). */
    public function carteira(Contexto $ctx): View|RedirectResponse
    {
        if (! $ctx->ehEscritorio()) {
            return redirect()->route('painel');
        }
        $vinculo = $ctx->vinculo();
        $empresas = AutorizacaoEscritorio::vigentes()->with('empresa')
            ->where('escritorio_id', $vinculo->organizacao_id)->get()
            ->pluck('empresa')
            ->filter(fn (Organizacao $e) => Contexto::escritorioAcessa($vinculo, $e->id))
            ->sortBy('razao_social')->values();

        return view('contexto.carteira', ['empresas' => $empresas, 'escritorio' => $vinculo->organizacao]);
    }

    public function entrar(Request $request, Contexto $ctx, Organizacao $empresa): RedirectResponse
    {
        abort_unless($ctx->ehEscritorio() && Contexto::escritorioAcessa($ctx->vinculo(), $empresa->id), 403,
            'Esta empresa não está na sua carteira autorizada.');

        $request->session()->put('ctx.empresa', $empresa->id);
        Evento::create([
            'organizacao_id' => $empresa->id, 'user_id' => $request->user()->id, 'ip' => $request->ip(),
            'acao' => 'escritorio.acesso', 'alvo_type' => $empresa->getMorphClass(), 'alvo_id' => $empresa->id,
            'dados' => ['escritorio' => $ctx->vinculo()->organizacao->razao_social],
        ]);

        return redirect()->route('painel');
    }

    public function sairDaEmpresa(Request $request): RedirectResponse
    {
        $request->session()->put('ctx.empresa', null);

        return redirect()->route('carteira');
    }
}
