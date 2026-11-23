<?php

namespace App\Http\Controllers;

use App\Models\Prestador;
use App\Services\Fechamento;
use App\Services\Financeiro;
use App\Support\Competencia;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PainelController extends Controller
{
    public function __invoke(Request $request, Fechamento $fechamento): View
    {
        $competencia = Competencia::de($request->query('competencia'));
        if (ctx()->ehPrestador()) {
            $prestador = Prestador::find(ctx()->prestadorId());
            $cobrancas = $prestador
                ? $prestador->cobrancas()->with('contrato.empresa', 'notaFiscal')->orderByDesc('competencia')->limit(24)->get()
                : collect();

            return view('painel.prestador', compact('cobrancas', 'prestador'));
        }

        return view('painel.interno', [
            'competencia' => $competencia,
            'r' => $fechamento->resumo($competencia),
            'fin' => app(Financeiro::class)->painel($competencia),
        ]);
    }
}
