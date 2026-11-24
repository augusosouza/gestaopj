<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class GovernancaController extends Controller
{
    public function index(Request $request): View
    {
        $tipos = [
            'pix' => ['Dados de pagamento', [
                'pix.alteracao_solicitada' => 'Revisão de chave Pix solicitada',
                'pix.alteracao_aplicada' => 'Alteração de chave Pix aplicada',
                'pix.alteracao_rejeitada' => 'Alteração de chave Pix rejeitada',
                'pix.alteracao_cancelada' => 'Alteração de chave Pix cancelada',
            ]],
            'acessos' => ['Equipe e acessos', [
                'vinculo.criado' => 'Acesso concedido', 'vinculo.papel_alterado' => 'Papel alterado',
                'vinculo.encerrado' => 'Acesso encerrado', 'autorizacao.concedida' => 'Escritório autorizado',
                'autorizacao.revogada' => 'Autorização do escritório revogada',
            ]],
            'configuracoes' => ['Configurações da empresa', [
                'inteligencia.configurada' => 'Preferências de inteligência atualizadas',
                'google.agenda_alterada' => 'Calendário de destino alterado',
            ]],
        ];
        $filtro = $request->validate(['tipo' => ['nullable', Rule::in(array_keys($tipos))]])['tipo'] ?? null;
        $rotulos = $filtro ? $tipos[$filtro][1] : array_merge(...array_column($tipos, 1));
        $eventos = Evento::query()->where('organizacao_id', ctx()->organizacaoId())->whereIn('acao', array_keys($rotulos))
            ->with('user:id,name')->latest('id')->paginate(25)->withQueryString();

        return view('governanca.index', compact('tipos', 'filtro', 'rotulos', 'eventos'));
    }
}
