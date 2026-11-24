<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\AlteracaoPix;
use App\Models\Evento;
use App\Models\Notificacao;
use App\Models\Organizacao;
use App\Models\Prestador;
use App\Models\Vinculo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AlteracaoPixController extends Controller
{
    public function solicitar(Request $request, Prestador $prestador): RedirectResponse
    {
        $dados = $request->validate([
            'nova_chave_pix' => ['present', 'nullable', 'string', 'max:140'],
            'motivo' => ['required', 'string', 'max:500'],
            'current_password' => ['required', 'current_password'],
        ]);

        DB::transaction(function () use ($request, $prestador, $dados) {
            $atual = $this->bloquearPrestador($prestador, $request);
            abort_if($atual->chave_pix === $dados['nova_chave_pix'], 422, 'A chave proposta é igual à atual.');
            abort_if(AlteracaoPix::where('prestador_id', $atual->id)->where('estado', 'pendente')->exists(), 422, 'Este prestador já possui uma solicitação pendente.');

            $alteracao = AlteracaoPix::create([
                'organizacao_id' => $atual->organizacao_id, 'prestador_id' => $atual->id,
                'solicitado_por' => $request->user()->id, 'chave_anterior' => $atual->chave_pix,
                'chave_proposta' => $dados['nova_chave_pix'], 'motivo' => $dados['motivo'],
            ]);
            Evento::registrar('pix.alteracao_solicitada', $atual, ['solicitacao' => $alteracao->id]);

            $responsaveis = Vinculo::vigentes()->where('organizacao_id', $atual->organizacao_id)
                ->where('papel', Papel::Admin->value)->where('user_id', '!=', $request->user()->id)->distinct()->pluck('user_id');
            foreach ($responsaveis as $responsavel) {
                Notificacao::avisar($responsavel, $atual->organizacao_id, 'seguranca', 'pix:revisar:'.$alteracao->id,
                    'Alteração de chave Pix em revisão', 'Há uma solicitação de alteração de dados de pagamento que precisa de revisão.',
                    route('prestadores.edit', $atual));
            }
        });

        return back()->with('ok', 'Solicitação registrada. A chave atual permanece em uso até a decisão de outro administrador.');
    }

    public function decidir(Request $request, Prestador $prestador, int $solicitacao): RedirectResponse
    {
        $dados = $request->validate([
            'decisao' => ['required', 'in:aprovar,rejeitar,cancelar'],
            'justificativa_decisao' => ['required', 'string', 'max:500'],
            'current_password' => ['required', 'current_password'],
        ]);

        DB::transaction(function () use ($request, $prestador, $solicitacao, $dados) {
            $atual = $this->bloquearPrestador($prestador, $request);
            $alteracao = AlteracaoPix::where('prestador_id', $atual->id)->findOrFail($solicitacao);
            abort_unless($alteracao->estado === 'pendente', 422, 'A solicitação já foi resolvida.');
            $autor = $alteracao->solicitado_por === $request->user()->id;
            abort_unless($autor === ($dados['decisao'] === 'cancelar'), 403, 'A aprovação ou rejeição exige outro administrador.');

            $estado = match ($dados['decisao']) {
                'aprovar' => 'aplicada', 'rejeitar' => 'rejeitada', 'cancelar' => 'cancelada',
            };
            if ($estado === 'aplicada') {
                abort_unless($atual->chave_pix === $alteracao->chave_anterior, 422, 'A chave atual mudou durante a revisão.');
                $atual->update(['chave_pix' => $alteracao->chave_proposta]);
            }
            $alteracao->update(['estado' => $estado, 'decidido_por' => $request->user()->id,
                'justificativa_decisao' => $dados['justificativa_decisao'], 'decidido_em' => now()]);
            Evento::registrar('pix.alteracao_'.$estado, $atual, ['solicitacao' => $alteracao->id]);
        });

        return back()->with('ok', 'Decisão registrada no histórico.');
    }

    private function bloquearPrestador(Prestador $prestador, Request $request): Prestador
    {
        Organizacao::whereKey($prestador->organizacao_id)->lockForUpdate()->firstOrFail();
        abort_unless(Vinculo::vigentes()->whereKey(ctx()->vinculo()->id)->where('user_id', $request->user()->id)
            ->where('organizacao_id', $prestador->organizacao_id)->where('papel', Papel::Admin->value)->exists(), 403);

        return Prestador::whereKey($prestador->id)->lockForUpdate()->firstOrFail();
    }
}
