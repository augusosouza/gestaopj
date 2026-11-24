<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\Fatura;
use App\Services\EnvioFatura;
use App\Services\Faturamento;
use App\Services\Financeiro;
use App\Support\Competencia;
use App\Support\Dinheiro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $titulo = app(Financeiro::class)->tituloDeFatura($fatura)->load('parcelas.movimentos.estornoDe');

        $envios = Evento::where('organizacao_id', $fatura->organizacao_id)->whereMorphedTo('alvo', $fatura)
            ->whereIn('acao', array_keys(EnvioFatura::ROTULOS))->where('dados->cliente_id', $fatura->contrato->cliente_id)->latest('id')->limit(10)->get();

        return view('faturas.show', ['fatura' => $fatura, 'titulo' => $titulo,
            'contas' => ContaFinanceira::where('ativa', true)->orderBy('nome')->get(), 'chave' => (string) Str::uuid(), 'envios' => $envios]);
    }

    public function enviar(Request $request, Fatura $fatura, EnvioFatura $envio): RedirectResponse
    {
        $dados = $request->validate([
            'chave' => ['required', 'uuid'],
            'destinatario' => ['nullable', 'string', 'max:254'],
        ]);
        $resultado = $envio->enviar($fatura, $dados['chave'], $dados['destinatario'] ?? null);

        return match ($resultado) {
            null => back()->with('ok', 'O SMTP aceitou a fatura. A entrega na caixa de entrada ainda não foi confirmada.'),
            'duplicado' => back()->with('ok', 'Essa solicitação já foi registrada. Consulte o histórico antes de reenviar.'),
            default => back()->with('erro', EnvioFatura::MOTIVOS[$resultado]),
        };
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
            'conta_id' => ['nullable', 'integer'],
            'chave' => ['nullable', 'string', 'max:64'],
        ]);
        $faturamento->registrarRecebimento($fatura, $this->centavos($dados['valor']), $dados['data'],
            isset($dados['conta_id']) ? (int) $dados['conta_id'] : null, $dados['chave'] ?? null);

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
