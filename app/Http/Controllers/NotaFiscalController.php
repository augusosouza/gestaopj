<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\NotaFiscal;
use App\Support\Competencia;
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

    public function xml(Request $request, NotaFiscal $nota): StreamedResponse
    {
        $ctx = ctx();
        if ($ctx->ehPrestador()) {
            abort_unless($nota->cobranca && $nota->cobranca->prestador_id === $ctx->prestadorId(), 404);
        }
        abort_unless($nota->xml_path && Storage::disk('local')->exists($nota->xml_path), 404);
        Evento::registrar('nota.xml_baixado', $nota);

        return Storage::disk('local')->download($nota->xml_path, "NFSe-{$nota->chave_acesso}.xml", ['Content-Type' => 'application/xml']);
    }
}
