<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\User;
use App\Support\DoisFatores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DoisFatoresController extends Controller
{
    public function __construct(private DoisFatores $tf) {}

    // ---------- Desafio no login ----------

    public function desafio(Request $request): View|RedirectResponse
    {
        return $this->pendente($request) ? view('auth.dois-fatores') : redirect()->route('login');
    }

    public function verificar(Request $request): RedirectResponse
    {
        $user = $this->pendente($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'A verificação expirou. Entre novamente.']);
        }
        $request->validate(['codigo' => ['required', 'string', 'max:20']]);

        $chave = '2fa|'.$user->id;
        if (RateLimiter::tooManyAttempts($chave, 5)) {
            throw ValidationException::withMessages(['codigo' => 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($chave).' segundos.']);
        }

        $codigo = (string) $request->input('codigo');
        $recuperacao = str_contains($codigo, '-');
        $ok = $recuperacao ? $this->tf->usarRecuperacao($user, $codigo) : $this->tf->verificar($user, $codigo);
        if (! $ok) {
            RateLimiter::hit($chave, 60);
            throw ValidationException::withMessages(['codigo' => 'Código inválido ou já utilizado.']);
        }
        RateLimiter::clear($chave);
        $lembrar = (bool) $request->session()->pull('2fa.pendente.lembrar');
        $request->session()->forget('2fa.pendente');

        return LoginController::concluir($request, $user, $lembrar, $recuperacao ? '2fa-recuperacao' : '2fa');
    }

    private function pendente(Request $request): ?User
    {
        $p = $request->session()->get('2fa.pendente');
        if (! $p || $p['expira'] < now()->timestamp) {
            return null;
        }

        return User::find($p['id']);
    }

    // ---------- Configuração pela pessoa ----------

    public function seguranca(Request $request): View
    {
        $user = $request->user();
        $segredo = $request->session()->get('2fa.novo_segredo');

        return view('auth.seguranca', [
            'user' => $user,
            'segredo' => $segredo,
            'qr' => $segredo ? $this->tf->qrSvg($user, $segredo) : null,
            'codigos' => session('2fa.codigos'),
        ]);
    }

    public function iniciar(Request $request): RedirectResponse
    {
        $request->session()->put('2fa.novo_segredo', $this->tf->novoSegredo());

        return redirect()->route('seguranca');
    }

    public function confirmar(Request $request): RedirectResponse
    {
        $request->validate(['codigo' => ['required', 'string']]);
        $segredo = $request->session()->get('2fa.novo_segredo');
        if (! $segredo || ! $this->tf->codigoValido($segredo, $request->input('codigo'))) {
            throw ValidationException::withMessages(['codigo' => 'Código inválido. Confira o relógio do celular e tente o código atual.']);
        }
        $codigos = $this->tf->novosCodigosRecuperacao();
        $request->user()->forceFill([
            'two_factor_secret' => $segredo,
            'two_factor_recovery_codes' => $codigos,
            'two_factor_confirmed_at' => now(),
            'two_factor_ultimo_uso' => null,
        ])->save();
        $request->session()->forget('2fa.novo_segredo');
        $this->registrar($request, '2fa.ativado');

        return redirect()->route('seguranca')->with('2fa.codigos', $codigos)
            ->with('ok', 'Verificação em duas etapas ativada.');
    }

    public function novosCodigos(Request $request): RedirectResponse
    {
        $this->exigirSenha($request);
        $codigos = $this->tf->novosCodigosRecuperacao();
        $request->user()->forceFill(['two_factor_recovery_codes' => $codigos])->save();
        $this->registrar($request, '2fa.codigos_renovados');

        return redirect()->route('seguranca')->with('2fa.codigos', $codigos)->with('ok', 'Novos códigos gerados. Os anteriores deixaram de valer.');
    }

    public function desativar(Request $request): RedirectResponse
    {
        abort_if($request->user()->exigeDoisFatores(), 403, 'A verificação em duas etapas é obrigatória para seu perfil.');
        $this->exigirSenha($request);
        $request->user()->forceFill([
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null, 'two_factor_ultimo_uso' => null,
        ])->save();
        $this->registrar($request, '2fa.desativado');

        return redirect()->route('seguranca')->with('ok', 'Verificação em duas etapas desativada.');
    }

    private function exigirSenha(Request $request): void
    {
        $request->validate(['senha' => ['required', 'string']]);
        if (! Hash::check($request->input('senha'), $request->user()->password)) {
            throw ValidationException::withMessages(['senha' => 'Senha incorreta.']);
        }
    }

    private function registrar(Request $request, string $acao): void
    {
        Evento::create([
            'user_id' => $request->user()->id, 'ip' => $request->ip(), 'acao' => $acao,
            'alvo_type' => $request->user()->getMorphClass(), 'alvo_id' => $request->user()->id,
        ]);
    }
}
