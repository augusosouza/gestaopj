<?php

namespace App\Http\Controllers;

use App\Models\GoogleConexao;
use App\Models\GoogleEvento;
use App\Services\GoogleAgenda;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Configurações → Integrações → Google Agenda. Cada usuário conecta a própria conta, em cada empresa. */
class GoogleAgendaController extends Controller
{
    public function index(GoogleAgenda $g): View
    {
        $c = GoogleConexao::where('user_id', auth()->id())->first();
        $agendas = collect();
        if ($c && $c->status === 'ativa' && $c->modo === 'existente') {
            try {
                $agendas = $g->agendas($c);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return view('integracoes.google', [
            'configurado' => $g->configurado(), 'c' => $c, 'agendas' => $agendas,
            'eventos' => $c ? GoogleEvento::where('google_conexao_id', $c->id)->count() : 0,
            'comErro' => $c ? GoogleEvento::where('google_conexao_id', $c->id)->whereNotNull('erro')->count() : 0,
        ]);
    }

    public function conectar(Request $request, GoogleAgenda $g): RedirectResponse
    {
        abort_unless($g->configurado(), 404);
        $d = $request->validate(['modo' => ['required', 'in:dedicada,existente']]);
        $state = Str::random(40);
        // O retorno do Google precisa voltar para a MESMA pessoa, empresa e escolha (proteção contra CSRF).
        session(['google_oauth' => ['state' => $state, 'modo' => $d['modo'], 'org' => ctx()->organizacaoId(), 'user' => auth()->id()]]);

        return redirect()->away($g->urlAutorizacao($d['modo'], $state));
    }

    public function retorno(Request $request, GoogleAgenda $g): RedirectResponse
    {
        $s = session()->pull('google_oauth');
        if (! $s || ! hash_equals($s['state'], (string) $request->query('state')) || $s['org'] !== ctx()->organizacaoId() || $s['user'] !== auth()->id()) {
            return redirect()->route('integracoes.google')->with('erro', 'A autorização não pôde ser confirmada. Tente conectar de novo.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->route('integracoes.google')->with('erro', 'Conexão cancelada na tela do Google.');
        }
        try {
            $c = $g->concluir((string) $request->query('code'), $s['modo'], auth()->user(), $s['org']);
            $g->sincronizar($c);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('integracoes.google')->with('erro', $e instanceof \RuntimeException ? $e->getMessage() : 'Não foi possível concluir a conexão com o Google.');
        }

        return redirect()->route('integracoes.google')->with('ok', 'Google Agenda conectado'.($c->modo === 'dedicada' ? ' na agenda "'.GoogleAgenda::NOME_AGENDA.'".' : '.'));
    }

    public function salvar(Request $request, GoogleAgenda $g): RedirectResponse
    {
        $c = GoogleConexao::where('user_id', auth()->id())->firstOrFail();
        $d = $request->validate(['calendar_id' => ['nullable', 'string', 'max:255'], 'ao_quitar' => ['required', 'in:remover,marcar']]);
        if (filled($d['calendar_id'] ?? null) && $c->modo === 'existente' && $d['calendar_id'] !== $c->calendar_id) {
            $agenda = $g->agendas($c)->firstWhere('id', $d['calendar_id']);
            abort_unless($agenda, 422, 'Agenda não encontrada.');
            // Trocou de agenda: os eventos antigos ficam onde estão; os novos vão para a escolhida.
            $c->eventos()->delete();
            $c->update(['calendar_id' => $agenda['id'], 'calendar_nome' => $agenda['nome']]);
        }
        $c->update(['ao_quitar' => $d['ao_quitar'], 'titulos_discretos' => $request->boolean('titulos_discretos'),
            'sincronizar' => ['pagar' => $request->boolean('pagar'), 'receber' => $request->boolean('receber'), 'cartao' => $request->boolean('cartao')]]);
        $this->tentar(fn () => $g->sincronizar($c->fresh()));

        return back()->with('ok', 'Preferências do Google Agenda salvas.');
    }

    public function sincronizar(GoogleAgenda $g): RedirectResponse
    {
        $c = GoogleConexao::where('user_id', auth()->id())->firstOrFail();
        $st = $this->tentar(fn () => $g->sincronizar($c)) ?? ['criados' => 0, 'atualizados' => 0, 'removidos' => 0, 'erros' => 1];

        return back()->with($st['erros'] ? 'erro' : 'ok', $st['erros'] ? 'Houve falhas; elas serão refeitas automaticamente. Veja o último erro abaixo.'
            : "Sincronizado: {$st['criados']} criado(s), {$st['atualizados']} atualizado(s), {$st['removidos']} removido(s).");
    }

    public function desconectar(Request $request, GoogleAgenda $g): RedirectResponse
    {
        $c = GoogleConexao::where('user_id', auth()->id())->firstOrFail();
        $g->desconectar($c, $request->boolean('limpar'));

        return redirect()->route('integracoes.google')->with('ok', 'Google Agenda desconectado. Os lembretes internos do Augi continuam ativos.');
    }

    private function tentar(callable $f): ?array
    {
        try {
            return $f();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
