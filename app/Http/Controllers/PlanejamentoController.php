<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LeValores;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\Contrato;
use App\Models\Evento;
use App\Models\Titulo;
use App\Services\Planejamento;
use App\Support\Competencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Financeiro → Planejamento: orçamentos, centros de custo e comparativo mensal. */
class PlanejamentoController extends Controller
{
    use LeValores;

    public const ABAS = ['orcamentos' => 'Orçamentos', 'centros' => 'Centros de custo', 'comparativo' => 'Comparativo mensal'];

    public function index(Request $request, Planejamento $pl): View
    {
        $aba = array_key_exists((string) $request->query('aba'), self::ABAS) ? $request->query('aba') : 'orcamentos';
        $mes = Competencia::de($request->query('competencia'));

        return view('financeiro.planejamento', ['aba' => $aba, 'competencia' => $mes] + match ($aba) {
            'orcamentos' => ['linhas' => $pl->acompanhamento($mes), 'sem' => $pl->semOrcamento($mes),
                'categorias' => CategoriaFinanceira::with('pai')->where('tipo', 'despesa')->where('ativa', true)->get()->sortBy(fn ($c) => $c->caminho()),
                'centros' => CentroCusto::where('ativo', true)->orderBy('nome')->get()],
            'centros' => ['centros' => CentroCusto::orderByDesc('ativo')->orderBy('nome')->get(), 'porCentro' => $pl->porCentro($mes),
                'contratos' => Contrato::orderBy('numero')->get()],
            'comparativo' => $pl->comparativo($mes),
        });
    }

    public function salvarOrcamento(Request $request, Planejamento $pl): RedirectResponse
    {
        $d = $request->validate(['competencia' => ['required', 'date_format:Y-m'], 'alvo' => ['required', 'regex:/^(cat|cc):\d+$/'], 'valor' => ['required', 'string']],
            ['alvo.required' => 'Escolha a categoria ou o centro de custo.']);
        [$tipo, $id] = explode(':', $d['alvo']);
        $pl->salvarOrcamento(Competencia::de($d['competencia']), $tipo === 'cat' ? (int) $id : null, $tipo === 'cc' ? (int) $id : null, $this->centavos($d['valor'], 'valor'));

        return back()->with('ok', 'Orçamento salvo.');
    }

    public function copiar(Request $request, Planejamento $pl): RedirectResponse
    {
        $n = $pl->copiarMesAnterior(Competencia::de($request->input('competencia')));

        return back()->with('ok', $n ? "{$n} orçamento(s) copiado(s) do mês anterior." : 'Nada para copiar (o mês anterior não tem orçamentos novos para este mês).');
    }

    public function salvarCentro(Request $request): RedirectResponse
    {
        $org = ctx()->organizacaoId();
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:80', Rule::unique('centros_custo')->where('organizacao_id', $org)],
            'codigo' => ['nullable', 'string', 'max:20'], 'tipo' => ['required', Rule::in(array_keys(CentroCusto::TIPOS))],
            'contrato_id' => ['nullable', Rule::exists('contratos', 'id')->where('organizacao_id', $org)],
        ]);
        $c = CentroCusto::create($d);
        Evento::registrar('financeiro.centro_custo', $c, ['nome' => $c->nome]);

        return back()->with('ok', 'Centro de custo criado.');
    }

    public function alternarCentro(CentroCusto $centro): RedirectResponse
    {
        $centro->update(['ativo' => ! $centro->ativo]);

        return back()->with('ok', $centro->ativo ? 'Centro reativado.' : 'Centro desativado. Rateios antigos continuam valendo nos relatórios.');
    }

    public function ratear(Request $request, Planejamento $pl, Titulo $titulo): RedirectResponse
    {
        $d = $request->validate(['rateio' => ['nullable', 'array'], 'rateio.*' => ['nullable', 'string']]);
        $pl->ratear($titulo, $d['rateio'] ?? []);

        return back()->with('ok', 'Rateio salvo.');
    }
}
