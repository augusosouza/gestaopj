<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\ContaFinanceira;
use App\Services\LembretesBancarios;
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
            ]],
            ['Fiscal', 'Regimes tributários por CNPJ.', [
                ['Perfis fiscais', 'Regime de cada CNPJ e vigência, confirmados pelo contador.', route('perfis.index'), 'regime simples lucro presumido ibs cbs'],
            ]],
            ['Equipe e acessos', 'Quem acessa a empresa e com qual papel.', array_values(array_filter([
                $admin ? ['Usuários e convites', 'Convidar, definir papel, encerrar acesso.', route('usuarios.index'), 'usuário convite permissão papel acesso'] : null,
            ]))],
        ];

        return view('configuracoes.index', ['secoes' => array_values(array_filter($secoes, fn ($s) => $s[2] !== []))]);
    }

    public function lembretes(LembretesBancarios $l): View
    {
        $contas = ContaFinanceira::where('ativa', true)->with('responsavel')->orderBy('nome')->get();

        return view('configuracoes.lembretes', ['contas' => $contas, 'situacoes' => $contas->mapWithKeys(fn ($c) => [$c->id => $l->situacao($c)])]);
    }
}
