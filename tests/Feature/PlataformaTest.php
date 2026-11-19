<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComContexto;
use Tests\TestCase;

class PlataformaTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->dono = User::where('email', 'admin@augi.local')->sole();
    }

    public function test_seed_define_nome_slogan_e_responsavel(): void
    {
        $this->get('/login')->assertSee('Augi')->assertSee('Gestão inteligente para negócios que evoluem.')
            ->assertSee('Desenvolvido por Augi Tecnologias');
    }

    public function test_admin_da_plataforma_troca_o_nome_e_vale_na_hora(): void
    {
        $this->actingAs($this->dono)->get(route('plataforma.configuracoes'))->assertOk()->assertSee('Augi Gestão', false);

        $this->actingAs($this->dono)->put(route('plataforma.salvar'), [
            'nome_sistema' => 'Nova Marca', 'slogan' => 'Outro slogan', 'empresa_responsavel' => '',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');

        $this->assertSame('Nova Marca', config('app.name'));
        $this->assertSame('Nova Marca', config('mail.from.name'));
        $this->post('/logout');
        $this->get('/login')->assertSee('<title>Entrar · Nova Marca</title>', false)
            ->assertSee('Outro slogan')->assertDontSee('Desenvolvido por');
        $this->como('financeiro@augi.local')->get(route('cobrancas.index'))->assertSee('Nova Marca');

        $this->assertDatabaseHas('eventos', ['acao' => 'plataforma.configuracao', 'user_id' => $this->dono->id]);
        $evento = Evento::where('acao', 'plataforma.configuracao')->sole();
        $this->assertSame(['de' => 'Augi Gestão', 'para' => 'Nova Marca'], $evento->dados['nome_sistema']);
        $this->assertSame(['de' => 'Augi Tecnologias', 'para' => null], $evento->dados['empresa_responsavel']);
    }

    public function test_salvar_sem_mudancas_nao_gera_auditoria(): void
    {
        $this->actingAs($this->dono)->put(route('plataforma.salvar'), [
            'nome_sistema' => 'Augi Gestão', 'slogan' => 'Gestão inteligente para negócios que evoluem.', 'empresa_responsavel' => 'Augi Tecnologias',
        ])->assertSessionHas('ok', 'Nada foi alterado.');
        $this->assertDatabaseMissing('eventos', ['acao' => 'plataforma.configuracao']);
    }

    public function test_validacao(): void
    {
        $this->actingAs($this->dono)->put(route('plataforma.salvar'), ['nome_sistema' => ''])->assertSessionHasErrors('nome_sistema');
        $this->actingAs($this->dono)->put(route('plataforma.salvar'), ['nome_sistema' => str_repeat('x', 41)])->assertSessionHasErrors('nome_sistema');
        $this->assertSame('Augi Gestão', config('app.name'));
    }

    public function test_so_o_admin_da_plataforma_acessa(): void
    {
        $this->get(route('plataforma.configuracoes'))->assertRedirect('/login');
        foreach (['admin.b@augi.local', 'financeiro@augi.local', 'revisor@augi.local', 'prestador@augi.local'] as $email) {
            $u = User::where('email', $email)->sole();
            $this->actingAs($u)->get(route('plataforma.configuracoes'))->assertForbidden();
            $this->actingAs($u)->put(route('plataforma.salvar'), ['nome_sistema' => 'Invasor'])->assertForbidden();
        }
        $this->assertSame('Augi Gestão', config('app.name'));
    }

    public function test_exige_2fa(): void
    {
        config(['gestao.exigir_2fa' => true]);
        $this->actingAs($this->dono)->get(route('plataforma.configuracoes'))->assertRedirect(route('seguranca'));
    }

    public function test_comando_concede_e_remove_o_perfil(): void
    {
        $this->artisan('gestao:admin-plataforma', ['email' => 'revisor@augi.local'])->assertSuccessful();
        $this->assertTrue(User::where('email', 'revisor@augi.local')->sole()->admin_plataforma);
        $this->artisan('gestao:admin-plataforma', ['email' => 'revisor@augi.local', '--remover' => true])->assertSuccessful();
        $this->assertFalse(User::where('email', 'revisor@augi.local')->sole()->admin_plataforma);
    }
}
