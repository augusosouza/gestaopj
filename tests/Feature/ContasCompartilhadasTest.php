<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Movimento;
use App\Models\Parcela;
use App\Models\Titulo;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ComContexto;
use Tests\TestCase;

class ContasCompartilhadasTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 20));
        $this->seed(DatabaseSeeder::class);
        DB::table('titulos')->update(['cancelado_em' => now()]);
    }

    private function parcela(string $cnpj, string $tipo, string $descricao, string $competencia = '2026-11-01'): Parcela
    {
        $empresa = $this->empresa($cnpj);
        $titulo = Titulo::create(['organizacao_id' => $empresa->id, 'tipo' => $tipo, 'descricao' => $descricao,
            'competencia' => $competencia, 'valor_total_centavos' => 10000]);

        return $titulo->parcelas()->create(['organizacao_id' => $empresa->id, 'numero' => 1, 'valor_centavos' => 10000, 'vencimento' => '2026-11-20']);
    }

    public function test_abertas_paginam_sem_alterar_totais_e_isolam_empresa(): void
    {
        foreach (range(1, 26) as $numero) {
            $this->parcela(self::A, 'pagar', 'Conta aberta '.$numero);
        }
        $this->parcela(self::A, 'pagar', 'Outra competência', '2026-10-01');
        $this->parcela(self::B, 'pagar', 'Conta de outra empresa');

        $rota = route('contas_pagar.index', ['aba' => 'aberto', 'competencia' => '2026-11', 'ate' => '2026-11-20']);
        $this->como('financeiro@augi.local')->get($rota)->assertOk()
            ->assertViewHas('linhas', fn ($linhas) => $linhas->count() === 25 && $linhas->total() === 26)
            ->assertViewHas('totalLinhas', 260000)->assertDontSee('Conta aberta 26')
            ->assertDontSee('Conta de outra empresa')->assertDontSee('Outra competência');

        $this->get($rota.'&page=2')->assertOk()->assertSee('Conta aberta 26')
            ->assertViewHas('linhas', fn ($linhas) => $linhas->count() === 1 && $linhas->total() === 26)
            ->assertViewHas('totalLinhas', 260000);
    }

    public function test_recebimentos_excluem_estornos_e_totalizam_todas_as_paginas(): void
    {
        $empresa = $this->empresa();
        $conta = ContaFinanceira::create(['organizacao_id' => $empresa->id, 'nome' => 'Banco teste', 'tipo' => 'corrente',
            'saldo_inicial_centavos' => 0, 'saldo_inicial_em' => '2026-11-01']);
        $parcela = $this->parcela(self::A, 'receber', 'Mensalidade ACME');
        $dados = ['organizacao_id' => $empresa->id, 'parcela_id' => $parcela->id, 'conta_id' => $conta->id,
            'tipo' => 'baixa', 'data' => '2026-11-20', 'valor_centavos' => 100];
        foreach (range(1, 27) as $numero) {
            $baixa = Movimento::create($dados);
        }
        Movimento::create([...$dados, 'tipo' => 'estorno', 'estorno_de_id' => $baixa->id]);

        foreach ([[], ['fornecedor' => 'acme']] as $busca) {
            $rota = route('contas_receber.index', ['aba' => 'pagas', 'ate' => '2026-11-20', 'page' => 2] + $busca);
            $this->como('financeiro@augi.local')->get($rota)->assertOk()
                ->assertViewHas('pagas', fn ($pagas) => $pagas->total() === 26 && $pagas->count() === 1)
                ->assertViewHas('totalPagas', 2600)->assertViewHas('ind', fn ($ind) => $ind['pago_mes'] === 2600);
        }
    }

    public function test_previa_da_baixa_rapida_preserva_saldo_e_confirma_uma_vez(): void
    {
        $empresa = $this->empresa();
        $conta = ContaFinanceira::create(['organizacao_id' => $empresa->id, 'nome' => 'Banco baixa rápida', 'tipo' => 'corrente',
            'saldo_inicial_centavos' => 0, 'saldo_inicial_em' => '2026-11-01']);
        $parcela = $this->parcela(self::A, 'pagar', 'Conta em conferência');
        $previa = $this->como('financeiro@augi.local')->post(route('financeiro.baixa.previa', $parcela), [
            'conta_id' => $conta->id, 'data' => '2026-11-20', 'valor' => '40,00',
        ])->assertOk()->assertSee('R$ 60,00')->assertSee('Banco baixa rápida');
        $this->assertSame(0, $parcela->movimentos()->count());

        $url = route('financeiro.baixa.confirmar', $parcela);
        $dados = ['confirmacao' => $previa->viewData('confirmacao')];
        $this->como('admin@augi.local')->post($url, $dados)->assertUnprocessable();
        $this->como('financeiro@augi.local')->post($url, $dados)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($url, $dados)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, $parcela->movimentos()->count());
        $this->assertSame(6000, $parcela->fresh()->saldo());
    }
}
