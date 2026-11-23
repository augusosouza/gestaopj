<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LeValores;
use App\Models\ContaFinanceira;
use App\Models\Transferencia;
use App\Services\Financeiro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Transferências entre contas da empresa: mexem no saldo, não em receitas nem despesas. */
class TransferenciaController extends Controller
{
    use LeValores;

    public function __construct(private Financeiro $fin) {}

    public function index(): View
    {
        return view('financeiro.transferencias', [
            'transferencias' => Transferencia::with('origem', 'destino', 'estorno', 'estornoDe', 'criadoPor', 'linhasExtrato')->orderByDesc('data')->orderByDesc('id')->limit(300)->get(),
            'contas' => ContaFinanceira::where('ativa', true)->orderBy('nome')->get(), 'chave' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'conta_origem_id' => ['required', 'integer'], 'conta_destino_id' => ['required', 'integer', 'different:conta_origem_id'],
            'data' => ['required', 'date', 'before_or_equal:today'], 'valor' => ['required', 'string'], 'tarifa' => ['nullable', 'string'],
            'descricao' => ['nullable', 'string', 'max:255'], 'chave' => ['nullable', 'string', 'max:64'],
        ], ['conta_destino_id.different' => 'A conta de destino precisa ser diferente da origem.']);
        $this->fin->transferir(ctx()->organizacaoId(), (int) $d['conta_origem_id'], (int) $d['conta_destino_id'], $d['data'],
            $this->centavos($d['valor'], 'valor'), $d['descricao'] ?? null, $this->centavos($d['tarifa'] ?? '', 'tarifa', true), $d['chave'] ?? null);

        return back()->with('ok', 'Transferência registrada. Ela não conta como receita nem despesa.');
    }

    public function estornar(Request $request, Transferencia $transferencia): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:255']]);
        $this->fin->estornarTransferencia($transferencia, $d['motivo']);

        return back()->with('ok', 'Transferência estornada. O registro original foi mantido.');
    }
}
