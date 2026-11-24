<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Evento;
use App\Models\Fatura;
use App\Rules\DocumentoValido;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClienteController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', Rule::in(['pf', 'pj'])],
            'contratos' => ['nullable', Rule::in(['com', 'sem'])],
        ]);
        $query = Cliente::query()->withCount('contratos');
        if ($busca = trim($filtros['busca'] ?? '')) {
            $documento = Documento::limpar($busca);
            $termo = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $busca).'%';
            $query->where(function ($q) use ($termo, $documento) {
                $q->whereRaw("razao_social LIKE ? ESCAPE '!'", [$termo])
                    ->orWhereRaw("nome_fantasia LIKE ? ESCAPE '!'", [$termo]);
                if ($documento !== '') {
                    $q->orWhere('documento', $documento);
                }
            });
        }
        if (isset($filtros['tipo'])) {
            $query->whereRaw('length(documento) = ?', [$filtros['tipo'] === 'pf' ? 11 : 14]);
        }
        match ($filtros['contratos'] ?? null) {
            'com' => $query->has('contratos'),
            'sem' => $query->doesntHave('contratos'),
            default => null,
        };
        $clientes = $query->orderBy('razao_social')->orderBy('id')->paginate(25)->appends($filtros);

        return view('cadastros.clientes', compact('clientes', 'filtros'));
    }

    public function create(): View
    {
        return view('cadastros.cliente-form', ['cliente' => null]);
    }

    public function show(Cliente $cliente): View
    {
        $contratos = $cliente->contratos()->orderByDesc('id')->paginate(15, ['*'], 'contratos');
        $faturas = Fatura::query()->whereHas('contrato', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->with('contrato:id,numero')->orderByDesc('competencia')->orderByDesc('id')
            ->paginate(15, ['*'], 'faturas')->withQueryString();
        $contratos->withQueryString();

        return view('cadastros.cliente-show', compact('cliente', 'contratos', 'faturas'));
    }

    public function edit(Cliente $cliente): View
    {
        return view('cadastros.cliente-form', compact('cliente'));
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->dados($request);
        $cliente = DB::transaction(function () use ($dados) {
            $cliente = Cliente::create($dados);
            Evento::registrar('cliente.criado', $cliente);

            return $cliente;
        });

        return redirect()->route('clientes.show', $cliente)->with('ok', 'Cliente cadastrado.');
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $dados = $this->dados($request, $cliente);
        DB::transaction(function () use ($cliente, $dados) {
            $cliente->fill($dados);
            if ($cliente->isDirty()) {
                $cliente->save();
                Evento::registrar('cliente.atualizado', $cliente, [
                    'campos' => array_keys(array_diff_key($cliente->getChanges(), ['updated_at' => true])),
                ]);
            }
        });

        return redirect()->route('clientes.show', $cliente)->with('ok', 'Cliente atualizado.');
    }

    private function dados(Request $request, ?Cliente $c = null): array
    {
        $request->merge(['documento' => Documento::limpar(is_string($request->input('documento')) ? $request->input('documento') : null)]);

        return $request->validate([
            'razao_social' => ['required', 'string', 'max:255'],
            'documento' => ['required', new DocumentoValido, Rule::unique('clientes')->where('organizacao_id', ctx()->organizacaoId())->ignore($c)],
            'email' => ['nullable', 'email', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:32'],
            'municipio_ibge' => ['nullable', 'digits:7'],
        ]);
    }
}
