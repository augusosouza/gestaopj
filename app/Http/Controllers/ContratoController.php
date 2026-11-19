<?php

namespace App\Http\Controllers;

use App\Models\Alocacao;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Prestador;
use App\Support\Dinheiro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContratoController extends Controller
{
    public function index(): View
    {
        $contratos = Contrato::with('cliente', 'empresa')->withSum('alocacoes as custo_centavos', 'valor_mensal_centavos')
            ->orderBy('status')->orderBy('numero')->get();

        return view('contratos.index', compact('contratos'));
    }

    public function create(): View
    {
        return view('contratos.form', ['contrato' => new Contrato(['dia_vencimento' => 10, 'status' => 'ativo']), 'clientes' => Cliente::orderBy('razao_social')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $c = Contrato::create($this->dados($request));

        return redirect()->route('contratos.show', $c)->with('ok', 'Contrato criado. Aloque os prestadores abaixo.');
    }

    public function show(Contrato $contrato): View
    {
        $contrato->load('cliente', 'empresa', 'alocacoes.prestador');
        $faturas = $contrato->faturas()->orderByDesc('competencia')->limit(12)->get();

        return view('contratos.show', ['contrato' => $contrato, 'faturas' => $faturas, 'prestadores' => Prestador::orderBy('nome')->get()]);
    }

    public function edit(Contrato $contrato): View
    {
        return view('contratos.form', ['contrato' => $contrato, 'clientes' => Cliente::orderBy('razao_social')->get()]);
    }

    public function update(Request $request, Contrato $contrato): RedirectResponse
    {
        $contrato->update($this->dados($request, $contrato));

        return redirect()->route('contratos.show', $contrato)->with('ok', 'Contrato atualizado. Faturas já geradas não são alteradas.');
    }

    public function alocar(Request $request, Contrato $contrato): RedirectResponse
    {
        $d = $request->validate([
            'prestador_id' => ['required', Rule::exists('prestadores', 'id')->where('organizacao_id', ctx()->organizacaoId())],
            'descricao' => ['nullable', 'string', 'max:255'],
            'valor_mensal' => ['required', 'string'],
            'inicio' => ['required', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
        ]);
        $contrato->alocacoes()->create([
            'prestador_id' => $d['prestador_id'],
            'descricao' => $d['descricao'] ?? null,
            'valor_mensal_centavos' => $this->centavos($d['valor_mensal'], 'valor_mensal'),
            'inicio' => $d['inicio'],
            'fim' => $d['fim'] ?? null,
        ]);

        return back()->with('ok', 'Prestador alocado.');
    }

    public function encerrarAlocacao(Request $request, Alocacao $alocacao): RedirectResponse
    {
        $d = $request->validate(['fim' => ['required', 'date', 'after_or_equal:'.$alocacao->inicio->toDateString()]]);
        $alocacao->update(['fim' => $d['fim']]);

        return back()->with('ok', 'Alocação encerrada.');
    }

    private function dados(Request $request, ?Contrato $c = null): array
    {
        $d = $request->validate([
            'cliente_id' => ['required', Rule::exists('clientes', 'id')->where('organizacao_id', ctx()->organizacaoId())],
            'numero' => ['required', 'string', 'max:40', Rule::unique('contratos')->where('organizacao_id', ctx()->organizacaoId())->ignore($c)],
            'descricao' => ['required', 'string', 'max:255'],
            'codigo_servico' => ['nullable', 'digits:6'],
            'valor_mensal' => ['required', 'string'],
            'dia_vencimento' => ['required', 'integer', 'between:1,31'],
            'inicio' => ['required', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'status' => ['required', Rule::in(['ativo', 'suspenso', 'encerrado'])],
        ]);
        $d['valor_mensal_centavos'] = $this->centavos($d['valor_mensal'], 'valor_mensal');
        unset($d['valor_mensal']);

        return $d;
    }

    private function centavos(string $v, string $campo): int
    {
        try {
            $c = Dinheiro::paraCentavos($v);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$campo => 'Valor inválido. Use, por exemplo, 12.000,00.']);
        }
        if ($c <= 0) {
            throw ValidationException::withMessages([$campo => 'O valor deve ser maior que zero.']);
        }

        return $c;
    }
}
