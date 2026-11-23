<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use App\Models\Vinculo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificacaoController extends Controller
{
    public function index(Request $request): View
    {
        $filtro = $request->query('filtro') === 'todas' ? 'todas' : 'nao_lidas';

        return view('notificacoes.index', [
            'filtro' => $filtro,
            'notificacoes' => Notificacao::with('organizacao')->where('user_id', $request->user()->id)
                ->when($filtro === 'nao_lidas', fn ($q) => $q->whereNull('lida_em'))->latest('id')->limit(100)->get(),
        ]);
    }

    /** Marca como lida e leva à ação. Se for de outra empresa, troca o contexto antes (só se a pessoa tiver acesso). */
    public function abrir(Request $request, Notificacao $notificacao): RedirectResponse
    {
        abort_unless($notificacao->user_id === $request->user()->id, 404);
        $notificacao->update(['lida_em' => $notificacao->lida_em ?? now()]);
        $ctx = $request->session()->get('ctx');
        $atual = isset($ctx['vinculo']) ? Vinculo::find($ctx['vinculo']) : null;
        if ($notificacao->organizacao_id && $atual?->organizacao_id !== $notificacao->organizacao_id) {
            $v = Vinculo::vigentes()->where('user_id', $request->user()->id)->where('organizacao_id', $notificacao->organizacao_id)->first();
            if (! $v) {
                return redirect()->route('notificacoes.index')->withErrors(['notificacao' => 'Você não tem mais acesso à empresa desta notificação.']);
            }
            $request->session()->put('ctx', ['vinculo' => $v->id, 'empresa' => null]);
        }

        return redirect($notificacao->url ?: route('notificacoes.index'));
    }

    public function marcarLidas(Request $request): RedirectResponse
    {
        Notificacao::where('user_id', $request->user()->id)->whereNull('lida_em')->update(['lida_em' => now()]);

        return back()->with('ok', 'Todas as notificações foram marcadas como lidas.');
    }
}
