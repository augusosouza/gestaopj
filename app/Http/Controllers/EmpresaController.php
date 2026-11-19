<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Rules\DocumentoValido;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmpresaController extends Controller
{
    public function index(): View
    {
        return view('cadastros.empresas', ['empresas' => Empresa::orderBy('razao_social')->get(), 'editar' => null]);
    }

    public function edit(Empresa $empresa): View
    {
        return view('cadastros.empresas', ['empresas' => Empresa::orderBy('razao_social')->get(), 'editar' => $empresa]);
    }

    public function store(Request $request): RedirectResponse
    {
        Empresa::create($this->dados($request));

        return redirect()->route('empresas.index')->with('ok', 'Empresa cadastrada.');
    }

    public function update(Request $request, Empresa $empresa): RedirectResponse
    {
        $empresa->update($this->dados($request, $empresa));

        return redirect()->route('empresas.index')->with('ok', 'Empresa atualizada.');
    }

    private function dados(Request $request, ?Empresa $e = null): array
    {
        $request->merge(['cnpj' => Documento::limpar($request->input('cnpj'))]);

        return $request->validate([
            'razao_social' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', new DocumentoValido('cnpj'), Rule::unique('empresas')->ignore($e)],
            'inscricao_municipal' => ['nullable', 'string', 'max:30'],
            'municipio_ibge' => ['nullable', 'digits:7'],
        ]);
    }
}
