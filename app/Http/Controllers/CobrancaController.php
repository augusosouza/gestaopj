<?php

namespace App\Http\Controllers;

use App\Enums\StatusCobranca;
use App\Models\Cobranca;
use App\Models\Evento;
use App\Services\Faturamento;
use App\Support\Competencia;
use App\Support\Dinheiro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CobrancaController extends Controller
{
    public function index(Request $request): View
    {
        $competencia = Competencia::de($request->query('competencia'));
        $status = StatusCobranca::tryFrom((string) $request->query('status'));
        $cobrancas = Cobranca::with('prestador', 'contrato.cliente', 'notaFiscal')
            ->whereDate('competencia', $competencia->toDateString())
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->orderBy('status')->get();

        return view('cobrancas.index', compact('cobrancas', 'competencia', 'status'));
    }

    public function show(Request $request, Cobranca $cobranca): View
    {
        $this->autorizar($request, $cobranca);
        $cobranca->load('prestador', 'contrato.cliente', 'contrato.empresa', 'notaFiscal', 'aprovadaPor');
        $eventos = Evento::with('user')->where('alvo_type', $cobranca->getMorphClass())
            ->where('alvo_id', $cobranca->id)->latest('id')->get();

        return view('cobrancas.show', compact('cobranca', 'eventos'));
    }

    public function enviarNota(Request $request, Cobranca $cobranca, Faturamento $faturamento): RedirectResponse
    {
        $this->autorizar($request, $cobranca);
        $request->validate([
            'xml' => ['nullable', 'file', 'max:'.config('gestao.xml_max_kb'), 'required_without:chave_acesso'],
            'chave_acesso' => ['nullable', 'string', 'required_without:xml'],
        ], ['required_without' => 'Envie o XML da NFS-e ou informe a chave de acesso.']);

        $faturamento->enviarNotaPrestador($cobranca, $request->file('xml'), $request->input('chave_acesso'));

        return back()->with('ok', 'Nota enviada para conferência do financeiro.');
    }

    public function aprovar(Request $request, Cobranca $cobranca, Faturamento $faturamento): RedirectResponse
    {
        $dados = $request->validate(['justificativa' => ['nullable', 'string', 'max:1000']]);
        $faturamento->aprovar($cobranca, $dados['justificativa'] ?? null);

        return back()->with('ok', 'Cobrança aprovada para pagamento.');
    }

    public function rejeitar(Request $request, Cobranca $cobranca, Faturamento $faturamento): RedirectResponse
    {
        $dados = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);
        $faturamento->rejeitar($cobranca, $dados['motivo']);

        return back()->with('ok', 'Nota rejeitada. O prestador poderá reenviar.');
    }

    public function pagar(Request $request, Cobranca $cobranca, Faturamento $faturamento): RedirectResponse
    {
        $dados = $request->validate([
            'valor' => ['required', 'string'],
            'data' => ['required', 'date', 'before_or_equal:today'],
        ]);
        try {
            $valor = Dinheiro::paraCentavos($dados['valor']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['valor' => 'Valor inválido.']);
        }
        $faturamento->registrarPagamento($cobranca, $valor, $dados['data']);

        return back()->with('ok', 'Pagamento registrado.');
    }

    /** Prestador só acessa as próprias cobranças. */
    private function autorizar(Request $request, Cobranca $cobranca): void
    {
        $u = $request->user();
        abort_if($u->ehPrestador() && $u->prestador_id !== $cobranca->prestador_id, 404);
    }
}
