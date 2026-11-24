<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BaseConhecimento;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComContexto;
use Tests\TestCase;

class ConhecimentoTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_base_exige_login_e_abre_sem_empresa_ativa(): void
    {
        $url = route('conhecimento.index');
        $this->get($url)->assertRedirect(route('login'));

        $this->actingAs(User::where('email', 'admin@augi.local')->sole())->get($url)
            ->assertOk()->assertSee('Como adicionar uma empresa')->assertSee('Como criar usuário e conceder acesso');
        $this->get(route('conhecimento.artigo', 'nao-existe'))->assertNotFound();
    }

    public function test_busca_sem_acento_combina_termos_e_filtra_modulo(): void
    {
        $this->como('financeiro@augi.local')->get(route('conhecimento.index', ['q' => 'CRIAR USUARIO', 'categoria' => 'equipe']))
            ->assertOk()->assertSee('Como criar usuário e conceder acesso')->assertDontSee('Como adicionar uma empresa');
        $this->get(route('conhecimento.index', ['q' => 'assunto inexistente']))
            ->assertOk()->assertSee('Nenhum guia encontrado');
    }

    public function test_atalho_respeita_contexto_e_papel_e_a_tela_oferece_ajuda(): void
    {
        $guia = route('conhecimento.artigo', 'criar-usuario');
        $this->como('financeiro@augi.local')->get($guia)->assertOk()->assertDontSee('Abrir tela no Augi');
        $this->como('admin@augi.local')->get($guia)->assertOk()->assertSee('Abrir tela no Augi')->assertSee(route('usuarios.index'));
        $this->get(route('usuarios.index'))->assertOk()->assertSee('Ajuda desta tela')->assertSee($guia);
    }

    public function test_catalogo_abre_todos_os_guias_e_destinos_existem(): void
    {
        $this->como('admin@augi.local');
        foreach (BaseConhecimento::artigos() as $slug => $artigo) {
            $this->get(route('conhecimento.artigo', $slug))->assertOk()->assertSee($artigo['titulo']);
        }
    }
}
