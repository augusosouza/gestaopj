<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $chave = 'login:'.hash('sha256', Str::lower($dados['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($chave, 5)) {
            throw ValidationException::withMessages(['email' => 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($chave).' segundos.']);
        }
        // Valida a senha sem abrir a sessão: com 2FA, a sessão só nasce após o código.
        $provider = Auth::getProvider();
        $user = $provider->retrieveByCredentials(['email' => $dados['email']]);
        if (! $user || ! $provider->validateCredentials($user, ['password' => $dados['password']])) {
            RateLimiter::hit($chave);
            if ($user) {
                Evento::create(['user_id' => $user->id, 'ip' => $request->ip(), 'acao' => 'login.falha',
                    'alvo_type' => $user->getMorphClass(), 'alvo_id' => $user->id]);
            }
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        RateLimiter::clear($chave);

        if ($user->temDoisFatores()) {
            $request->session()->regenerate();
            $request->session()->put('2fa.pendente', [
                'id' => $user->id, 'lembrar' => $request->boolean('lembrar'), 'expira' => now()->addMinutes(5)->timestamp,
            ]);

            return redirect()->route('2fa.desafio');
        }

        return self::concluir($request, $user, $request->boolean('lembrar'), 'senha');
    }

    /** Abre a sessão e registra o login. */
    public static function concluir(Request $request, $user, bool $lembrar, string $metodo): RedirectResponse
    {
        Auth::login($user, $lembrar && ! $user->exigeDoisFatores());
        $request->session()->regenerate();
        Evento::create([
            'user_id' => $user->id, 'ip' => $request->ip(), 'acao' => 'login',
            'alvo_type' => $user->getMorphClass(), 'alvo_id' => $user->id, 'dados' => ['metodo' => $metodo],
        ]);

        return redirect()->route('contexto.escolher');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
