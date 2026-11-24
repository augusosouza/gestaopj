<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RegraSenha;
use Illuminate\View\View;

/** Recuperação de acesso e definição da senha de convidados. */
class SenhaController extends Controller
{
    public function pedir(): View
    {
        return view('auth.esqueci');
    }

    public function enviar(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Mesma resposta exista ou não a conta: não revela quem está cadastrado.
        return back()->with('ok', 'Se o e-mail estiver cadastrado, você receberá um link para definir a senha.');
    }

    public function formulario(Request $request, string $token): View
    {
        return view('auth.redefinir', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function redefinir(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', RegraSenha::min(10)],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function ($user, $senha) use ($request) {
            $user->forceFill(['password' => $senha, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->id)->delete();
            }
            Evento::create(['user_id' => $user->id, 'ip' => $request->ip(), 'acao' => 'senha.redefinida',
                'alvo_type' => $user->getMorphClass(), 'alvo_id' => $user->id]);
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('ok', 'Senha definida. Entre com o novo acesso.')
            : back()->withErrors(['email' => 'Link inválido ou expirado. Peça um novo.']);
    }
}
