<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Services\Assistente;
use App\Services\Sugestoes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Assistente por regras e motor de sugestões. Funciona sem nenhuma chave de IA. */
class InteligenciaController extends Controller
{
    public function assistente(Request $request, Assistente $a): View
    {
        $q = mb_substr(trim((string) $request->query('q')), 0, 300);

        return view('inteligencia.assistente', ['q' => $q, 'r' => $q !== '' ? $a->responder($q) : null, 'sugeridas' => Assistente::SUGERIDAS]);
    }

    public function sugestoes(Request $request, Sugestoes $s): View
    {
        $tipo = array_key_exists((string) $request->query('tipo'), Sugestoes::TIPOS) ? $request->query('tipo') : null;
        $todas = $s->gerar();

        return view('inteligencia.sugestoes', ['sugestoes' => $todas->when($tipo, fn ($c) => $c->where('tipo', $tipo))->values(),
            'contagem' => $todas->countBy('tipo'), 'tipo' => $tipo, 'podeDispensar' => ctx()->tem(Papel::Admin, Papel::Financeiro)]);
    }

    public function dispensar(Request $request, Sugestoes $s): RedirectResponse
    {
        $d = $request->validate(['chave' => ['required', 'string', 'max:160'], 'dias' => ['nullable', 'integer', 'in:3,7,30'],
            'motivo' => ['nullable', 'string', 'max:255']]);
        $s->dispensar($d['chave'], $d['dias'] ?? null, $d['motivo'] ?? null);

        return back()->with('ok', isset($d['dias']) ? "Sugestão adiada por {$d['dias']} dias." : 'Sugestão dispensada. Se a situação mudar, uma nova sugestão aparece.');
    }
}
