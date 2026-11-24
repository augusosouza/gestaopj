<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Fatura;
use App\Models\VisaoSalva;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComContexto;
use Tests\TestCase;

class BuscaTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    public function test_pesquisa_documento_formatado_com_isolamento_e_permissoes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cliente = Cliente::create(['organizacao_id' => $this->empresa()->id,
            'razao_social' => 'Empresa Pesquisa Alfa', 'documento' => '12ABC34501DE35']);
        $externo = Cliente::create(['organizacao_id' => $this->empresa(self::B)->id,
            'razao_social' => 'Empresa Pesquisa Externa', 'documento' => '12ABC34501DE35']);

        $this->como('admin@augi.local')->post(route('busca'), ['q' => '12.ABC.345/01DE-35'])
            ->assertOk()->assertSee(route('clientes.show', $cliente))->assertDontSee(route('clientes.show', $externo))
            ->assertDontSee('Empresa Pesquisa Externa');
        $this->post(route('busca'), ['q' => 'Empresa Pesquisa'])->assertOk()
            ->assertSee('Empresa Pesquisa Alfa')->assertDontSee('Empresa Pesquisa Externa');
        $this->post(route('busca'), ['q' => '%_'])->assertOk()->assertSee('Nenhum resultado encontrado');
        $this->como('financeiro@augi.local')->post(route('busca'), ['q' => 'Empresa Pesquisa Alfa'])
            ->assertOk()->assertDontSee(route('clientes.show', $cliente));
        $this->como('prestador@augi.local')->post(route('busca'), ['q' => 'Relatórios'])->assertForbidden();
    }

    public function test_busca_de_faturas_respeita_empresa_competencia_e_situacao(): void
    {
        $this->seed(DatabaseSeeder::class);
        $faturas = [];
        foreach ([self::A, self::B] as $cnpj) {
            $org = $this->empresa($cnpj)->id;
            $contrato = Contrato::where('organizacao_id', $org)->firstOrFail();
            $faturas[] = Fatura::create(['organizacao_id' => $org, 'contrato_id' => $contrato->id,
                'competencia' => '2026-11-01', 'vencimento' => '2026-12-10', 'valor_centavos' => 100, 'status' => 'aberta']);
        }
        [$local, $externa] = $faturas;
        $this->como('financeiro@augi.local')->post(route('busca'), ['q' => '#'.$externa->id])
            ->assertOk()->assertDontSee(route('faturas.show', $externa));
        $this->post(route('busca'), ['q' => '#'.$local->id])->assertOk()->assertSee(route('faturas.show', $local));
        $this->get(route('faturas.index', ['q' => '#'.$local->id, 'competencia' => '2026-11', 'situacao' => 'aberta']))
            ->assertOk()->assertSee(route('faturas.show', $local));
        $this->get(route('faturas.index', ['q' => '#'.$local->id, 'competencia' => '2026-11', 'situacao' => 'cancelada']))
            ->assertOk()->assertDontSee(route('faturas.show', $local));
    }

    public function test_visao_salva_pertence_ao_usuario_e_nao_aceita_filtros_arbitrarios(): void
    {
        $this->seed(DatabaseSeeder::class);
        $filtros = ['situacao' => 'aberta', 'competencia' => '2026-11'];
        $this->como('admin@augi.local')->post(route('visoes.salvar', 'faturas'), [
            'nome' => 'Faturas do mês em aberto', 'filtros' => $filtros,
        ])->assertRedirect(route('faturas.index', $filtros));
        $visao = VisaoSalva::query()->where('nome', 'Faturas do mês em aberto')->firstOrFail();
        $this->get(route('faturas.index'))->assertOk()->assertSee('Faturas do mês em aberto');
        $this->postJson(route('visoes.salvar', 'faturas'), [
            'nome' => 'Insegura', 'filtros' => ['organizacao_id' => $this->empresa(self::B)->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('filtros');

        $this->como('financeiro@augi.local')->get(route('faturas.index'))
            ->assertOk()->assertDontSee('Faturas do mês em aberto');
        $this->delete(route('visoes.remover', ['faturas', $visao]))->assertNotFound();
        $this->como('admin@augi.local')->delete(route('visoes.remover', ['faturas', $visao]))->assertRedirect();
        $this->assertModelMissing($visao);
    }
}
