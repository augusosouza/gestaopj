<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Services\Financeiro;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ComContexto;
use Tests\TestCase;

class RadarEconomiaTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    public function test_compara_tarifas_ao_mesmo_volume_e_acompanha_acao_por_empresa(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 24));
        $this->seed(DatabaseSeeder::class);
        foreach ([self::A, self::B] as $cnpj) {
            $org = $this->empresa($cnpj)->id;
            $conta = ContaFinanceira::create(['organizacao_id' => $org, 'nome' => 'Conta', 'tipo' => 'corrente',
                'saldo_inicial_centavos' => 0, 'saldo_inicial_em' => '2026-09-01']);
            $financeiro = app(Financeiro::class);
            foreach (['2026-09-10' => [100000, 3000], '2026-10-10' => [200000, 4000]] as $data => [$bruto, $taxa]) {
                for ($i = 0; $i < 3; $i++) {
                    $titulo = $financeiro->criarTitulo($org, ['tipo' => 'receber', 'descricao' => 'Venda', 'competencia' => $data], $bruto, 1, $data);
                    $financeiro->baixar($titulo->parcelas()->sole(), [
                        'conta_id' => $conta->id, 'valor' => $bruto, 'tarifa' => $taxa, 'data' => $data,
                    ]);
                }
            }
        }
        $this->como('financeiro@augi.local')->get(route('inteligencia.economia'))->assertOk()
            ->assertViewHas('r', fn ($r) => $r['esperado'] === 18000 && $r['observado'] === 12000 && $r['diferenca'] === 6000);
        $this->post(route('inteligencia.economia.acompanhar'), ['base' => '2026-09', 'acao' => 'Tarifa renegociada com o banco'])
            ->assertRedirect(route('inteligencia.economia'));
        $this->get(route('inteligencia.economia'))->assertOk()->assertSee('Tarifa renegociada com o banco');
        $this->como('admin.b@augi.local', self::B)->get(route('inteligencia.economia'))->assertOk()->assertDontSee('Tarifa renegociada com o banco');
    }

    public function test_ia_exige_consentimento_respeita_limite_e_envia_so_sinais(): void
    {
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"acoes":["amostra"]}']]], 'usage' => ['total_tokens' => 42],
        ])]);
        $this->como('admin@augi.local')->put(route('configuracoes.inteligencia.ia'), [
            'ativa' => '1', 'limite' => 1, 'ia_radar_chave' => 'sk-ficticia-teste-radar',
        ])->assertSessionHasNoErrors();
        $raw = DB::table('organizacoes')->where('id', $this->empresa()->id)->value('ia_radar_chave');
        $this->assertNotEmpty($raw);
        $this->assertNotSame('sk-ficticia-teste-radar', $raw);
        $this->get(route('configuracoes.inteligencia'))->assertOk()->assertDontSee('sk-ficticia-teste-radar');
        $this->postJson(route('inteligencia.economia.orientar'))->assertUnprocessable();
        Http::assertNothingSent();
        $this->post(route('inteligencia.economia.orientar'), ['autorizar' => '1'])->assertOk()->assertSee('Reúna mais recebimentos');
        $this->post(route('inteligencia.economia.orientar'), ['autorizar' => '1'])->assertOk()->assertSee('limite mensal foi atingido');
        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $dados = json_decode($r['messages'][1]['content'], true);

            return array_keys($dados) === ['acoes_permitidas', 'comparacao_disponivel', 'tarifas_totais_subiram', 'custo_ajustado_caiu'];
        });
        $this->assertSame(42, Evento::where('acao', 'ia.radar_concluida')->sole()->dados['tokens']);
        $this->assertStringNotContainsString('sk-ficticia', Evento::where('acao', 'ia.radar_configurada')->sole()->toJson());
    }
}
