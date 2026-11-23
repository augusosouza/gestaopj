<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LeValores;
use App\Models\ContaFinanceira;
use App\Services\PosicaoFinanceira;
use App\Services\Recebimentos;
use App\Services\Sugestoes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Meu Caixa (posição e projeção), agenda financeira e inadimplência. */
class CaixaController extends Controller
{
    use LeValores;

    public const HORIZONTES = [7, 15, 30, 60, 90];

    public function caixa(Request $request, PosicaoFinanceira $posicao, Sugestoes $sugestoes): View
    {
        $dias = in_array($request->integer('dias'), self::HORIZONTES, true) ? $request->integer('dias') : 30;
        $contaIds = array_values(array_filter(array_map('intval', (array) $request->query('contas', []))));
        $incluir = $request->boolean('vencidos');
        $simulacao = null;
        if ($request->filled('simular_valor')) {
            $request->validate(['simular_data' => ['required', 'date']], ['simular_data.required' => 'Informe a data da simulação.']);
            $simulacao = ['valor' => $this->centavos($request->query('simular_valor'), 'simular_valor'),
                'data' => $request->query('simular_data'), 'tipo' => $request->query('simular_tipo') === 'receber' ? 'receber' : 'pagar'];
        }
        $p = $posicao->projetar($dias, $contaIds ?: null, $incluir, $simulacao);
        $semSimular = $simulacao ? $posicao->projetar($dias, $contaIds ?: null, $incluir) : null;

        return view('financeiro.caixa', [
            'p' => $p, 'semSimular' => $semSimular, 'dias' => $dias, 'contaIds' => $contaIds, 'incluir' => $incluir,
            'todasContas' => ContaFinanceira::where('ativa', true)->orderBy('nome')->get(),
            'confirmados' => $posicao->confirmados($p['contas']),
            'sugestoes' => $sugestoes->gerar()->whereIn('tipo', ['caixa', 'inadimplencia', 'fatura', 'parcelados'])->take(4)->values(),
        ]);
    }

    public function agenda(Request $request, PosicaoFinanceira $posicao): View
    {
        $visao = $request->query('visao') === 'mes' ? 'mes' : 'lista';
        $hoje = CarbonImmutable::today();
        $tipo = in_array($request->query('tipo'), ['receber', 'pagar'], true) ? $request->query('tipo') : null;

        if ($visao === 'mes') {
            $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('mes')) ? CarbonImmutable::parse($request->query('mes').'-01') : $hoje->startOfMonth();
            $ev = $posicao->compromissos($mes->endOfMonth(), true)
                ->filter(fn ($e) => $e['vencimento']->gte($mes) && $e['vencimento']->lte($mes->endOfMonth()))
                ->when($tipo, fn ($c) => $c->where('tipo', $tipo));

            return view('financeiro.agenda', ['visao' => $visao, 'mes' => $mes, 'tipo' => $tipo, 'dias' => null,
                'porDia' => $ev->groupBy(fn ($e) => $e['vencimento']->toDateString()), 'atrasados' => collect(), 'eventos' => $ev]);
        }

        $dias = in_array($request->integer('dias'), self::HORIZONTES, true) ? $request->integer('dias') : 15;
        $dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('dia')) ? $request->query('dia') : null;
        $ate = $dia ? max(CarbonImmutable::parse($dia), $hoje) : $hoje->addDays($dias);
        $ev = $posicao->compromissos($ate, true)->when($tipo, fn ($c) => $c->where('tipo', $tipo));
        $atrasados = $ev->where('atrasado', true)->values();
        $futuros = $dia ? $ev->filter(fn ($e) => $e['vencimento']->toDateString() === $dia) : $ev->where('atrasado', false);

        return view('financeiro.agenda', ['visao' => $visao, 'mes' => $hoje->startOfMonth(), 'tipo' => $tipo, 'dias' => $dias,
            'atrasados' => $dia ? collect() : $atrasados, 'porDia' => $futuros->groupBy(fn ($e) => $e['vencimento']->toDateString()), 'eventos' => $ev]);
    }

    public function inadimplencia(Request $request, Recebimentos $receb): View
    {
        $faixa = array_key_exists((string) $request->query('faixa'), Recebimentos::FAIXAS) ? $request->query('faixa') : null;
        $cliente = trim((string) $request->query('cliente'));
        $vencidas = $receb->vencidas();
        $porFaixa = collect(Recebimentos::FAIXAS)->keys()->mapWithKeys(fn ($k) => [$k => [
            'qtd' => $vencidas->where('faixa', $k)->count(), 'valor' => (int) $vencidas->where('faixa', $k)->sum('saldo')]]);
        $filtradas = $vencidas->when($faixa, fn ($c) => $c->where('faixa', $faixa))
            ->when($cliente !== '', fn ($c) => $c->filter(fn ($v) => mb_stripos($v['cliente'], $cliente) !== false));

        return view('financeiro.inadimplencia', [
            'clientes' => $receb->porCliente($filtradas->values()), 'porFaixa' => $porFaixa, 'faixa' => $faixa, 'cliente' => $cliente,
            'total' => (int) $vencidas->sum('saldo'), 'qtdClientes' => $vencidas->pluck('cliente')->unique()->count(),
        ]);
    }
}
