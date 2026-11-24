<?php

namespace App\Http\Controllers;

use App\Models\Fatura;
use App\Services\Exportacao;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class FaturaLoteController extends Controller
{
    public function previa(Request $request): Response
    {
        $ids = $request->validate([
            'faturas' => ['required', 'array', 'min:1', 'max:100'],
            'faturas.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])['faturas'];
        $faturas = $this->selecionadas($ids);
        $bloqueadas = count($ids) - $faturas->count();
        $confirmacao = $faturas->isEmpty() ? null : Crypt::encryptString(json_encode([
            'usuario' => (int) $request->user()->id,
            'empresa' => ctx()->organizacaoId(),
            'expira' => now()->addMinutes(10)->timestamp,
            'ids' => $faturas->modelKeys(),
            'revisao' => $this->revisao($this->linhas($faturas)),
        ], JSON_THROW_ON_ERROR));

        return response()->view('faturas.exportacao-lote', compact('faturas', 'bloqueadas', 'confirmacao'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function exportar(Request $request, Exportacao $exportacao): BinaryFileResponse
    {
        $confirmacao = $request->validate(['confirmacao' => ['required', 'string', 'max:8000']])['confirmacao'];
        try {
            $seletores = json_decode(Crypt::decryptString($confirmacao), true);
        } catch (DecryptException) {
            abort(422, 'Confirmação inválida. Refaça a prévia.');
        }
        abort_unless(is_array($seletores) && ($seletores['usuario'] ?? null) === (int) $request->user()->id
            && ($seletores['empresa'] ?? null) === ctx()->organizacaoId()
            && ($seletores['expira'] ?? 0) >= now()->timestamp
            && is_array($seletores['ids'] ?? null) && count($seletores['ids']) >= 1 && count($seletores['ids']) <= 100,
            422, 'Confirmação inválida ou expirada. Refaça a prévia.');

        $faturas = $this->selecionadas($seletores['ids']);
        $linhas = $this->linhas($faturas);
        abort_unless($faturas->count() === count($seletores['ids']) && ($seletores['revisao'] ?? null) === $this->revisao($linhas),
            409, 'A seleção mudou. Refaça a prévia.');
        [$caminho, $nome] = $exportacao->gerarRelatorio([
            'titulo' => 'Faturas selecionadas',
            'colunas' => [['Fatura', 'texto'], ['Contrato', 'texto'], ['Cliente', 'texto'], ['Competência', 'texto'],
                ['Vencimento', 'texto'], ['Situação', 'texto'], ['Valor', 'dinheiro']],
            'linhas' => $linhas,
            'total' => null,
        ], 'faturas-selecionadas', 'xlsx');

        return response()->download($caminho, $nome, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    private function linhas(Collection $faturas): array
    {
        return $faturas->map(fn ($fatura) => [$fatura->id, $fatura->contrato->numero, $fatura->contrato->cliente->razao_social,
            $fatura->competencia->format('m/Y'), $fatura->vencimento->format('d/m/Y'), $fatura->status->rotulo(), $fatura->valor_centavos])->all();
    }

    private function revisao(array $linhas): string
    {
        return hash('sha256', json_encode($linhas, JSON_THROW_ON_ERROR));
    }

    private function selecionadas(array $ids): Collection
    {
        return Fatura::query()->whereKey($ids)->whereHas('contrato.cliente')
            ->with('contrato.cliente')->orderBy('id')->get();
    }
}
