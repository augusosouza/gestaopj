<?php

namespace App\Http\Controllers;

use App\Support\Dinheiro;
use App\Support\Documento;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class FerramentasController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $valido = $parcelas = $total = null;
        if ($request->isMethod('post')) {
            $acao = $request->validate(['acao' => ['required', Rule::in(['validar', 'simular'])]])['acao'];
            if ($acao === 'validar') {
                $dados = $request->validate([
                    'tipo' => ['required', Rule::in(['cpf', 'cnpj', 'cep', 'email'])],
                    'valor' => ['required', 'string', 'max:254'],
                ]);
                $valido = match ($dados['tipo']) {
                    'cpf' => Documento::cpfValido($dados['valor']),
                    'cnpj' => Documento::cnpjValido($dados['valor']),
                    'cep' => preg_match('/^\d{5}-?\d{3}$/D', $dados['valor']) === 1,
                    'email' => filter_var($dados['valor'], FILTER_VALIDATE_EMAIL) !== false,
                };
            } else {
                $dados = $request->validate([
                    'total' => ['required', 'string', 'max:16'],
                    'quantidade' => ['required', 'integer', 'between:1,120'],
                    'inicio' => ['required', 'date_format:Y-m-d'],
                ]);
                try {
                    $total = Dinheiro::paraCentavos($dados['total']);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(['total' => 'Informe um valor válido em reais.']);
                }
                if ($total < (int) $dados['quantidade']) {
                    throw ValidationException::withMessages(['total' => 'Cada parcela precisa ter pelo menos um centavo.']);
                }
                $inicio = CarbonImmutable::parse($dados['inicio']);
                $parcelas = collect(Dinheiro::ratear($total, array_fill(0, (int) $dados['quantidade'], 1)))
                    ->map(fn ($valor, $i) => [$inicio->addMonthsNoOverflow($i), $valor]);
            }
        }

        return response()->view('busca.ferramentas', compact('valido', 'parcelas', 'total'))->header('Cache-Control', 'private, no-store');
    }
}
