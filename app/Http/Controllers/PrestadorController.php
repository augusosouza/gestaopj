<?php

namespace App\Http\Controllers;

use App\Enums\TipoPrestador;
use App\Models\Prestador;
use App\Rules\DocumentoValido;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PrestadorController extends Controller
{
    public function index(): View
    {
        return view('cadastros.prestadores', ['prestadores' => Prestador::withCount('alocacoes')->orderBy('nome')->get(), 'editar' => null]);
    }

    public function edit(Prestador $prestador): View
    {
        return view('cadastros.prestadores', ['prestadores' => Prestador::withCount('alocacoes')->orderBy('nome')->get(), 'editar' => $prestador]);
    }

    public function store(Request $request): RedirectResponse
    {
        Prestador::create($this->dados($request));

        return redirect()->route('prestadores.index')->with('ok', 'Prestador cadastrado.');
    }

    public function update(Request $request, Prestador $prestador): RedirectResponse
    {
        $prestador->update($this->dados($request, $prestador));

        return redirect()->route('prestadores.index')->with('ok', 'Prestador atualizado.');
    }

    private function dados(Request $request, ?Prestador $p = null): array
    {
        $request->merge(['documento' => Documento::limpar($request->input('documento'))]);
        $tipo = TipoPrestador::tryFrom((string) $request->input('tipo'));

        return $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoPrestador::class)],
            'documento' => ['required', new DocumentoValido($tipo === TipoPrestador::PF ? 'cpf' : 'cnpj'), Rule::unique('prestadores')->where('organizacao_id', ctx()->organizacaoId())->ignore($p)],
            'email' => ['nullable', 'email'],
            'chave_pix' => ['nullable', 'string', 'max:140'],
        ]);
    }
}
