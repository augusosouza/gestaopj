<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\Evento;
use App\Models\Organizacao;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Vinculo;
use App\Support\Contexto;
use App\Support\SimuladorAcesso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Acessos da organização do contexto: empresa (admin) ou escritório (revisor).
 * Convidar = criar vínculo; quem ainda não tem conta recebe link para definir a senha.
 */
class UsuarioController extends Controller
{
    public function index(Request $request, Contexto $ctx): View
    {
        $org = $this->organizacao($ctx);

        $filtros = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'in:vigentes,encerrados']]);
        $busca = trim($filtros['q'] ?? '');

        return view('cadastros.usuarios', [
            'org' => $org,
            'vinculos' => Vinculo::with('user', 'prestador', 'criadoPor')->where('organizacao_id', $org->id)
                ->when($busca !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where(fn ($u) => $u->whereLike('name', '%'.$busca.'%')->orWhereLike('email', '%'.$busca.'%'))))
                ->when(($filtros['status'] ?? '') === 'vigentes', fn ($q) => $q->vigentes())
                ->when(($filtros['status'] ?? '') === 'encerrados', fn ($q) => $q->whereDate('fim', '<=', now()->toDateString()))
                ->orderByRaw('fim is not null')->orderByDesc('id')->paginate(20)->withQueryString(),
            'busca' => $busca, 'status' => $filtros['status'] ?? '',
            'papeis' => $org->ehEscritorio() ? Papel::doEscritorio() : Papel::daEmpresa(),
            'prestadores' => $org->ehEscritorio() ? collect() : Prestador::orderBy('nome')->get(),
        ]);
    }

    public function simular(Request $request, Contexto $ctx, Vinculo $vinculo, SimuladorAcesso $simulador): View
    {
        $org = $this->organizacao($ctx);
        abort_unless($vinculo->organizacao_id === $org->id, 404);
        $dados = $request->validate(['recurso' => ['nullable', Rule::in(array_keys(SimuladorAcesso::RECURSOS))]]);
        $recurso = $dados['recurso'] ?? 'bancos';
        $resultados = $simulador->avaliar($vinculo->load('user'), $ctx->organizacaoId(), $recurso);

        return view('cadastros.simular-acesso', [
            'org' => $org, 'empresa' => $ctx->empresa(), 'vinculo' => $vinculo,
            'recursos' => SimuladorAcesso::RECURSOS, 'recurso' => $recurso, 'resultados' => $resultados,
        ]);
    }

    public function store(Request $request, Contexto $ctx): RedirectResponse
    {
        $org = $this->organizacao($ctx);
        $papeis = $org->ehEscritorio() ? Papel::doEscritorio() : Papel::daEmpresa();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'papel' => ['required', Rule::in(array_map(fn ($p) => $p->value, $papeis))],
            'prestador_id' => ['nullable', 'required_if:papel,prestador',
                Rule::exists('prestadores', 'id')->where('organizacao_id', $org->id)],
        ], ['prestador_id.required_if' => 'Selecione o prestador vinculado a este acesso.']);
        $papel = Papel::from($d['papel']);

        $user = DB::transaction(function () use ($org, $ctx, $request, $d, $papel) {
            $this->bloquearOrganizacao($org, $request, $ctx->vinculo()->id);
            $user = User::firstOrCreate(['email' => Str::lower($d['email'])],
                ['name' => $d['name'], 'password' => Str::random(40)]);
            if (Vinculo::vigentes()->where('user_id', $user->id)->where('organizacao_id', $org->id)->where('papel', $papel)->exists()) {
                throw ValidationException::withMessages(['email' => 'Esta pessoa já tem este papel aqui.']);
            }
            $v = Vinculo::create([
                'user_id' => $user->id, 'organizacao_id' => $org->id, 'papel' => $papel,
                'prestador_id' => $papel === Papel::Prestador ? $d['prestador_id'] : null,
                'inicio' => now()->toDateString(), 'criado_por' => $request->user()->id,
            ]);
            $this->auditar($v, $request, 'vinculo.criado', ['email' => $user->email, 'papel' => $papel->value]);

            return $user;
        });

        if ($user->wasRecentlyCreated) {
            try {
                $enviado = app('mail.manager')->getDefaultDriver() === 'log'
                    ? null : Password::sendResetLink(['email' => $user->email]);
            } catch (\Throwable) {
                $enviado = null;
            }

            return $enviado === Password::ResetLinkSent && app('mail.manager')->getDefaultDriver() !== 'array'
                ? back()->with('ok', "Convite enviado para {$user->email} com link para definir a senha.")
                : back()->with('erro', 'O acesso foi criado, mas o convite não foi confirmado. Após verificar o e-mail da plataforma, peça à pessoa para usar a recuperação de senha.');
        }

        return back()->with('ok', "{$user->email} já tinha conta e recebeu o novo acesso.");
    }

    public function atualizarPapel(Request $request, Contexto $ctx, Vinculo $vinculo): RedirectResponse
    {
        $org = $this->organizacao($ctx);
        abort_unless($vinculo->organizacao_id === $org->id, 404);
        $papeis = $org->ehEscritorio() ? Papel::doEscritorio() : Papel::daEmpresa();
        $dados = $request->validate([
            'papel' => ['required', Rule::in(array_column($papeis, 'value'))],
            'prestador_id' => ['nullable', 'required_if:papel,prestador',
                Rule::exists('prestadores', 'id')->where('organizacao_id', $org->id)],
            'current_password' => ['required', 'current_password'],
        ]);
        $papel = Papel::from($dados['papel']);
        $prestador = $papel === Papel::Prestador ? (int) $dados['prestador_id'] : null;

        $mudou = DB::transaction(function () use ($request, $ctx, $org, $vinculo, $papel, $prestador) {
            $v = $this->bloquearAcesso($org, $vinculo, $request, $ctx->vinculo()->id);
            if ($v->papel === $papel && $v->prestador_id === $prestador) {
                return false;
            }
            if (Vinculo::vigentes()->where('organizacao_id', $org->id)->where('user_id', $v->user_id)
                ->where('papel', $papel)->whereKeyNot($v->id)->exists()) {
                throw ValidationException::withMessages(['papel' => 'Esta pessoa já tem este papel nesta organização.']);
            }
            $this->encerrarAcesso($v, $request);
            $novo = Vinculo::create([
                'organizacao_id' => $org->id, 'user_id' => $v->user_id, 'papel' => $papel, 'prestador_id' => $prestador,
                'inicio' => now()->toDateString(), 'criado_por' => $request->user()->id,
            ]);
            $this->auditar($novo, $request, 'vinculo.papel_alterado', ['anterior' => $v->id, 'de' => $v->papel->value, 'para' => $papel->value]);

            return true;
        });

        return back()->with('ok', $mudou ? 'Papel atualizado. O vínculo anterior permanece no histórico.' : 'Este acesso já possui o papel selecionado.');
    }

    public function encerrar(Request $request, Contexto $ctx, Vinculo $vinculo): RedirectResponse
    {
        $org = $this->organizacao($ctx);
        DB::transaction(fn () => $this->encerrarAcesso($this->bloquearAcesso($org, $vinculo, $request, $ctx->vinculo()->id), $request));

        return back()->with('ok', 'Acesso encerrado. O histórico foi preservado.');
    }

    private function bloquearAcesso(Organizacao $org, Vinculo $vinculo, Request $request, int $vinculoAutor): Vinculo
    {
        abort_unless($vinculo->organizacao_id === $org->id, 404);
        $this->bloquearOrganizacao($org, $request, $vinculoAutor);
        $v = Vinculo::where('organizacao_id', $org->id)->whereKey($vinculo->id)->lockForUpdate()->firstOrFail();
        abort_if($v->user_id === $request->user()->id, 422, 'Você não pode alterar seus próprios acessos.');
        abort_unless($v->vigente(), 422, 'Acesso já encerrado.');

        return $v;
    }

    private function bloquearOrganizacao(Organizacao $org, Request $request, int $vinculoAutor): void
    {
        Organizacao::whereKey($org->id)->lockForUpdate()->firstOrFail();
        abort_unless(Vinculo::vigentes()->whereKey($vinculoAutor)->where('organizacao_id', $org->id)
            ->where('user_id', $request->user()->id)->whereIn('papel', [Papel::Admin->value, Papel::Revisor->value])->exists(), 403);
    }

    private function encerrarAcesso(Vinculo $v, Request $request): void
    {
        $v->update(['fim' => now()->toDateString(), 'encerrado_por' => $request->user()->id]);
        $this->auditar($v, $request, 'vinculo.encerrado');
    }

    private function auditar(Vinculo $v, Request $request, string $acao, array $dados = []): void
    {
        Evento::create([
            'organizacao_id' => $v->organizacao_id, 'user_id' => $request->user()->id, 'ip' => $request->ip(),
            'acao' => $acao, 'alvo_type' => $v->getMorphClass(), 'alvo_id' => $v->id, 'dados' => $dados ?: null,
        ]);
    }

    /** Administrador gere a empresa; revisor gere o escritório. */
    private function organizacao(Contexto $ctx)
    {
        if ($ctx->tem(Papel::Admin)) {
            return $ctx->empresa();
        }
        abort_unless($ctx->tem(Papel::Revisor), 403);

        return $ctx->vinculo()->organizacao;
    }
}
