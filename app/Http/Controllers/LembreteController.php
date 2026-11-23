<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\GoogleConexao;
use App\Models\LembreteConfig;
use App\Models\Parcela;
use App\Models\Titulo;
use App\Services\Financeiro;
use App\Services\GoogleAgenda;
use App\Services\LembretesVencimento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** FIN-REM-01 — configuração dos lembretes (padrão do usuário e por obrigação) e renegociação de vencimento. */
class LembreteController extends Controller
{
    public function padroes(LembretesVencimento $l): View
    {
        $org = ctx()->organizacaoId();

        return view('configuracoes.lembretes_vencimento', [
            'configs' => collect(LembreteConfig::TIPOS)->mapWithKeys(fn ($r, $t) => [$t => $l->config(auth()->id(), $org, $t)]),
            'google' => GoogleConexao::where('user_id', auth()->id())->first(),
        ]);
    }

    public function salvarPadrao(Request $request, LembretesVencimento $l, string $tipo): RedirectResponse
    {
        abort_unless(array_key_exists($tipo, LembreteConfig::TIPOS), 404);
        $l->salvar(auth()->id(), ctx()->organizacaoId(), $tipo, null, $this->dados($request));
        Evento::registrar('financeiro.lembretes_configurados', auth()->user(), ['tipo' => LembreteConfig::TIPOS[$tipo], 'padrao' => 'sim']);

        return back()->with('ok', 'Padrão de lembretes salvo para '.mb_strtolower(LembreteConfig::TIPOS[$tipo]).'.');
    }

    public function salvarTitulo(Request $request, LembretesVencimento $l, Titulo $titulo): RedirectResponse
    {
        if ($request->boolean('restaurar')) {
            LembreteConfig::where('user_id', auth()->id())->where('titulo_id', $titulo->id)->delete();
            Evento::registrar('financeiro.lembretes_configurados', $titulo, ['restaurado' => 'padrão']);

            return back()->with('ok', 'Este lançamento voltou a usar o seu padrão de lembretes.');
        }
        $d = $this->dados($request);
        $l->salvar(auth()->id(), $titulo->organizacao_id, $titulo->tipo, $titulo->id, $d);
        Evento::registrar('financeiro.lembretes_configurados', $titulo, ['dias' => implode(', ', $d['dias']), 'hora' => $d['hora'], 'ativo' => $d['ativo'] ? 'sim' : 'não']);

        return back()->with('ok', 'Lembretes deste lançamento salvos. Os avisos e o Google Agenda usam a nova configuração.');
    }

    /** Renegocia o vencimento; lembretes e evento do Google são recalculados (o mesmo evento é atualizado). */
    public function alterarVencimento(Request $request, Financeiro $fin, GoogleAgenda $g, Parcela $parcela): RedirectResponse
    {
        $d = $request->validate(['vencimento' => ['required', 'date'], 'motivo' => ['required', 'string', 'max:255']]);
        $fin->alterarVencimento($parcela, $d['vencimento'], $d['motivo']);
        $this->sincronizarAgora($g, $parcela->titulo_id);

        return back()->with('ok', 'Vencimento alterado. Lembretes recalculados.');
    }

    /** "Adicionar ao Google Agenda": sincroniza já este lançamento (se conectado); sem conexão, a tela oferece o link manual. */
    public function adicionarGoogle(GoogleAgenda $g, Titulo $titulo): RedirectResponse
    {
        $c = GoogleConexao::where('user_id', auth()->id())->where('status', 'ativa')->first();
        if (! $c) {
            return redirect()->route('integracoes.google')->with('erro', 'Conecte o Google Agenda para sincronizar automaticamente.');
        }
        $st = $g->sincronizar($c, $titulo->id);

        return back()->with($st['erros'] ? 'erro' : 'ok', $st['erros']
            ? 'Não foi possível falar com o Google agora. Vamos tentar de novo automaticamente; os lembretes do Augi continuam valendo.'
            : 'Google Agenda atualizado: '.($st['criados'] + $st['atualizados']).' evento(s).');
    }

    private function dados(Request $request): array
    {
        $d = $request->validate(['dias' => ['nullable', 'array'], 'dias.*' => ['integer'], 'hora' => ['required', 'date_format:H:i'],
            'canais_google' => ['nullable', 'array'], 'canais_google.*' => ['string'], 'ativo' => ['nullable', 'boolean']]);

        return ['dias' => $d['dias'] ?? [], 'hora' => $d['hora'], 'canais_google' => $d['canais_google'] ?? [], 'ativo' => $request->boolean('ativo')];
    }

    /** Falha do Google nunca impede a operação financeira. */
    private function sincronizarAgora(GoogleAgenda $g, int $tituloId): void
    {
        foreach (GoogleConexao::where('status', 'ativa')->get() as $c) {
            try {
                $g->sincronizar($c, $tituloId);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
