<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Enums\StatusFatura;
use App\Models\Evento;
use App\Models\User;
use App\Models\VisaoSalva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VisaoSalvaController extends Controller
{
    public function salvar(Request $request, string $modulo): RedirectResponse
    {
        $this->autorizar($modulo);
        $regras = match ($modulo) {
            'clientes' => [
                'filtros.busca' => ['nullable', 'string', 'max:120'],
                'filtros.tipo' => ['nullable', Rule::in(['pf', 'pj'])],
                'filtros.contratos' => ['nullable', Rule::in(['com', 'sem'])],
                'filtros.ordem' => ['nullable', Rule::in(['nome', 'nome_desc', 'contratos'])],
            ],
            'faturas' => [
                'filtros.q' => ['nullable', 'string', 'max:100'],
                'filtros.situacao' => ['nullable', Rule::enum(StatusFatura::class)],
                'filtros.competencia' => ['nullable', 'date_format:Y-m'],
            ],
        };
        $campos = array_map(static fn ($campo) => substr($campo, strlen('filtros.')), array_keys($regras));
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:60'],
            'filtros' => ['required', 'array:'.implode(',', $campos)],
            ...$regras,
        ]);
        $filtros = array_filter($dados['filtros'], static fn ($valor) => $valor !== null && $valor !== '');
        DB::transaction(function () use ($request, $modulo, $dados, $filtros) {
            User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $visoes = VisaoSalva::pessoais($modulo);
            if ($visoes->count() >= 20) {
                throw ValidationException::withMessages(['nome' => 'Exclua uma visão para salvar outra. O limite é de 20 por módulo.']);
            }
            if ($visoes->where('nome', $dados['nome'])->exists()) {
                throw ValidationException::withMessages(['nome' => 'Já existe uma visão com este nome.']);
            }
            VisaoSalva::create(['user_id' => $request->user()->id, 'modulo' => $modulo, 'nome' => $dados['nome'], 'filtros' => $filtros]);
        });

        return redirect()->route($modulo.'.index', $filtros)->with('ok', 'Visão salva.');
    }

    public function remover(string $modulo, int $visao): RedirectResponse
    {
        $this->autorizar($modulo);
        VisaoSalva::pessoais($modulo)->findOrFail($visao)->delete();

        return back()->with('ok', 'Visão removida.');
    }

    public function compartilhar(Request $request, string $modulo, int $visao): RedirectResponse
    {
        $this->autorizar($modulo);
        abort_unless(ctx()->tem(Papel::Admin, Papel::Financeiro), 403);
        $estado = $request->boolean('compartilhada');

        DB::transaction(function () use ($modulo, $visao, $estado) {
            $registro = VisaoSalva::pessoais($modulo)->lockForUpdate()->findOrFail($visao);
            if ($estado && (isset($registro->filtros['q']) || isset($registro->filtros['busca']))) {
                throw ValidationException::withMessages(['visao' => 'Salve uma visão sem texto de pesquisa para compartilhar com a equipe.']);
            }
            $registro->compartilhada = $estado;
            if ($registro->isDirty()) {
                $registro->save();
                Evento::registrar($estado ? 'visao.compartilhada' : 'visao.privada', $registro);
            }
        });

        return back()->with('ok', $estado ? 'Visão compartilhada com a equipe.' : 'Visão agora pessoal.');
    }

    private function autorizar(string $modulo): void
    {
        if ($modulo === 'clientes') {
            abort_unless(ctx()->tem(Papel::Admin), 403);
        }
    }
}
