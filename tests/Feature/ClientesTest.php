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

    public function test_contatos_enderecos_e_historico_respeitam_o_cliente(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = $this->empresa();
        $cliente = Cliente::create(['organizacao_id' => $empresa->id, 'razao_social' => 'Cliente contatos', 'documento' => '52998224725']);
        $vizinho = Cliente::create(['organizacao_id' => $empresa->id, 'razao_social' => 'Outro cliente', 'documento' => '11144477735']);
        $this->como('admin@augi.local')->get(route('clientes.relacionamentos.form', [$cliente, 'contato']))->assertOk();
        $this->post(route('clientes.relacionamentos.salvar', [$cliente, 'contato']), [
            'ativo' => '1', 'finalidade' => 'financeiro', 'dados' => ['nome' => 'Contato de teste', 'email' => 'financeiro@example.test'],
        ])->assertRedirect(route('clientes.show', $cliente));
        $contato = $cliente->relacionamentos()->firstOrFail();
        $this->post(route('clientes.relacionamentos.salvar', [$vizinho, 'contato', $contato->id]), [
            'ativo' => '0', 'finalidade' => 'financeiro', 'dados' => ['nome' => 'Intruso'],
        ])->assertNotFound();
        $this->post(route('clientes.relacionamentos.salvar', [$cliente, 'endereco']), [
            'ativo' => '1', 'finalidade' => 'fiscal', 'dados' => ['logradouro' => 'Rua de teste', 'municipio' => 'Juiz de Fora', 'pais' => 'Brasil', 'uf' => 'MG'],
        ])->assertRedirect(route('clientes.show', $cliente));
        $this->post(route('clientes.relacionamentos.salvar', [$cliente, 'contato', $contato->id]), [
            'ativo' => '0', 'finalidade' => 'financeiro', 'dados' => ['nome' => 'Contato de teste', 'email' => 'financeiro@example.test'],
        ])->assertRedirect(route('clientes.show', $cliente));
        $this->get(route('clientes.show', $cliente))->assertOk()->assertSee('Rua de teste')->assertSee('Inativo');
        $this->get(route('clientes.historico', $cliente))->assertOk()->assertSee('Ficha consultada')
            ->assertSee('Contato ou endereço cadastrado')->assertSee('Contato ou endereço atualizado')->assertSee('Campos: ativo')
            ->assertDontSee('financeiro@example.test');
        $this->get(route('clientes.historico', $vizinho))->assertOk()->assertDontSee('Contato ou endereço atualizado');
    }

    public function test_retornar_da_ficha_preserva_os_filtros_validados(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cliente = Cliente::where('organizacao_id', $this->empresa()->id)->firstOrFail();
        $filtros = ['tipo' => 'pj', 'contratos' => 'com', 'ordem' => 'nome_desc'];
        $lista = route('clientes.index', $filtros);
        $this->como('admin@augi.local')->get($lista)->assertOk()->assertSee('Filtros avançados');
        $this->get(route('clientes.show', $cliente))->assertOk()->assertSee($lista);
        $this->get(route('clientes.historico', $cliente))->assertOk()->assertSee($lista);
        $this->get(route('desenvolvimento.componentes'))->assertNotFound();
    }
}
