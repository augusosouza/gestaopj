<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ClienteRelacionamento;
use App\Models\Evento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClienteRelacionamentoController extends Controller
{
    public function form(Cliente $cliente, string $tipo, ?int $item = null): View
    {
        $registro = $item === null ? null : $cliente->relacionamentos()->where('tipo', $tipo)->findOrFail($item);

        Evento::registrar('cliente.relacionamento_consultado', $cliente, ['tipo' => $tipo, 'registro' => $registro?->id]);

        return view('cadastros.cliente-relacionamento', compact('cliente', 'tipo', 'registro'));
    }

    public function salvar(Request $request, Cliente $cliente, string $tipo, ?int $item = null): RedirectResponse
    {
        $dados = $request->validate([
            'finalidade' => ['required', Rule::in(array_keys(ClienteRelacionamento::FINALIDADES[$tipo]))],
            'ativo' => ['required', 'boolean'],
            'dados' => ['required', 'array:'.implode(',', array_keys(ClienteRelacionamento::CAMPOS[$tipo]))],
            ...($tipo === 'contato' ? [
                'dados.nome' => 'required|string|max:160',
                'dados.funcao' => 'nullable|string|max:120',
                'dados.email' => 'nullable|email|max:254',
                'dados.telefone' => 'nullable|string|max:32',
                'dados.whatsapp' => 'nullable|string|max:32',
                'dados.departamento' => 'nullable|string|max:120',
            ] : [
                'dados.cep' => 'nullable|regex:/^\d{5}-?\d{3}$/D',
                'dados.logradouro' => 'required|string|max:160',
                'dados.numero' => 'nullable|string|max:25',
                'dados.complemento' => 'nullable|string|max:120',
                'dados.bairro' => 'nullable|string|max:120',
                'dados.municipio' => 'required|string|max:120',
                'dados.uf' => ['nullable', Rule::in(explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'))],
                'dados.ibge' => 'nullable|digits:7',
                'dados.pais' => 'required|string|max:80',
            ]),
        ]);

        DB::transaction(function () use ($cliente, $tipo, $item, $dados) {
            $relacao = $cliente->relacionamentos()->where('tipo', $tipo);
            $registro = $item === null ? $relacao->make(['tipo' => $tipo]) : $relacao->lockForUpdate()->findOrFail($item);
            $anteriores = $registro->dados ?? [];
            $registro->fill($dados);
            $campos = array_keys(array_filter($dados['dados'], fn ($valor, $chave) => ($anteriores[$chave] ?? null) !== $valor, ARRAY_FILTER_USE_BOTH));
            $campos = array_merge($campos, array_keys(array_intersect_key($registro->getDirty(), array_flip(['ativo', 'finalidade']))));
            $registro->save();
            Evento::registrar($item === null ? 'cliente.relacionamento_criado' : 'cliente.relacionamento_atualizado',
                $cliente, ['tipo' => $tipo, 'registro' => $registro->id, 'campos' => $campos]);
        });

        return redirect()->route('clientes.show', $cliente)->with('ok', 'Dados salvos.');
    }
}
