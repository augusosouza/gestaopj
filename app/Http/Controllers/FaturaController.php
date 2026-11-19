<?php

namespace App\Http\Controllers;

use App\Models\Fatura;
use App\Services\Faturamento;
use App\Support\Competencia;
use App\Support\Dinheiro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FaturaController extends Controller
{
    public function index(Request $request): View
    {
        $competencia = Competencia::de($request->query('competencia'));
        $faturas = Fatura::with('contrato.cliente', 'notaFiscal')
            ->whereDate('competencia', $competencia->toDateString())
            ->orderBy('vencimento')->get();

        return view('faturas.index', compact('faturas', 'competencia'));
    }

    public function show(Fatura $fatura): View
    {
        $fatura->load('contrato.cliente', 'contrato.empresa', 'notaFiscal');

        return view('faturas.show', compact('fatura'));
    }

    public function vincularNota(Request $request, Fatura $fatura, Faturamento $faturamento): RedirectResponse
    {
        $request->validate(['xml' => ['required', 'file', 'max:'.config('gestao.xml_max_kb')]]);
        $faturamento->vincularNotaEmitida($fatura, $request->file('xml'));

        return back()->with('ok', 'NFS-e vinculada à fatura.');
    }

    public function receber(Request $request, Fatura $fatura, Faturamento $faturamento): RedirectResponse
    {
        $dados = $request->validate([
            'valor' => ['required', 'string'],
            'data' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $faturamento->registrarRecebimento($fatura, $this->centavos($dados['valor']), $dados['data']);

        return back()->with('ok', 'Recebimento registrado.');
    }

    private function centavos(string $v): int
    {
        try {
            return Dinheiro::paraCentavos($v);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['valor' => 'Valor inválido.']);
        }
    }
}
