<?php

namespace App\Http\Controllers;

use App\Support\BaseConhecimento;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ConhecimentoController extends Controller
{
    public function index(Request $request): View
    {
        $categorias = BaseConhecimento::categorias();
        $q = $request->query('q', '');
        $busca = is_string($q) ? mb_substr(trim($q), 0, 160) : '';
        $filtro = $request->query('categoria');
        $categoria = is_string($filtro) && isset($categorias[$filtro]) ? $filtro : null;
        $artigos = BaseConhecimento::filtrar($busca, $categoria);

        return view('conhecimento.index', compact('artigos', 'busca', 'categoria', 'categorias'));
    }

    public function artigo(Request $request, string $slug): View
    {
        $artigos = BaseConhecimento::artigos();
        abort_unless(isset($artigos[$slug]), 404);
        $artigo = $artigos[$slug];
        $categoria = BaseConhecimento::categorias()[$artigo['categoria']];
        $destino = BaseConhecimento::destino($artigo, ctx(), $request->user());
        $relacoes = config('conhecimento.relacoes.'.$slug);
        if ($relacoes) {
            $relacionados = collect($relacoes)->filter(fn ($chave) => isset($artigos[$chave]))->mapWithKeys(fn ($chave) => [$chave => $artigos[$chave]])->all();
        } else {
            $relacionados = array_slice(array_filter($artigos, fn ($item, $chave) => $chave !== $slug
                && $item['categoria'] === $artigo['categoria'], ARRAY_FILTER_USE_BOTH), 0, 4, true);
        }

        return view('conhecimento.artigo', compact('artigo', 'categoria', 'destino', 'relacionados'));
    }
}
