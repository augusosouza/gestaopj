<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Titulo;
use App\Models\User;
use App\Services\Faturamento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** QA-01 — menu por papel: só aparece o permitido, e tudo o que aparece abre. */
class NavegacaoTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::now()->startOfMonth());
    }

    /** @return array{0: list<string>, 1: list<string>} [rótulos, urls] do menu principal */
    private function menu(TestResponse $r): array
    {
        preg_match('/<nav aria-label="Principal" class="menu">(.*?)<\/nav>/s', $r->getContent(), $m);
        $this->assertNotEmpty($m, 'menu lateral não encontrado');
        preg_match_all('/<a href="([^"]+)"[^>]*>(.*?)<\/a>/s', $m[1], $links);
        preg_match_all('/<summary>.*?<span>(.*?)<\/span><\/summary>/s', $m[1], $grupos);
        $rotulos = array_merge(array_map(fn ($h) => trim(strip_tags($h)), $links[2]), $grupos[1]);

        return [$rotulos, array_map('html_entity_decode', $links[1])];
    }

    private function percorrer(string $email, string $cnpj, array $deveTer, array $naoPodeTer): void
    {
        [$rotulos, $urls] = $this->menu($this->como($email, $cnpj)->get('/')->assertOk());
        foreach ($deveTer as $r) {
            $this->assertContains($r, $rotulos, "{$email} deveria ver “{$r}”");
        }
        foreach ($naoPodeTer as $r) {
            $this->assertNotContains($r, $rotulos, "{$email} NÃO deveria ver “{$r}”");
        }
        foreach ($urls as $u) {
            $r = $this->como($email, $cnpj)->get($u);
            $this->assertSame(200, $r->status(), "{$email} abriu {$u}");
        }
    }

    public function test_financeiro(): void
    {
        $this->percorrer('financeiro@augi.local', self::A,
            ['Início', 'Operação', 'Financeiro', 'Fiscal', 'Contabilidade', 'Painel financeiro', 'Contas a receber', 'Contas a pagar', 'Contas e bancos', 'Configurações', 'Faturamento e NFS-e'],
            ['Administração', 'Administração Augi', 'Clientes', 'Prestadores', 'Equipe e acessos', 'Carteira', 'Backups']);
    }

    public function test_administrador_da_empresa_e_da_plataforma(): void
    {
        $this->percorrer('admin@augi.local', self::A,
            ['Administração', 'Empresa', 'Equipe e acessos', 'Clientes', 'Prestadores', 'Configurações', 'Administração Augi', 'Backups', 'Identidade'],
            ['Carteira', 'Atribuições']);
        // Administrador de outra empresa, sem ser da plataforma
        $this->percorrer('admin.b@augi.local', self::B, ['Administração', 'Configurações'], ['Administração Augi', 'Backups']);
    }

    public function test_analista_e_revisor_do_escritorio(): void
    {
        $this->percorrer('contabilidade@augi.local', self::A,
            ['Financeiro', 'Fiscal', 'Contabilidade', 'Fechamento contábil', 'Carteira'],
            ['Configurações', 'Administração', 'Equipe e acessos', 'Clientes', 'Envios ao contador', 'Atribuições']);
        $this->percorrer('revisor@augi.local', self::A, ['Atribuições', 'Equipe e acessos', 'Carteira'], ['Configurações', 'Empresa']);
    }

    public function test_prestador_ve_so_as_proprias_cobrancas(): void
    {
        $this->percorrer('prestador@augi.local', self::A, ['Minhas cobranças', 'Notificações'],
            ['Operação', 'Financeiro', 'Fiscal', 'Contabilidade', 'Configurações', 'Administração']);
    }

    public function test_paginas_novas_e_abas(): void
    {
        $conta = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Banco X', 'tipo' => 'corrente', 'saldo_inicial_em' => now()->subMonth()->toDateString(), 'lembrete_ativo' => true]);
        $t = Titulo::where('organizacao_id', $this->empresa()->id)->where('tipo', 'pagar')->firstOrFail();
        foreach (['resumo', 'fluxo', 'despesas'] as $a) {
            $this->como('financeiro@augi.local')->get(route('financeiro.painel', ['aba' => $a]))->assertOk();
        }
        $this->como('financeiro@augi.local')->get(route('financeiro.painel'))->assertSee('Ações necessárias')->assertSee('Extrato desatualizado');
        $this->como('financeiro@augi.local')->get(route('financeiro.painel', ['aba' => 'despesas']))->assertSee('Prestadores de serviço');
        foreach (['resumo', 'parcelas', 'pagamentos', 'historico'] as $a) {
            $this->como('financeiro@augi.local')->get(route('financeiro.titulo', [$t, 'aba' => $a]))->assertOk();
        }
        $this->como('financeiro@augi.local')->get(route('financeiro.index', ['q' => 'Consultor', 'periodo' => 'todos']))->assertSee('Prestador Consultor')->assertDontSee('Fatura CT-2026-001');
        $this->como('financeiro@augi.local')->get(route('configuracoes'))->assertOk()->assertSee('Lembretes bancários')->assertDontSee('Usuários e convites');
        $this->como('admin@augi.local')->get(route('configuracoes'))->assertSee('Usuários e convites')->assertSee('Escritório contábil');
        $this->como('financeiro@augi.local')->get(route('configuracoes.lembretes'))->assertOk()->assertSee('Banco X');
        $this->como('admin@augi.local')->get(route('organizacao.show', ['aba' => 'escritorio']))->assertOk()->assertSee('Autorizar');
        $this->como('contabilidade@augi.local')->get(route('configuracoes'))->assertForbidden();
    }

    public function test_seguranca_mostra_o_menu_e_nao_trava_quem_precisa_de_2fa(): void
    {
        config(['gestao.exigir_2fa' => true]);
        $this->como('admin@augi.local')->get('/')->assertRedirect(route('seguranca'));
        $r = $this->como('admin@augi.local')->get(route('seguranca'))->assertOk();
        $this->assertStringContainsString('class="menu"', $r->getContent());
        // Sem contexto (logo após o login) a página também abre
        $this->actingAs(User::where('email', 'admin@augi.local')->sole())->get(route('seguranca'))->assertOk();
    }
}
