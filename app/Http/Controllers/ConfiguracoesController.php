<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\Organizacao;
use App\Services\LembretesBancarios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Central de configurações da empresa (CFG-01). Organizada por domínio, com busca.
 * Só lista o que existe e o que o papel atual pode alterar. A identidade do produto e os
 * backups ficam na Administração Augi, separados das configurações do cliente.
 */
class ConfiguracoesController extends Controller
{
    public function index(): View
    {
        $admin = ctx()->tem(Papel::Admin);
        $secoes = [
            ['Minha conta', 'Seu acesso pessoal.', [
                ['Segurança e verificação em duas etapas', 'Ativar 2FA, códigos de recuperação.', route('seguranca'), '2fa senha autenticador recuperação'],
                ['Notificações', 'Avisos que precisam da sua ação.', route('notificacoes.index'), 'alertas avisos lembretes'],
            ]],
            ['Empresa', 'Dados da empresa e relação com o escritório.', array_values(array_filter([
                $admin ? ['Dados gerais', 'Razão social, inscrição municipal, município.', route('organizacao.show'), 'cnpj razão social endereço município'] : null,
                $admin ? ['Escritório contábil', 'Autorizar ou revogar o acesso do contador.', route('organizacao.show', ['aba' => 'escritorio']), 'contador contabilidade autorização'] : null,
            ]))],
            ['Financeiro', 'Contas, categorias e lembretes.', [
                ['Contas e bancos', 'Contas, saldo inicial, ativar/desativar.', route('financeiro.contas'), 'banco conta saldo'],
                ['Lembretes bancários', 'Quando cobrar a atualização do extrato de cada conta.', route('configuracoes.lembretes'), 'extrato lembrete importação frequência ofx'],
                ['Categorias', 'Categorias de receitas e despesas.', route('financeiro.categorias'), 'categoria despesa receita plano de contas'],
                ['Lembretes de vencimento', 'Quando avisar sobre contas a pagar, a receber e faturas (15, 10, 5, 1 dia antes e no dia).', route('configuracoes.vencimentos'), 'lembrete aviso vencimento alerta notificação conta pagar'],
                ['Google Agenda', 'Sincronizar vencimentos com o Google Agenda, com lembretes.', route('integracoes.google'), 'google agenda calendario calendar integração integracao sincronizar'],
                ['Cartões', 'Cartões corporativos: limite, fechamento, vencimento e responsável.', route('cartoes.index'), 'cartao credito fatura limite fechamento vencimento'],
            ]],
            ['Inteligência', 'Preferências do assistente financeiro e das sugestões desta empresa.', [
                ['Assistente, sugestões e tokens', 'Disponibilidade, fontes de dados e modos de uso.', route('configuracoes.inteligencia'), 'ia inteligência assistente sugestões tokens chaves regras'],
            ]],
            ['Fiscal', 'Regimes tributários por CNPJ.', [
                ['Perfis fiscais', 'Regime de cada CNPJ e vigência, confirmados pelo contador.', route('perfis.index'), 'regime simples lucro presumido ibs cbs'],
            ]],
            ['Equipe e acessos', 'Quem acessa a empresa e com qual papel.', array_values(array_filter([
                $admin ? ['Usuários e convites', 'Convidar, definir papel, encerrar acesso.', route('usuarios.index'), 'usuário convite permissão papel acesso'] : null,
            ]))],
        ];

        if (auth()->user()->admin_plataforma) {
            $secoes[] = ['Administração Augi', 'Configurações globais de todas as empresas e escritórios.', [
                ['Título e identidade', 'Nome do sistema, slogan e assinatura institucional.', route('plataforma.configuracoes'), 'marca título nome'],
                ['E-mail e SMTP', 'Remetente, credencial e envio de teste.', route('plataforma.smtp'), 'smtp email senha servidor convite token'],
            ]];
        }

        return view('configuracoes.index', ['secoes' => array_values(array_filter($secoes, fn ($s) => $s[2] !== []))]);
    }

    public function inteligencia(): View
    {
        return view('configuracoes.inteligencia', ['empresa' => ctx()->empresa(), 'editavel' => ctx()->tem(Papel::Admin)]);
    }

    public function salvarInteligencia(Request $request): RedirectResponse
    {
        $dados = $request->validate(['assistente_ativo' => ['required', 'boolean'], 'sugestoes_ativas' => ['required', 'boolean']]);
        DB::transaction(function () use ($dados) {
            $empresa = Organizacao::whereKey(ctx()->organizacaoId())->lockForUpdate()->firstOrFail();
            $empresa->forceFill($dados);
            if ($empresa->isDirty()) {
                $mudancas = $empresa->getDirty();
                $empresa->save();
                Evento::registrar('inteligencia.configurada', $empresa, $mudancas);
            }
        });

        return back()->with('ok', 'Preferências de inteligência salvas para esta empresa.');
    }

    public function salvarIaRadar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'ativa' => ['required', 'boolean'],
            'limite' => ['required', 'integer', 'between:1,300'],
            'ia_radar_chave' => ['nullable', 'string', 'max:300', 'regex:/^sk-[A-Za-z0-9_-]+$/D'],
            'revogar' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($dados) {
            $empresa = Organizacao::query()->lockForUpdate()->findOrFail(ctx()->organizacaoId());
            $revogar = (bool) ($dados['revogar'] ?? false);
            if ($revogar) {
                $empresa->ia_radar_chave = null;
            } elseif (isset($dados['ia_radar_chave'])) {
                $empresa->ia_radar_chave = $dados['ia_radar_chave'];
            }
            $empresa->ia_radar_ativa = ! $revogar && (bool) $dados['ativa'];
            $empresa->ia_radar_limite = (int) $dados['limite'];
            if ($empresa->ia_radar_ativa && ! $empresa->ia_radar_chave) {
                throw ValidationException::withMessages(['ia_radar_chave' => 'Informe uma chave para habilitar a IA.']);
            }
            if ($empresa->isDirty()) {
                $chaveAlterada = $empresa->isDirty('ia_radar_chave');
                $empresa->save();
                Evento::registrar('ia.radar_configurada', $empresa, ['ativa' => $empresa->ia_radar_ativa,
                    'limite' => $empresa->ia_radar_limite, 'chave_alterada' => $chaveAlterada]);
            }
        });

        return back()->with('ok', 'Preferências da IA do radar salvas.');
    }

    public function lembretes(LembretesBancarios $l): View
    {
        $contas = ContaFinanceira::where('ativa', true)->with('responsavel')->orderBy('nome')->get();

        return view('configuracoes.lembretes', ['contas' => $contas, 'situacoes' => $contas->mapWithKeys(fn ($c) => [$c->id => $l->situacao($c)])]);
    }
}
