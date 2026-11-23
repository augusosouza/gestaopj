<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\NotaFiscal;
use App\Services\Receita\ComponenteIndisponivel;
use App\Services\Receita\GeradorDanfse;
use App\Support\Competencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotaFiscalController extends Controller
{
    public function index(Request $request): View
    {
        $competencia = $request->filled('competencia') ? Competencia::de($request->query('competencia')) : null;
        $direcao = in_array($request->query('direcao'), ['emitida', 'recebida'], true) ? $request->query('direcao') : null;
        $notas = NotaFiscal::query()
            ->when($competencia, fn ($q) => $q->whereDate('competencia', $competencia->toDateString()))
            ->when($direcao, fn ($q) => $q->where('direcao', $direcao))
            ->latest('id')->paginate(50)->withQueryString();

        return view('notas.index', compact('notas', 'competencia', 'direcao'));
    }

    /** DANFSe em PDF pelo gerador oficial da Receita. */
    public function danfse(NotaFiscal $nota, GeradorDanfse $gerador): StreamedResponse|RedirectResponse
    {
        $this->autorizar($nota);
        try {
            $caminho = $gerador->pdf($nota);
        } catch (ComponenteIndisponivel $e) {
            return back()->withErrors(['danfse' => $e->getMessage()]);
        }

        return Storage::disk('local')->download($caminho, "DANFSe-{$nota->chave_acesso}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /** Prestador só acessa notas das próprias cobranças. */
    private function autorizar(NotaFiscal $nota): void
    {
        $ctx = ctx();
        if ($ctx->ehPrestador()) {
            abort_unless($nota->cobranca && $nota->cobranca->prestador_id === $ctx->prestadorId(), 404);
        }
    }

    public function xml(Request $request, NotaFiscal $nota): StreamedResponse
    {
        $this->autorizar($nota);
        abort_unless($nota->xml_path && Storage::disk('local')->exists($nota->xml_path), 404);
        Evento::registrar('nota.xml_baixado', $nota);

        return Storage::disk('local')->download($nota->xml_path, "NFSe-{$nota->chave_acesso}.xml", ['Content-Type' => 'application/xml']);
    }
}
