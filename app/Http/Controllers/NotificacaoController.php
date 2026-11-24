<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use App\Models\Vinculo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificacaoController extends Controller
{
    public function index(Request $request): View
    {
        $rotulos = ['nao_lidas' => 'Não lidas', 'todas' => 'Todas', 'acao' => 'Exigem ação',
            'financeiras' => 'Financeiras', 'contabeis' => 'Contábeis', 'sistema' => 'Sistema e segurança'];
        $dados = $request->validate([
            'filtro' => ['nullable', Rule::in(array_keys($rotulos))],
            'agrupar' => ['nullable', Rule::in(['empresa', 'periodo'])],
        ]);
        $filtro = $dados['filtro'] ?? 'nao_lidas';
        $agrupar = $dados['agrupar'] ?? null;
        $notificacoes = Notificacao::with('organizacao')->where('user_id', $request->user()->id)
            ->when(in_array($filtro, ['nao_lidas', 'acao'], true), fn ($q) => $q->whereNull('lida_em')->disponiveis())
            ->when($filtro === 'acao', fn ($q) => $q->whereNotNull('url')->where('url', '!=', ''))
            ->when(isset(Notificacao::CATEGORIAS[$filtro]), fn ($q) => $q->whereIn('tipo', Notificacao::CATEGORIAS[$filtro]))
            ->when($agrupar === 'empresa', fn ($q) => $q->orderBy('organizacao_id'))
            ->latest('created_at')->latest('id')->paginate(25)->appends($dados);

        return view('notificacoes.index', compact('filtro', 'agrupar', 'notificacoes', 'rotulos'));
    }

    public function adiar(Request $request, Notificacao $notificacao): RedirectResponse
    {
        abort_unless($notificacao->user_id === $request->user()->id, 404);
        abort_unless(in_array($notificacao->tipo, Notificacao::ADIAVEIS, true) && ! $notificacao->lida_em, 422);
        $notificacao->update(['adiada_ate' => now()->addDay()]);

        return back()->with('ok', 'Lembrete adiado por 24 horas.');
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
