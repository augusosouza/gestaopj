<?php

namespace App\Http\Controllers;

use App\Enums\EtapaContabil;
use App\Models\AutorizacaoEscritorio;
use App\Models\CompetenciaContabil;
use App\Models\Evento;
use App\Models\Organizacao;
use App\Models\Vinculo;
use App\Support\Competencia;
use App\Support\Contexto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Escolha do contexto ativo e carteira do escritório. */
class ContextoController extends Controller
{
    public function escolher(Request $request): View|RedirectResponse
    {
        $vinculos = Vinculo::vigentes()->with('organizacao', 'prestador')
            ->where('user_id', $request->user()->id)->get();

        // Um único vínculo: entra direto.
        if ($vinculos->count() === 1 && ! session('aviso')) {
            return $this->ativar($request, $vinculos->first());
        }

        return view('contexto.escolher', compact('vinculos'));
    }

    public function selecionar(Request $request): RedirectResponse
    {
        $d = $request->validate(['vinculo' => ['required', 'integer']]);
        $vinculo = Vinculo::vigentes()->where('user_id', $request->user()->id)->find($d['vinculo']);
        abort_if($vinculo === null, 403, 'Vínculo inexistente, encerrado ou de outra pessoa.');

        return $this->ativar($request, $vinculo);
    }

    private function ativar(Request $request, Vinculo $vinculo): RedirectResponse
    {
        $request->session()->put('ctx', ['vinculo' => $vinculo->id, 'empresa' => null]);

        return $vinculo->papel->deEscritorio() ? redirect()->route('carteira') : redirect()->route('painel');
    }

    /**
     * Carteira do escritório: empresas que autorizaram o escritório (analista: só as atribuídas),
     * com a situação do fechamento contábil no mês escolhido.
     */
    public function carteira(Request $request, Contexto $ctx): View|RedirectResponse
    {
        if (! $ctx->ehEscritorio()) {
            return redirect()->route('painel');
        }
        $vinculo = $ctx->vinculo();
        $mes = Competencia::de($request->query('mes') ?: now()->subMonthNoOverflow()->format('Y-m'));
        $empresas = AutorizacaoEscritorio::vigentes()->with('empresa')
            ->where('escritorio_id', $vinculo->organizacao_id)->get()
            ->pluck('empresa')
            ->filter(fn (Organizacao $e) => Contexto::escritorioAcessa($vinculo, $e->id))
            ->sortBy('razao_social')->values();

        // Consulta explícita fora do escopo de empresa, restrita às empresas da carteira.
        $ids = $empresas->pluck('id');
        $ccs = CompetenciaContabil::withoutGlobalScope('organizacao')
            ->whereIn('organizacao_id', $ids)->whereDate('competencia', $mes)
            ->withCount([
                'documentos' => fn ($q) => $q->withoutGlobalScope('organizacao'),
                'solicitacoes as abertas_count' => fn ($q) => $q->withoutGlobalScope('organizacao')->where('status', 'aberta'),
                'solicitacoes as respondidas_count' => fn ($q) => $q->withoutGlobalScope('organizacao')->where('status', 'respondida'),
            ])->get()->keyBy('organizacao_id');

        $linhas = $empresas->map(function (Organizacao $e) use ($ccs) {
            $cc = $ccs->get($e->id);
            $etapa = $cc?->etapa ?? EtapaContabil::Coleta;
            $situacao = match (true) {
                $etapa === EtapaContabil::Fechada => 'fechada',
                ($cc?->respondidas_count ?? 0) > 0 || ($cc?->enviada_em && $etapa !== EtapaContabil::Revisao) => 'com_contador',
                $etapa === EtapaContabil::Revisao => 'revisao',
                default => 'com_cliente',
            };

            return compact('e', 'cc', 'etapa', 'situacao');
        });

        $filtros = ['' => 'Todas', 'com_cliente' => 'Aguardando a empresa', 'com_contador' => 'Aguardando o escritório', 'revisao' => 'Prontas para revisão', 'fechada' => 'Fechadas'];
        $filtro = array_key_exists((string) $request->query('filtro'), $filtros) ? (string) $request->query('filtro') : '';
        $contagem = $linhas->countBy('situacao');
        if ($filtro !== '') {
            $linhas = $linhas->where('situacao', $filtro)->values();
        }

        return view('contexto.carteira', ['linhas' => $linhas, 'escritorio' => $vinculo->organizacao,
            'mes' => $mes, 'filtros' => $filtros, 'filtro' => $filtro, 'contagem' => $contagem, 'total' => $empresas->count()]);
    }

    public function entrar(Request $request, Contexto $ctx, Organizacao $empresa): RedirectResponse
    {
        abort_unless($ctx->ehEscritorio() && Contexto::escritorioAcessa($ctx->vinculo(), $empresa->id), 403,
            'Esta empresa não está na sua carteira autorizada.');

        $request->session()->put('ctx.empresa', $empresa->id);
        $destino = $request->input('mes') && preg_match('/^\d{4}-\d{2}$/', $request->input('mes'))
            ? route('contabil.show', $request->input('mes')) : route('painel');
        Evento::create([
            'organizacao_id' => $empresa->id, 'user_id' => $request->user()->id, 'ip' => $request->ip(),
            'acao' => 'escritorio.acesso', 'alvo_type' => $empresa->getMorphClass(), 'alvo_id' => $empresa->id,
            'dados' => ['escritorio' => $ctx->vinculo()->organizacao->razao_social],
        ]);

        return redirect($destino);
    }

    public function sairDaEmpresa(Request $request): RedirectResponse
    {
        $request->session()->put('ctx.empresa', null);

        return redirect()->route('carteira');
    }
}
