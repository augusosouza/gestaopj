<?php

namespace App\Http\Controllers;

use App\Services\Exportacao;
use App\Services\Relatorios;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Financeiro → Relatórios: central de relatórios gerenciais e DRE gerencial. */
class RelatorioController extends Controller
{
    public function index(): View
    {
        return view('relatorios.index', ['lista' => Relatorios::LISTA]);
    }

    public function show(Request $request, Relatorios $rel, string $slug): View
    {
        abort_unless(array_key_exists($slug, Relatorios::LISTA), 404);
        [$de, $ate, $regime] = $this->periodo($request);

        return view('relatorios.show', ['slug' => $slug, 'r' => $rel->gerar($slug, $de, $ate, $regime), 'de' => $de, 'ate' => $ate, 'regime' => $regime]);
    }

    public function exportar(Request $request, Relatorios $rel, Exportacao $exp, string $slug): BinaryFileResponse
    {
        abort_unless(array_key_exists($slug, Relatorios::LISTA), 404);
        [$de, $ate, $regime] = $this->periodo($request);
        [$caminho, $nome] = $exp->gerarRelatorio($rel->gerar($slug, $de, $ate, $regime), $slug, (string) $request->query('formato', 'xlsx'));

        return response()->download($caminho, $nome)->deleteFileAfterSend();
    }

    /** Período: de/até em meses (padrão: janeiro até o mês atual), no máximo 24 meses. */
    private function periodo(Request $r): array
    {
        $mes = fn ($k, $padrao) => preg_match('/^\d{4}-\d{2}$/', (string) $r->query($k)) ? CarbonImmutable::parse($r->query($k).'-01') : $padrao;
        $de = $mes('de', CarbonImmutable::today()->startOfYear());
        $ate = $mes('ate', CarbonImmutable::today()->startOfMonth())->endOfMonth();
        if ($ate->lt($de)) {
            [$de, $ate] = [$ate->startOfMonth(), $de->endOfMonth()];
        }
        if ($de->diffInMonths($ate) > 24) {
            $de = $ate->startOfMonth()->subMonths(23);
        }

        return [$de, $ate, $r->query('regime') === 'caixa' ? 'caixa' : 'competencia'];
    }
}
