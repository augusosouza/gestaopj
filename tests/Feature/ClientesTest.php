<?php

namespace Tests\Feature;

use App\Models\Cliente;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComContexto;
use Tests\TestCase;

class ClientesTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    public function test_cadastra_pj_alfanumerica_busca_e_isola_ficha(): void
    {
        $this->seed(DatabaseSeeder::class);
        $externo = Cliente::create([
            'organizacao_id' => $this->empresa(self::B)->id,
            'razao_social' => 'Cliente de outra organização',
            'documento' => '52998224725',
        ]);
        $this->como('admin@augi.local')->get(route('clientes.create'))->assertOk();
        $this->post(route('clientes.store'), [
            'razao_social' => 'Cliente Cadastro 51',
            'nome_fantasia' => 'Nome comercial 51',
            'documento' => '12.ABC.345/01DE-35',
            'telefone' => '(32) 3333-1234',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $cliente = Cliente::where('razao_social', 'Cliente Cadastro 51')->firstOrFail();
        $this->get(route('clientes.show', $cliente))->assertOk()
            ->assertSee('Nome comercial 51')->assertSee('Faturas (0)');
        $this->get(route('clientes.index', ['busca' => '12.ABC.345/01DE-35', 'tipo' => 'pj']))
            ->assertOk()->assertViewHas('clientes', fn ($clientes) => $clientes->total() === 1);
        $this->get(route('clientes.show', $externo))->assertNotFound();
        $this->assertDatabaseHas('eventos', ['acao' => 'cliente.criado', 'alvo_id' => $cliente->id]);
    }
}
