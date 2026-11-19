<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\Atribuicao;
use App\Models\AutorizacaoEscritorio;
use App\Models\Evento;
use App\Models\Organizacao;
use App\Models\User;
use App\Models\Vinculo;
use App\Support\Contexto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Revisor distribui as empresas da carteira entre os analistas. */
class EscritorioController extends Controller
{
    public function atribuicoes(Contexto $ctx): View
    {
        $escritorio = $ctx->vinculo()->organizacao;
        $empresas = AutorizacaoEscritorio::vigentes()->with('empresa')->where('escritorio_id', $escritorio->id)
            ->get()->pluck('empresa')->sortBy('razao_social')->values();
        $atribuicoes = Atribuicao::vigentes()->with('user', 'empresa')->where('escritorio_id', $escritorio->id)->get()->groupBy('empresa_id');
        $analistas = User::whereIn('id', Vinculo::vigentes()->where('organizacao_id', $escritorio->id)
            ->where('papel', Papel::Analista)->pluck('user_id'))->orderBy('name')->get();

        return view('contexto.atribuicoes', compact('escritorio', 'empresas', 'atribuicoes', 'analistas'));
    }

    public function atribuir(Request $request, Contexto $ctx): RedirectResponse
    {
        $escritorio = $ctx->vinculo()->organizacao;
        $d = $request->validate(['empresa_id' => ['required', 'integer'], 'user_id' => ['required', 'integer']]);

        $autorizada = AutorizacaoEscritorio::vigentes()->where('escritorio_id', $escritorio->id)->where('empresa_id', $d['empresa_id'])->exists();
        $analista = Vinculo::vigentes()->where('organizacao_id', $escritorio->id)->where('user_id', $d['user_id'])->where('papel', Papel::Analista)->exists();
        if (! $autorizada || ! $analista) {
            throw ValidationException::withMessages(['empresa_id' => 'Empresa fora da carteira ou analista sem vínculo com o escritório.']);
        }
        if (Atribuicao::vigentes()->where('escritorio_id', $escritorio->id)->where('empresa_id', $d['empresa_id'])->where('user_id', $d['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Analista já atribuído a esta empresa.']);
        }
        $a = Atribuicao::create($d + ['escritorio_id' => $escritorio->id, 'inicio' => now()->toDateString(), 'criado_por' => $request->user()->id]);
        $this->registrar($request, $a, 'atribuicao.criada', (int) $d['empresa_id']);

        return back()->with('ok', 'Empresa atribuída.');
    }

    public function encerrar(Request $request, Contexto $ctx, Atribuicao $atribuicao): RedirectResponse
    {
        abort_unless($atribuicao->escritorio_id === $ctx->vinculo()->organizacao_id, 404);
        $atribuicao->update(['fim' => now()->toDateString(), 'encerrado_por' => $request->user()->id]);
        $this->registrar($request, $atribuicao, 'atribuicao.encerrada', $atribuicao->empresa_id);

        return back()->with('ok', 'Atribuição encerrada. O histórico foi preservado.');
    }

    private function registrar(Request $request, Atribuicao $a, string $acao, int $empresaId): void
    {
        Evento::create([
            'organizacao_id' => $empresaId, 'user_id' => $request->user()->id, 'ip' => $request->ip(),
            'acao' => $acao, 'alvo_type' => $a->getMorphClass(), 'alvo_id' => $a->id,
            'dados' => ['analista' => $a->user->email, 'escritorio' => Organizacao::find($a->escritorio_id)?->razao_social],
        ]);
    }
}
