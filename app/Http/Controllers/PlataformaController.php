<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\Evento;
use App\Support\Plataforma;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlataformaController extends Controller
{
    public function configuracoes(): View
    {
        $alvo = (new Configuracao)->getMorphClass();

        return view('plataforma.configuracoes', [
            'campos' => Plataforma::CAMPOS,
            'valores' => ['nome_sistema' => config('app.name')] + array_filter(Plataforma::valores(), fn ($v) => $v !== null),
            'historico' => Evento::with('user')->where('acao', 'plataforma.configuracao')->where('alvo_type', $alvo)
                ->latest('id')->limit(10)->get(),
        ]);
    }

    public function salvar(Request $request): RedirectResponse
    {
        $regras = [];
        foreach (Plataforma::CAMPOS as $chave => [$rotulo, $max, $obrigatorio]) {
            $regras[$chave] = [$obrigatorio ? 'required' : 'nullable', 'string', 'max:'.$max];
        }
        $dados = $request->validate($regras, [], array_map(fn ($c) => mb_strtolower($c[0]), Plataforma::CAMPOS));

        $mudancas = Plataforma::salvar($dados, $request->user());

        return back()->with('ok', $mudancas ? 'Configurações salvas. Já valem para todos os usuários.' : 'Nada foi alterado.');
    }
}
