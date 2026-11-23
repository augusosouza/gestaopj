<?php

namespace App\Http\Controllers;

use App\Models\Anexo;
use App\Models\Compra;
use App\Models\Evento;
use App\Models\Titulo;
use App\Services\Anexos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnexoController extends Controller
{
    public function store(Request $request, Anexos $anexos, string $tipo, int $id): RedirectResponse
    {
        // Busca com o escopo da empresa: ID de outra empresa = 404.
        $alvo = match ($tipo) {
            'titulo' => Titulo::findOrFail($id),
            'compra' => Compra::findOrFail($id),
            default => abort(404),
        };
        $d = $request->validate(['arquivo' => ['required', 'file', 'max:10240'], 'descricao' => ['nullable', 'string', 'max:120']],
            ['arquivo.max' => 'Arquivo acima de 10 MB.']);
        $a = $anexos->anexar($alvo, $d['arquivo'], $d['descricao'] ?? null);

        return back()->with('ok', "Anexo \"{$a->nome_original}\" salvo.");
    }

    public function baixar(Anexo $anexo): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($anexo->caminho), 404);
        Evento::registrar('financeiro.anexo_baixado', $anexo->anexavel, ['arquivo' => $anexo->nome_original]);

        return Storage::disk('local')->download($anexo->caminho, $anexo->nome_original, ['Content-Type' => $anexo->mime]);
    }

    public function remover(Anexo $anexo, Anexos $anexos): RedirectResponse
    {
        $anexos->remover($anexo);

        return back()->with('ok', 'Anexo removido. A remoção ficou registrada no histórico.');
    }
}
