<?php

namespace App\Http\Controllers;

use App\Services\Fechamento;
use App\Support\Competencia;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PainelController extends Controller
{
    public function __invoke(Request $request, Fechamento $fechamento): View
    {
        $competencia = Competencia::de($request->query('competencia'));
        $user = $request->user();

        if ($user->ehPrestador()) {
            $cobrancas = $user->prestador
                ? $user->prestador->cobrancas()->with('contrato.empresa', 'notaFiscal')->orderByDesc('competencia')->limit(24)->get()
                : collect();

            return view('painel.prestador', compact('cobrancas'));
        }

        return view('painel.interno', [
            'competencia' => $competencia,
            'r' => $fechamento->resumo($competencia),
        ]);
    }
}
