<?php

namespace App\Http\Controllers;

use App\Services\Faturamento;
use App\Services\Fechamento;
use App\Support\Competencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompetenciaController extends Controller
{
    public function gerar(Request $request, Faturamento $faturamento): RedirectResponse
    {
        $dados = $request->validate(['competencia' => ['required', 'date_format:Y-m']]);
        $c = Competencia::de($dados['competencia']);
        $n = $faturamento->gerarCompetencia($c);

        return redirect()->route('painel', ['competencia' => $c->format('Y-m')])
            ->with('ok', "Competência {$c->format('m/Y')}: {$n['faturas']} fatura(s) e {$n['cobrancas']} cobrança(s) criadas.");
    }

    public function exportar(Request $request, Fechamento $fechamento): BinaryFileResponse
    {
        $dados = $request->validate(['competencia' => ['required', 'date_format:Y-m']]);
        $c = Competencia::de($dados['competencia']);

        return response()->download($fechamento->exportar($c), "fechamento-{$c->format('Y-m')}.zip")->deleteFileAfterSend();
    }
}
