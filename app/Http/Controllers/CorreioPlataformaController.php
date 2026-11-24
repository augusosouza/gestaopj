<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\User;
use App\Support\CorreioPlataforma;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

final class CorreioPlataformaController extends Controller
{
    public function index(CorreioPlataforma $correio): View
    {
        $dados = $correio->salvo();
        $temSenha = filled($dados['senha'] ?? null);
        unset($dados['senha']);

        $teste = Evento::whereIn('acao', ['plataforma.smtp_teste_ok', 'plataforma.smtp_teste_falhou'])->latest('id')->first();
        $ultimaAlteracao = Evento::where('acao', 'plataforma.smtp_configurado')->max('id');

        return view('plataforma.smtp', [
            'dados' => $dados, 'temSenha' => $temSenha, 'disponivel' => $correio->disponivel(),
            'host' => config('mail.painel_host'), 'transporte' => Mail::getDefaultDriver(),
            'teste' => $teste, 'testeDesatualizado' => $teste && $ultimaAlteracao > $teste->id,
        ]);
    }

    public function salvar(Request $request, CorreioPlataforma $correio): RedirectResponse
    {
        abort_unless($correio->disponivel(), 403);
        $dados = $request->validate([
            'ativo' => ['required', 'boolean'], 'porta' => ['required', 'in:465,587'],
            'remetente' => ['required', 'email', 'max:254'], 'nome' => ['nullable', 'string', 'max:100'],
            'usuario' => ['nullable', 'string', 'max:254'], 'smtp_password' => ['nullable', 'string', 'max:512'],
            'remover_senha' => ['nullable', 'boolean'], 'current_password' => ['required', 'current_password'],
        ]);
        $correio->salvar($dados + ['remover_senha' => false], $request->user());

        return back()->with('ok', 'Configurações de e-mail salvas.');
    }

    public function testar(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $transporte = Mail::getDefaultDriver();
        abort_unless(in_array($transporte, ['smtp', 'smtp-painel'], true), 422, 'Ative o SMTP antes de testar o envio.');
        $user = $request->user();
        try {
            Mail::raw('Esta mensagem confirma que o Augi Gestão conseguiu enviar e-mail pelo SMTP configurado.',
                fn (Message $m) => $m->to($user->email)->subject('Augi Gestão | Teste de e-mail'));
        } catch (\Throwable) {
            $this->registrarTeste($user, $request, 'plataforma.smtp_teste_falhou');

            return back()->with('erro', 'O SMTP recusou o envio ou não respondeu. Confira o servidor, a porta e as credenciais.');
        }
        $this->registrarTeste($user, $request, 'plataforma.smtp_teste_ok');

        return back()->with('ok', 'O SMTP aceitou a mensagem de teste para seu e-mail. Confira a caixa de entrada.');
    }

    private function registrarTeste(User $usuario, Request $request, string $acao): void
    {
        Evento::create(['user_id' => $usuario->id, 'ip' => $request->ip(), 'acao' => $acao,
            'alvo_type' => $usuario->getMorphClass(), 'alvo_id' => $usuario->id]);
    }
}
