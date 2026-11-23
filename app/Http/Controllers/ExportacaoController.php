<?php

namespace App\Http\Controllers;

use App\Services\Exportacao;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Downloads de relatórios (XLSX/CSV). O arquivo é temporário e apagado após o envio. */
class ExportacaoController extends Controller
{
    public function __invoke(Request $request, Exportacao $exp, string $relatorio): BinaryFileResponse
    {
        abort_unless(array_key_exists($relatorio, Exportacao::RELATORIOS), 404);
        [$caminho, $nome] = $exp->gerar($relatorio, (string) $request->query('formato', 'xlsx'), $request);

        return response()->download($caminho, $nome)->deleteFileAfterSend();
    }
}
