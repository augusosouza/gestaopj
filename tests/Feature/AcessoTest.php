<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Cobranca;
use App\Models\Prestador;
use App\Models\User;
use App\Services\Faturamento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcessoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::parse('2026-10-01'));
    }

    private function usuario(Papel $p): User
    {
        return User::where('papel', $p)->first();
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Entrar');
    }

    public function test_login_e_logout(): void
    {
        $this->post('/login', ['email' => 'admin@gestaopj.local', 'password' => 'piloto-2026'])->assertRedirect(route('painel'));
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_senha_errada_nao_entra(): void
    {
        $this->post('/login', ['email' => 'admin@gestaopj.local', 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_prestador_ve_so_as_proprias_cobrancas(): void
    {
        $outro = Prestador::create(['nome' => 'Outro', 'tipo' => 'PJ', 'documento' => '11444777000161']);
        $cob = Cobranca::sole();
        $userOutro = User::create(['name' => 'Outro', 'email' => 'outro@x.local', 'password' => 'xxxxxxxxxx', 'papel' => Papel::Prestador, 'prestador_id' => $outro->id]);

        $this->actingAs($this->usuario(Papel::Prestador))->get(route('cobrancas.show', $cob))->assertOk()->assertSee('R$ 7.500,00');
        $this->actingAs($userOutro)->get(route('cobrancas.show', $cob))->assertNotFound();
        $this->actingAs($userOutro)->post(route('cobrancas.nota', $cob), ['chave_acesso' => str_repeat('1', 50)])->assertNotFound();
    }

    public function test_prestador_nao_acessa_areas_internas(): void
    {
        $p = $this->usuario(Papel::Prestador);
        foreach (['faturas.index', 'cobrancas.index', 'notas.index', 'contratos.index', 'clientes.index', 'usuarios.index'] as $r) {
            $this->actingAs($p)->get(route($r))->assertForbidden();
        }
        $this->actingAs($p)->post(route('cobrancas.aprovar', Cobranca::sole()))->assertForbidden();
        $this->actingAs($p)->get('/')->assertOk()->assertSee('CT-2026-001');
    }

    public function test_contabilidade_le_mas_nao_aprova_pagamentos(): void
    {
        $c = $this->usuario(Papel::Contabilidade);
        $this->actingAs($c)->get(route('cobrancas.index', ['competencia' => '2026-10']))->assertOk();
        $this->actingAs($c)->post(route('cobrancas.aprovar', Cobranca::sole()))->assertForbidden();
        $this->actingAs($c)->get(route('clientes.index'))->assertForbidden();
    }

    public function test_todas_as_telas_internas_renderizam(): void
    {
        $a = $this->usuario(Papel::Admin);
        $cob = Cobranca::sole();
        foreach ([
            route('painel', ['competencia' => '2026-10']), route('faturas.index', ['competencia' => '2026-10']),
            route('faturas.show', $cob->contrato->faturas()->first()), route('cobrancas.index', ['competencia' => '2026-10']),
            route('cobrancas.show', $cob), route('notas.index'), route('contratos.index'), route('contratos.create'),
            route('contratos.show', $cob->contrato), route('contratos.edit', $cob->contrato), route('perfis.index'),
            route('clientes.index'), route('prestadores.index'), route('empresas.index'), route('usuarios.index'),
        ] as $url) {
            $this->actingAs($a)->get($url)->assertOk();
        }
    }

    public function test_admin_cadastra_contrato_e_aloca_prestador(): void
    {
        $a = $this->usuario(Papel::Admin);
        $this->actingAs($a)->post(route('contratos.store'), [
            'empresa_id' => 1, 'cliente_id' => 1, 'numero' => 'CT-2', 'descricao' => 'Consultoria',
            'valor_mensal' => '5.000,00', 'dia_vencimento' => 5, 'inicio' => '2026-11-01', 'status' => 'ativo',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contratos', ['numero' => 'CT-2', 'valor_mensal_centavos' => 500000]);

        $this->actingAs($a)->post(route('prestadores.store'), ['nome' => 'X', 'tipo' => 'PJ', 'documento' => '11.222.333/0001-82'])
            ->assertSessionHasErrors('documento');
    }
}
