<?php

namespace App\Http\Controllers;

use App\Enums\TipoOrganizacao;
use App\Models\AutorizacaoEscritorio;
use App\Models\Evento;
use App\Models\Organizacao;
use App\Support\Contexto;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Dados da empresa do contexto e autorizações concedidas a escritórios. */
class OrganizacaoController extends Controller
{
    public function show(Contexto $ctx): View
    {
        $empresa = $ctx->empresa();
        $autorizacoes = $empresa->autorizacoesConcedidas()->with('escritorio', 'concedidaPor')->latest('inicio')->get();

        return view('organizacao.show', compact('empresa', 'autorizacoes'));
    }

    public function update(Request $request, Contexto $ctx): RedirectResponse
    {
        $d = $request->validate([
            'razao_social' => ['required', 'string', 'max:255'],
            'inscricao_municipal' => ['nullable', 'string', 'max:30'],
            'municipio_ibge' => ['nullable', 'digits:7'],
        ]);
        $ctx->empresa()->update($d);

        return back()->with('ok', 'Dados da empresa atualizados.');
    }

    public function autorizarEscritorio(Request $request, Contexto $ctx): RedirectResponse
    {
        $cnpj = Documento::limpar($request->input('cnpj'));
        $escritorio = Organizacao::where('cnpj', $cnpj)->where('tipo', TipoOrganizacao::Escritorio)->first();
        if (! $escritorio) {
            throw ValidationException::withMessages(['cnpj' => 'Nenhum escritório contábil com este CNPJ está cadastrado na plataforma.']);
        }
        $empresa = $ctx->empresa();
        $jaTem = AutorizacaoEscritorio::vigentes()->where('empresa_id', $empresa->id)->where('escritorio_id', $escritorio->id)->exists();
        if ($jaTem) {
            throw ValidationException::withMessages(['cnpj' => 'Este escritório já está autorizado.']);
        }
        $a = AutorizacaoEscritorio::create([
            'empresa_id' => $empresa->id, 'escritorio_id' => $escritorio->id,
            'inicio' => now()->toDateString(), 'concedida_por' => $request->user()->id,
        ]);
        Evento::registrar('autorizacao.concedida', $a, ['escritorio' => $escritorio->razao_social]);

        return back()->with('ok', "{$escritorio->razao_social} agora pode acessar os dados desta empresa.");
    }

    public function revogarEscritorio(Request $request, Contexto $ctx, AutorizacaoEscritorio $autorizacao): RedirectResponse
    {
        abort_unless($autorizacao->empresa_id === $ctx->organizacaoId(), 404);
        abort_unless($autorizacao->vigente(), 422, 'Autorização já encerrada.');
        $autorizacao->update(['fim' => now()->toDateString(), 'revogada_por' => $request->user()->id]);
        Evento::create([
            'organizacao_id' => $ctx->organizacaoId(), 'user_id' => $request->user()->id, 'ip' => $request->ip(),
            'acao' => 'autorizacao.revogada', 'alvo_type' => $autorizacao->getMorphClass(), 'alvo_id' => $autorizacao->id,
        ]);

        return back()->with('ok', 'Acesso do escritório revogado. O histórico foi preservado.');
    }
}
