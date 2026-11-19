<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Rules\DocumentoValido;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClienteController extends Controller
{
    public function index(): View
    {
        return view('cadastros.clientes', ['clientes' => Cliente::withCount('contratos')->orderBy('razao_social')->get(), 'editar' => null]);
    }

    public function edit(Cliente $cliente): View
    {
        return view('cadastros.clientes', ['clientes' => Cliente::withCount('contratos')->orderBy('razao_social')->get(), 'editar' => $cliente]);
    }

    public function store(Request $request): RedirectResponse
    {
        Cliente::create($this->dados($request));

        return redirect()->route('clientes.index')->with('ok', 'Cliente cadastrado.');
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $cliente->update($this->dados($request, $cliente));

        return redirect()->route('clientes.index')->with('ok', 'Cliente atualizado.');
    }

    private function dados(Request $request, ?Cliente $c = null): array
    {
        $request->merge(['documento' => Documento::limpar($request->input('documento'))]);

        return $request->validate([
            'razao_social' => ['required', 'string', 'max:255'],
            'documento' => ['required', new DocumentoValido, Rule::unique('clientes')->where('organizacao_id', ctx()->organizacaoId())->ignore($c)],
            'email' => ['nullable', 'email'],
            'municipio_ibge' => ['nullable', 'digits:7'],
        ]);
    }
}
