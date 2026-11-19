<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\Evento;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Vinculo;
use App\Support\Contexto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(Contexto $ctx): View
    {
        $org = $this->organizacao($ctx);

        return view('cadastros.usuarios', [
            'org' => $org,
            'vinculos' => Vinculo::with('user', 'prestador', 'criadoPor')->where('organizacao_id', $org->id)
                ->orderByRaw('fim is not null')->orderBy('inicio')->get(),
            'papeis' => $org->ehEscritorio() ? Papel::doEscritorio() : Papel::daEmpresa(),
            'prestadores' => $org->ehEscritorio() ? collect() : Prestador::orderBy('nome')->get(),
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

        $user = User::firstWhere('email', Str::lower($d['email']));
        $novo = $user === null;
        $user ??= User::create(['name' => $d['name'], 'email' => Str::lower($d['email']), 'password' => Str::random(40)]);

        $duplicado = Vinculo::vigentes()->where('user_id', $user->id)->where('organizacao_id', $org->id)
            ->where('papel', $papel)->exists();
        if ($duplicado) {
            throw ValidationException::withMessages(['email' => 'Esta pessoa já tem este papel aqui.']);
        }

        $v = Vinculo::create([
            'user_id' => $user->id, 'organizacao_id' => $org->id, 'papel' => $papel,
            'prestador_id' => $papel === Papel::Prestador ? $d['prestador_id'] : null,
            'inicio' => now()->toDateString(), 'criado_por' => $request->user()->id,
        ]);
        Evento::create([
            'organizacao_id' => $org->id, 'user_id' => $request->user()->id, 'ip' => $request->ip(),
            'acao' => 'vinculo.criado', 'alvo_type' => $v->getMorphClass(), 'alvo_id' => $v->id,
            'dados' => ['email' => $user->email, 'papel' => $papel->value],
        ]);

        if ($novo) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return back()->with('ok', $novo
            ? "Convite enviado para {$user->email} com link para definir a senha."
            : "{$user->email} já tinha conta e recebeu o novo acesso.");
    }

    public function encerrar(Request $request, Contexto $ctx, Vinculo $vinculo): RedirectResponse
    {
        $org = $this->organizacao($ctx);
        abort_unless($vinculo->organizacao_id === $org->id, 404);
        abort_if($vinculo->id === $ctx->vinculo()->id, 422, 'Você não pode encerrar o próprio acesso em uso.');
        abort_unless($vinculo->vigente(), 422, 'Acesso já encerrado.');

        $vinculo->update(['fim' => now()->toDateString(), 'encerrado_por' => $request->user()->id]);
        Evento::create([
            'organizacao_id' => $org->id, 'user_id' => $request->user()->id, 'ip' => $request->ip(),
            'acao' => 'vinculo.encerrado', 'alvo_type' => $vinculo->getMorphClass(), 'alvo_id' => $vinculo->id,
        ]);

        return back()->with('ok', 'Acesso encerrado. O histórico foi preservado.');
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
