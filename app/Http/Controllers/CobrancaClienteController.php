<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LeValores;
use App\Models\Titulo;
use App\Services\Cobrancas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Contatos, promessas e renegociações de contas a receber. */
class CobrancaClienteController extends Controller
{
    use LeValores;

    public function registrar(Request $request, Cobrancas $c, Titulo $titulo): RedirectResponse
    {
        $d = $request->validate(['tipo' => ['required', 'in:contato,promessa'], 'canal' => ['nullable', 'in:telefone,whatsapp,email,presencial,outro'],
            'data' => ['required', 'date'], 'mensagem' => ['nullable', 'string', 'max:2000'], 'resposta' => ['nullable', 'string', 'max:2000'],
            'parcela_id' => ['nullable', 'integer'], 'promessa_data' => ['nullable', 'date'], 'promessa_valor' => ['nullable', 'string']]);
        $c->registrar($titulo, [
            'tipo' => $d['tipo'], 'canal' => $d['canal'] ?? null, 'data' => $d['data'], 'mensagem' => $d['mensagem'] ?? null, 'resposta' => $d['resposta'] ?? null,
            'parcela_id' => isset($d['parcela_id']) ? (int) $d['parcela_id'] : null, 'promessa_data' => $d['promessa_data'] ?? null,
            'promessa_valor' => $d['tipo'] === 'promessa' ? $this->centavos($d['promessa_valor'] ?? '', 'promessa_valor') : null,
        ]);

        return back()->with('ok', $d['tipo'] === 'promessa' ? 'Promessa registrada. Ela será marcada como cumprida quando o pagamento entrar.' : 'Contato registrado.');
    }

    public function renegociar(Request $request, Cobrancas $c, Titulo $titulo): RedirectResponse
    {
        $d = $request->validate(['parcelas' => ['required', 'array', 'min:1'], 'parcelas.*' => ['integer'], 'datas' => ['nullable', 'array'],
            'datas.*' => ['nullable', 'date'], 'motivo' => ['required', 'string', 'max:255'], 'condicoes' => ['nullable', 'string', 'max:500']],
            ['parcelas.required' => 'Marque as parcelas que serão renegociadas.']);
        $novas = [];
        foreach ($d['parcelas'] as $pid) {
            $novas[(int) $pid] = $d['datas'][$pid] ?? null ?: throw ValidationException::withMessages(['parcelas' => 'Informe a nova data de cada parcela marcada.']);
        }
        $c->renegociar($titulo, $novas, $d['motivo'], $d['condicoes'] ?? null);

        return back()->with('ok', 'Renegociação registrada. Novos vencimentos, lembretes e agenda atualizados.');
    }
}
