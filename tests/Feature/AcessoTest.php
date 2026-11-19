<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Contrato;
use App\Models\Fatura;
use App\Models\Prestador;
use App\Models\User;
use App\Services\Faturamento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** Login, escolha de contexto, papéis e recuperação de acesso. */
class AcessoTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::parse('2026-10-01')); // A e B
    }

    private function cobrancaA(): Cobranca
    {
        return Cobranca::where('organizacao_id', $this->empresa()->id)->sole();
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Entrar')->assertSee('Esqueci a senha');
    }

    public function test_com_um_unico_vinculo_entra_direto_no_painel(): void
    {
        $this->post('/login', ['email' => 'admin@gestaopj.local', 'password' => 'piloto-2026'])->assertRedirect(route('contexto.escolher'));
        $this->get(route('contexto.escolher'))->assertRedirect(route('painel'));
        $this->get(route('painel', ['competencia' => '2026-10']))->assertOk()->assertSee('Minha Empresa de Suporte');
        $this->assertDatabaseHas('eventos', ['acao' => 'login']);
    }

    public function test_com_varios_vinculos_escolhe_o_contexto(): void
    {
        $this->post('/login', ['email' => 'prestador@gestaopj.local', 'password' => 'piloto-2026']);
        $this->get(route('contexto.escolher'))->assertOk()
            ->assertSee('Minha Empresa de Suporte')->assertSee('Outra Empresa de Serviços');
    }

    public function test_senha_errada_nao_entra(): void
    {
        $this->post('/login', ['email' => 'admin@gestaopj.local', 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $this->como('admin@gestaopj.local')->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_prestador_ve_so_as_proprias_cobrancas(): void
    {
        $cob = $this->cobrancaA();
        $this->como('prestador@gestaopj.local')->get(route('cobrancas.show', $cob))->assertOk()->assertSee('R$ 7.500,00');
        $this->como('prestador@gestaopj.local')->get('/')->assertOk()->assertSee('CT-2026-001')->assertDontSee('CT-B-001');
    }

    public function test_prestador_nao_acessa_areas_internas(): void
    {
        foreach (['faturas.index', 'cobrancas.index', 'notas.index', 'contratos.index', 'clientes.index', 'usuarios.index', 'organizacao.show'] as $r) {
            $this->como('prestador@gestaopj.local')->get(route($r))->assertForbidden();
        }
        $this->como('prestador@gestaopj.local')->post(route('cobrancas.aprovar', $this->cobrancaA()))->assertForbidden();
    }

    public function test_analista_le_mas_nao_aprova_nem_cadastra(): void
    {
        $this->como('contabilidade@gestaopj.local')->get(route('cobrancas.index', ['competencia' => '2026-10']))->assertOk();
        $this->como('contabilidade@gestaopj.local')->post(route('cobrancas.aprovar', $this->cobrancaA()))->assertForbidden();
        $this->como('contabilidade@gestaopj.local')->get(route('clientes.index'))->assertForbidden();
    }

    public function test_todas_as_telas_internas_renderizam(): void
    {
        $cob = $this->cobrancaA();
        foreach ([
            route('painel', ['competencia' => '2026-10']), route('faturas.index', ['competencia' => '2026-10']),
            route('faturas.show', Fatura::where('contrato_id', $cob->contrato_id)->first()), route('cobrancas.index', ['competencia' => '2026-10']),
            route('cobrancas.show', $cob), route('notas.index'), route('contratos.index'), route('contratos.create'),
            route('contratos.show', $cob->contrato_id), route('contratos.edit', $cob->contrato_id), route('perfis.index'),
            route('clientes.index'), route('prestadores.index'), route('organizacao.show'), route('usuarios.index'),
        ] as $url) {
            $this->como('admin@gestaopj.local')->get($url)->assertOk();
        }
        foreach ([route('carteira'), route('escritorio.atribuicoes'), route('usuarios.index')] as $url) {
            $this->como('revisor@gestaopj.local')->get($url)->assertOk();
        }
    }

    public function test_admin_cadastra_contrato_na_propria_empresa(): void
    {
        $clienteA = $this->cobrancaA()->contrato->cliente_id;
        $this->como('admin@gestaopj.local')->post(route('contratos.store'), [
            'cliente_id' => $clienteA, 'numero' => 'CT-2', 'descricao' => 'Consultoria',
            'valor_mensal' => '5.000,00', 'dia_vencimento' => 5, 'inicio' => '2026-11-01', 'status' => 'ativo',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contratos', ['numero' => 'CT-2', 'organizacao_id' => $this->empresa()->id, 'valor_mensal_centavos' => 500000]);

        $this->como('admin@gestaopj.local')->post(route('prestadores.store'), ['nome' => 'X', 'tipo' => 'PJ', 'documento' => '11.222.333/0001-82'])
            ->assertSessionHasErrors('documento');
    }

    public function test_mesmo_numero_de_contrato_pode_existir_em_outra_empresa(): void
    {
        $clienteA = $this->cobrancaA()->contrato->cliente_id;
        $this->como('admin@gestaopj.local')->post(route('contratos.store'), [
            'cliente_id' => $clienteA, 'numero' => 'CT-B-001', 'descricao' => 'x',
            'valor_mensal' => '1,00', 'dia_vencimento' => 5, 'inicio' => '2026-11-01', 'status' => 'ativo',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Contrato::where('numero', 'CT-B-001')->count());
    }

    public function test_convite_cria_vinculo_e_envia_link_de_senha(): void
    {
        Notification::fake();
        $this->como('admin@gestaopj.local')->post(route('usuarios.store'), [
            'name' => 'Nova Financeira', 'email' => 'nova@x.local', 'papel' => 'financeiro',
        ])->assertSessionHasNoErrors();
        $novo = User::where('email', 'nova@x.local')->sole();
        Notification::assertSentTo($novo, ResetPassword::class);
        $this->assertDatabaseHas('vinculos', ['user_id' => $novo->id, 'organizacao_id' => $this->empresa()->id, 'papel' => 'financeiro']);
    }

    public function test_admin_nao_convida_papel_de_escritorio_nem_prestador_de_outra_empresa(): void
    {
        $this->como('admin@gestaopj.local')->post(route('usuarios.store'), ['name' => 'X', 'email' => 'x@x.local', 'papel' => 'revisor'])
            ->assertSessionHasErrors('papel');
        $prestB = Prestador::where('organizacao_id', $this->empresa(self::B)->id)->sole();
        $this->como('admin@gestaopj.local')->post(route('usuarios.store'), ['name' => 'X', 'email' => 'x@x.local', 'papel' => 'prestador', 'prestador_id' => $prestB->id])
            ->assertSessionHasErrors('prestador_id');
    }

    public function test_recuperacao_de_senha(): void
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'admin@gestaopj.local'])->assertSessionHas('ok');
        $user = User::where('email', 'admin@gestaopj.local')->sole();
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'])
            ->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'nova-senha-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_recuperacao_nao_revela_se_o_email_existe(): void
    {
        $this->post(route('password.email'), ['email' => 'ninguem@x.local'])->assertSessionHas('ok')->assertSessionHasNoErrors();
    }
}
