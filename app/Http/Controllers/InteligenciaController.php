<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\Evento;
use App\Services\Assistente;
use App\Services\IaRadar;
use App\Services\RadarEconomia;
use App\Services\Sugestoes;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Assistente por regras e motor de sugestões. Funciona sem nenhuma chave de IA. */
class InteligenciaController extends Controller
{
    public function assistente(Request $request, Assistente $a): View
    {
        abort_unless(ctx()->empresa()->assistente_ativo, 403, 'O assistente está desativado nesta empresa.');
        $q = mb_substr(trim((string) $request->query('q')), 0, 300);

        return view('inteligencia.assistente', ['q' => $q, 'r' => $q !== '' ? $a->responder($q) : null, 'sugeridas' => Assistente::SUGERIDAS]);
    }

    public function sugestoes(Request $request, Sugestoes $s): View
    {
        abort_unless(ctx()->empresa()->sugestoes_ativas, 403, 'As sugestões estão desativadas nesta empresa.');
        $tipo = array_key_exists((string) $request->query('tipo'), Sugestoes::TIPOS) ? $request->query('tipo') : null;
        $todas = $s->gerar();

        return view('inteligencia.sugestoes', ['sugestoes' => $todas->when($tipo, fn ($c) => $c->where('tipo', $tipo))->values(),
            'contagem' => $todas->countBy('tipo'), 'tipo' => $tipo, 'podeDispensar' => ctx()->tem(Papel::Admin, Papel::Financeiro)]);
    }

    public function economia(Request $request, RadarEconomia $radar): View
    {
        return $this->mostrarEconomia($request, $radar);
    }

    public function orientar(Request $request, RadarEconomia $radar, IaRadar $ia): View
    {
        $request->validate(['autorizar' => ['required', 'accepted']]);

        return $this->mostrarEconomia($request, $radar, $ia);
    }

    public function acompanharEconomia(Request $request): RedirectResponse
    {
        $empresa = ctx()->empresa();
        abort_unless($empresa->sugestoes_ativas, 403);
        $dados = $request->validate(['base' => ['required', 'date_format:Y-m'], 'acao' => ['required', 'string', 'max:160']]);
        $base = CarbonImmutable::parse($dados['base'].'-01');
        if ($base->gte(CarbonImmutable::today()->startOfMonth())) {
            throw ValidationException::withMessages(['base' => 'Use um mês completo como referência.']);
        }
        Evento::registrar('ia.radar_acompanhamento', $empresa, ['base' => $dados['base'], 'acao' => $dados['acao']]);

        return redirect()->route('inteligencia.economia')->with('ok', 'Acompanhamento de tarifas salvo.');
    }

    private function mostrarEconomia(Request $request, RadarEconomia $radar, ?IaRadar $ia = null): View
    {
        $empresa = ctx()->empresa();
        abort_unless($empresa->sugestoes_ativas, 403);
        $dados = $request->validate([
            'atual' => ['nullable', 'date_format:Y-m'],
            'base' => ['nullable', 'date_format:Y-m', 'before:atual'],
        ]);
        $ultimoMes = CarbonImmutable::today()->startOfMonth()->subMonth();
        $atual = isset($dados['atual']) ? CarbonImmutable::parse($dados['atual'].'-01') : $ultimoMes;
        $acompanhamentos = Evento::query()->where('organizacao_id', $empresa->id)->where('acao', 'ia.radar_acompanhamento')
            ->latest('id')->limit(5)->get();
        $baseSalva = $acompanhamentos->first()?->dados['base'] ?? null;
        $base = isset($dados['base']) ? CarbonImmutable::parse($dados['base'].'-01') : $atual->subMonth();
        if (! isset($dados['base']) && $baseSalva && $baseSalva < $atual->format('Y-m')) {
            $base = CarbonImmutable::parse($baseSalva.'-01');
        }
        if ($atual->gt($ultimoMes) || $base->gte($atual)) {
            throw ValidationException::withMessages(['atual' => 'Compare dois meses completos, com o mês de referência anterior ao mês analisado.']);
        }

        $r = $radar->comparar($empresa->id, $base, $atual);
        $podeUsarIa = $empresa->ia_radar_ativa && ctx()->tem(Papel::Admin, Papel::Financeiro);
        $orientacoes = $ia && $podeUsarIa ? $ia->orientar($empresa, $r) : null;

        return view('inteligencia.economia', ['base' => $base, 'atual' => $atual, 'r' => $r,
            'acompanhamentos' => $acompanhamentos, 'podeUsarIa' => $podeUsarIa, 'orientacoes' => $orientacoes, 'solicitouIa' => $ia !== null]);
    }

    public function dispensar(Request $request, Sugestoes $s): RedirectResponse
    {
        abort_unless(ctx()->empresa()->sugestoes_ativas, 403, 'As sugestões estão desativadas nesta empresa.');
        $d = $request->validate(['chave' => ['required', 'string', 'max:160'], 'dias' => ['nullable', 'integer', 'in:3,7,30'],
            'motivo' => ['nullable', 'string', 'max:255']]);
        $s->dispensar($d['chave'], $d['dias'] ?? null, $d['motivo'] ?? null);

        return back()->with('ok', isset($d['dias']) ? "Sugestão adiada por {$d['dias']} dias." : 'Sugestão dispensada. Se a situação mudar, uma nova sugestão aparece.');
    }
}
