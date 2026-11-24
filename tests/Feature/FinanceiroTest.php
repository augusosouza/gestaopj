<?php

namespace Tests\Feature;

use App\Enums\EtapaContabil;
use App\Enums\StatusCobranca;
use App\Enums\StatusFatura;
use App\Models\Cobranca;
use App\Models\CompetenciaContabil;
use App\Models\ContaFinanceira;
use App\Models\ExtratoLinha;
use App\Models\Fatura;
use App\Models\Movimento;
use App\Models\Parcela;
use App\Models\Titulo;
use App\Services\Faturamento;
use App\Services\Financeiro;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ComContexto;
use Tests\Support\NfseXml;
use Tests\TestCase;

/** FIN-01 — Fundação financeira. */
class FinanceiroTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private ContaFinanceira $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
        $this->como('financeiro@augi.local')->post(route('financeiro.conta_salvar'), [
            'nome' => 'Banco X', 'tipo' => 'corrente', 'saldo_inicial' => '10.000,00', 'saldo_inicial_em' => '2026-11-01',
        ])->assertSessionHasNoErrors();
        $this->conta = ContaFinanceira::sole();
    }

    private function novaDespesa(string $valor = '3.400,00', int $parcelas = 1): Titulo
    {
        $this->como('financeiro@augi.local')->post(route('financeiro.store'), [
            'tipo' => 'pagar', 'descricao' => 'Aluguel', 'competencia' => '2026-11', 'valor' => $valor,
            'parcelas' => $parcelas, 'vencimento' => '2026-11-10',
        ])->assertSessionHasNoErrors();

        return Titulo::where('descricao', 'Aluguel')->latest('id')->firstOrFail();
    }

    private function baixar(int $parcelaId, string $valor, string $data = '2026-11-15', array $extra = []): TestResponse
    {
        return $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $parcelaId),
            ['conta_id' => $this->conta->id, 'data' => $data, 'valor' => $valor] + $extra);
    }

    /** Critério de aceite: registrar, pagar parcialmente, conciliar, estornar e obter o saldo correto sem alterar o histórico. */
    public function test_criterio_de_aceite_fin_01(): void
    {
        // 1. Obrigação de R$ 3.400 (competência 11/2026, vencimento 10/11)
        $t = $this->novaDespesa();
        $p = $t->parcelas()->sole();
        $this->assertSame('2026-11-01', $t->competencia->toDateString());
        $this->assertSame('2026-11-10', $p->vencimento->toDateString());

        // 2. Pagamento parcial de R$ 2.000 em 15/11 (liquidação)
        $this->baixar($p->id, '2.000,00')->assertSessionHasNoErrors();
        $t->refresh()->load('parcelas.movimentos.estornoDe');
        $this->assertSame(140000, $t->saldo());
        $this->assertSame('parcial', $t->situacao());
        $this->assertSame(800000, $this->conta->saldo());

        // 3. Extrato do banco traz a saída de R$ 2.000; concilia
        $ofx = "OFXHEADER:100\n<OFX><BANKTRANLIST>\n<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261115<TRNAMT>-2000.00<FITID>A1<MEMO>PIX ALUGUEL\n</STMTTRN></BANKTRANLIST></OFX>";
        $this->como('financeiro@augi.local')->post(route('financeiro.importar', $this->conta), ['arquivo' => UploadedFile::fake()->createWithContent('extrato.ofx', $ofx)])
            ->assertSessionHasNoErrors();
        $linha = ExtratoLinha::sole();
        $this->assertSame(-200000, $linha->valor_centavos);
        $baixa = Movimento::where('tipo', 'baixa')->sole();
        $this->assertTrue(app(Financeiro::class)->sugestoes($linha)->contains('id', $baixa->id));
        $this->como('financeiro@augi.local')->post(route('financeiro.conciliar', $linha), ['movimento_id' => $baixa->id])->assertSessionHasNoErrors();

        // 4. Estorno exige desfazer a conciliação antes
        $this->como('financeiro@augi.local')->post(route('financeiro.estornar', $baixa), ['motivo' => 'Pago na conta errada'])->assertSessionHasErrors('motivo');
        $this->como('financeiro@augi.local')->post(route('financeiro.desconciliar', $linha))->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00'));
        $this->como('financeiro@augi.local')->post(route('financeiro.estornar', $baixa), ['motivo' => 'Pago na conta errada'])->assertSessionHasNoErrors();

        // 5. Saldos corretos e histórico preservado
        $t->refresh()->load('parcelas.movimentos.estornoDe');
        $this->assertSame(340000, $t->saldo());
        $this->assertSame('aberto', $t->situacao());
        $this->assertSame(1_000_000, $this->conta->saldo(), 'saldo hoje volta ao inicial');
        $this->assertSame(800000, $this->conta->saldo(CarbonImmutable::parse('2026-11-20')), 'saldo de um dia passado não muda com o estorno');
        $this->assertSame(2, Movimento::count(), 'baixa original + estorno');
        $this->assertSame(200000, $baixa->fresh()->valor_centavos, 'baixa original intacta');
        $this->assertSame($baixa->id, Movimento::where('tipo', 'estorno')->sole()->estorno_de_id);
        $this->assertDatabaseHas('eventos', ['acao' => 'financeiro.estorno']);
    }

    public function test_movimento_e_imutavel(): void
    {
        $t = $this->novaDespesa();
        $this->baixar($t->parcelas()->sole()->id, '100,00');
        $m = Movimento::sole();
        $this->expectException(\LogicException::class);
        $m->update(['valor_centavos' => 1]);
    }

    public function test_nao_paga_mais_que_o_saldo_nem_com_data_futura(): void
    {
        $p = $this->novaDespesa()->parcelas()->sole();
        $this->baixar($p->id, '3.400,01')->assertSessionHasErrors('valor');
        $this->baixar($p->id, '100,00', '2026-12-01')->assertSessionHasErrors('data');
        $this->baixar($p->id, '3.000,00', '2026-11-15', ['desconto' => '400,00'])->assertSessionHasNoErrors();
        $this->baixar($p->id, '0,01')->assertSessionHasErrors('valor');
        $this->assertSame('liquidado', $p->titulo->fresh()->load('parcelas.movimentos.estornoDe')->situacao());
    }

    public function test_duplo_envio_nao_duplica_baixa(): void
    {
        $p = $this->novaDespesa()->parcelas()->sole();
        $this->baixar($p->id, '500,00', '2026-11-15', ['chave' => 'form-123']);
        $this->baixar($p->id, '500,00', '2026-11-15', ['chave' => 'form-123']);
        $this->assertSame(1, Movimento::count());
    }

    public function test_parcelamento_divide_centavos_e_competencia_separada_do_vencimento(): void
    {
        $t = $this->novaDespesa('1.000,00', 3);
        $this->assertSame([33333, 33333, 33334], $t->parcelas->pluck('valor_centavos')->all());
        $this->assertSame(['2026-11-10', '2026-12-10', '2027-01-10'], $t->parcelas->map(fn ($p) => $p->vencimento->toDateString())->all());
        $this->assertSame('2026-11-01', $t->competencia->toDateString());
    }

    public function test_mes_fechado_pelo_contador_bloqueia_movimento(): void
    {
        $p = $this->novaDespesa()->parcelas()->sole();
        CompetenciaContabil::create(['organizacao_id' => $this->empresa()->id, 'competencia' => '2026-11-01', 'etapa' => EtapaContabil::Fechada]);
        $this->baixar($p->id, '100,00')->assertSessionHasErrors('data');
        $this->como('financeiro@augi.local')->post(route('financeiro.store'), [
            'tipo' => 'pagar', 'descricao' => 'X', 'competencia' => '2026-11', 'valor' => '10,00', 'parcelas' => 1, 'vencimento' => '2026-11-10',
        ])->assertSessionHasErrors('competencia');
    }

    public function test_cancelar_so_sem_baixas_ativas(): void
    {
        $t = $this->novaDespesa();
        $this->baixar($t->parcelas()->sole()->id, '100,00');
        $this->como('financeiro@augi.local')->post(route('financeiro.cancelar', $t), ['motivo' => 'erro'])->assertSessionHasErrors('motivo');
        $this->como('financeiro@augi.local')->post(route('financeiro.estornar', Movimento::sole()), ['motivo' => 'erro']);
        $this->como('financeiro@augi.local')->post(route('financeiro.cancelar', $t), ['motivo' => 'lançado em duplicidade'])->assertSessionHasNoErrors();
        $this->assertSame('cancelado', $t->fresh()->situacao());
    }

    public function test_reimportar_extrato_nao_duplica_e_csv_funciona(): void
    {
        $csv = "data;descricao;valor\n15/11/2026;TARIFA PACOTE;-45,90\n15/11/2026;TARIFA PACOTE;-45,90\n16/11/2026;PIX RECEBIDO CLIENTE;1.200,00\n";
        $arq = fn () => UploadedFile::fake()->createWithContent('extrato.csv', $csv);
        $this->como('financeiro@augi.local')->post(route('financeiro.importar', $this->conta), ['arquivo' => $arq()])->assertSessionHas('ok');
        $this->assertSame(3, ExtratoLinha::count(), 'duas tarifas iguais no mesmo dia são lançamentos distintos');
        $this->como('financeiro@augi.local')->post(route('financeiro.importar', $this->conta), ['arquivo' => $arq()]);
        $this->assertSame(3, ExtratoLinha::count());
        $this->assertSame([-4590, -4590, 120000], ExtratoLinha::orderBy('id')->pluck('valor_centavos')->all());
    }

    public function test_conciliacao_recusa_valor_ou_sentido_diferente(): void
    {
        $p = $this->novaDespesa()->parcelas()->sole();
        $this->baixar($p->id, '500,00');
        $linha = ExtratoLinha::create(['conta_id' => $this->conta->id, 'organizacao_id' => $this->empresa()->id,
            'data' => '2026-11-15', 'valor_centavos' => 50000, 'descricao' => 'entrada', 'identificador' => 'x']);
        $this->como('financeiro@augi.local')->post(route('financeiro.conciliar', $linha), ['movimento_id' => Movimento::sole()->id])
            ->assertSessionHasErrors('movimento_id');
    }

    public function test_recebimento_bruto_tarifa_e_credito_liquido_preservam_classificacao_e_estorno(): void
    {
        $parcela = $this->recebivelParaTarifa();
        $resumo = $this->como('financeiro@augi.local')->post(route('financeiro.baixa.previa', $parcela), [
            'conta_id' => $this->conta->id, 'data' => '2026-11-15', 'valor' => '1000,00', 'tarifa' => '30,00',
        ])->assertOk()->assertSee('R$ 970,00');
        $this->assertDatabaseCount('movimentos', 0);
        $dados = ['confirmacao' => $resumo->viewData('confirmacao')];
        $this->post(route('financeiro.baixa.confirmar', $parcela), $dados)->assertSessionHasNoErrors();
        $this->post(route('financeiro.baixa.confirmar', $parcela), $dados)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('movimentos', 2);
        $mov = $parcela->movimentos()->sole();
        $this->assertSame(100000, $mov->valor_centavos);
        $this->assertSame(97000, $mov->valorConciliavel());
        $this->assertSame(3000, $mov->tarifa->valor_centavos);
        $this->assertSame('tarifas', $mov->tarifa->parcela->titulo->categoria->chave);
        $this->assertSame(0, $parcela->fresh()->saldo());
        $this->assertSame(1097000, $this->conta->saldo());

        $credito = $this->extratoParaTarifa(97000, 'credito-liquido');
        $debito = $this->extratoParaTarifa(-3000, 'tarifa-ja-embutida');
        $this->get(route('financeiro.conciliacao', $this->conta))->assertOk()->assertSee('Bruto R$ 1.000,00');
        $this->post(route('financeiro.conciliar', $credito), ['movimento_id' => $mov->id])->assertSessionHasNoErrors();
        $this->post(route('financeiro.conciliar', $debito), ['movimento_id' => $mov->tarifa->id])->assertSessionHasErrors('movimento_id');
        $this->post(route('financeiro.estornar', $mov), ['motivo' => 'Correção'])->assertSessionHasErrors('motivo');

        app(Financeiro::class)->desconciliar($credito->fresh());
        $this->post(route('financeiro.estornar', $mov), ['motivo' => 'Correção'])->assertSessionHasNoErrors();
        $this->assertSame(100000, $parcela->fresh()->saldo());
        $this->assertSame(1000000, $this->conta->saldo());
        $this->assertTrue($mov->tarifa->parcela->titulo->fresh()->cancelado());
        $this->assertDatabaseCount('movimentos', 4);
    }

    #[DataProvider('datasDebitoTarifa')]
    public function test_debito_de_tarifa_em_linha_separada_concilia_individualmente(string $dataTarifa, int $saldoPrimeiroDia): void
    {
        $parcela = $this->recebivelParaTarifa();
        $mov = app(Financeiro::class)->baixar($parcela, [
            'conta_id' => $this->conta->id, 'data' => '2026-11-15', 'valor' => 100000,
            'tarifa' => 3000, 'tarifa_data' => $dataTarifa,
        ]);
        $credito = $this->extratoParaTarifa(100000, 'credito-bruto');
        $debito = $this->extratoParaTarifa(-3000, 'tarifa-separada', $dataTarifa);
        $this->como('financeiro@augi.local')->post(route('financeiro.conciliar', $credito), ['movimento_id' => $mov->id])->assertSessionHasNoErrors();
        $this->post(route('financeiro.conciliar', $debito), ['movimento_id' => $mov->tarifa->id])->assertSessionHasNoErrors();
        $this->assertSame($saldoPrimeiroDia, $this->conta->saldo(CarbonImmutable::parse('2026-11-15')));
        $this->assertSame(1097000, $this->conta->saldo(CarbonImmutable::parse('2026-11-16')));
    }

    public static function datasDebitoTarifa(): array
    {
        return ['mesma data' => ['2026-11-15', 1097000], 'data diferente' => ['2026-11-16', 1100000]];
    }

    private function recebivelParaTarifa(): Parcela
    {
        return app(Financeiro::class)->criarTitulo($this->empresa()->id, [
            'tipo' => 'receber', 'descricao' => 'Venda de teste', 'competencia' => '2026-11-01',
        ], 100000, 1, '2026-11-15')->parcelas()->sole();
    }

    private function extratoParaTarifa(int $valor, string $identificador, string $data = '2026-11-15'): ExtratoLinha
    {
        return ExtratoLinha::create(['conta_id' => $this->conta->id, 'organizacao_id' => $this->empresa()->id,
            'data' => $data, 'valor_centavos' => $valor, 'descricao' => 'Recebimento de teste', 'identificador' => $identificador]);
    }

    public function test_fatura_e_cobranca_viram_titulos_e_aceitam_parcial(): void
    {
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::parse('2026-10-01'));
        $a = $this->empresa()->id;
        $fatura = Fatura::where('organizacao_id', $a)->sole();
        $cob = Cobranca::where('organizacao_id', $a)->sole();
        $this->assertSame(2, Titulo::where('organizacao_id', $a)->count());
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::parse('2026-10-01'));
        $this->assertSame(2, Titulo::where('organizacao_id', $a)->count(), 'gerar de novo não duplica títulos');
        $this->assertSame('2026-11-15', Titulo::where('origem_type', $cob->getMorphClass())->where('origem_id', $cob->id)->sole()->parcelas()->sole()->vencimento->toDateString());

        // Fatura com nota: recebimento parcial mantém "Emitida"; o segundo quita
        $this->como('financeiro@augi.local')->post(route('faturas.nota', $fatura), ['xml' => UploadedFile::fake()->createWithContent('n.xml',
            NfseXml::gerar(['emitente' => '11222333000181', 'tomador' => '11444777000161', 'valor' => '12000.00', 'competencia' => '2026-10-01']))]);
        $this->como('financeiro@augi.local')->post(route('faturas.receber', $fatura), ['valor' => '5.000,00', 'data' => '2026-11-10', 'conta_id' => $this->conta->id]);
        $this->assertSame(StatusFatura::Emitida, $fatura->fresh()->status);
        $this->assertSame(500000, $fatura->fresh()->valor_recebido_centavos);
        $this->como('financeiro@augi.local')->post(route('faturas.receber', $fatura), ['valor' => '7.000,00', 'data' => '2026-11-12', 'conta_id' => $this->conta->id]);
        $this->assertSame(StatusFatura::Recebida, $fatura->fresh()->status);
        $this->assertSame('2026-11-12', $fatura->fresh()->recebida_em->toDateString());

        // Cobrança: retenção abate sem sair da conta
        $cob->update(['status' => StatusCobranca::Aprovada]);
        $this->como('financeiro@augi.local')->post(route('cobrancas.pagar', $cob), ['valor' => '7.000,00', 'retencao' => '500,00', 'data' => '2026-11-15', 'conta_id' => $this->conta->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatusCobranca::Paga, $cob->fresh()->status);
        $this->assertSame(1_000_000 + 1_200_000 - 700_000, $this->conta->saldo());

        // Estornar o recebimento final reabre a fatura
        $ultima = Movimento::where('valor_centavos', 700000)->whereHas('parcela.titulo', fn ($q) => $q->where('tipo', 'receber'))->sole();
        $this->como('financeiro@augi.local')->post(route('financeiro.estornar', $ultima), ['motivo' => 'devolvido']);
        $this->assertSame(StatusFatura::Emitida, $fatura->fresh()->status);
        $this->assertNull($fatura->fresh()->recebida_em);
    }

    public function test_sincronizar_legado_e_idempotente(): void
    {
        $a = $this->empresa();
        app(Faturamento::class)->gerarCompetencia(CarbonImmutable::parse('2026-10-01'));
        // Simula registro antigo: cobrança paga direto na tabela, sem título
        $cob = Cobranca::where('organizacao_id', $a->id)->sole();
        foreach (Titulo::where('origem_type', $cob->getMorphClass())->where('origem_id', $cob->id)->get() as $t) {
            $t->parcelas()->delete();
            $t->delete();
        }
        $cob->update(['status' => StatusCobranca::Paga, 'valor_pago_centavos' => 701250, 'paga_em' => '2026-11-15']);

        $n = app(Financeiro::class)->sincronizarLegado();
        $this->assertGreaterThanOrEqual(1, $n['titulos']);
        $t = Titulo::where('origem_type', $cob->getMorphClass())->where('origem_id', $cob->id)->sole()->load('parcelas.movimentos.estornoDe');
        $this->assertSame(0, $t->saldo(), 'valor pago + retenção quitam a cobrança');
        $this->assertSame(['titulos' => 0, 'baixas' => 0], app(Financeiro::class)->sincronizarLegado());
    }

    public function test_permissoes_e_isolamento(): void
    {
        $p = $this->novaDespesa()->parcelas()->sole();
        $this->como('contabilidade@augi.local')->get(route('financeiro.index', ['competencia' => '2026-11']))->assertOk()->assertSee('Aluguel');
        $this->como('contabilidade@augi.local')->post(route('financeiro.baixar', $p), ['conta_id' => $this->conta->id, 'data' => '2026-11-15', 'valor' => '1,00'])->assertForbidden();
        $this->como('prestador@augi.local')->get(route('financeiro.index'))->assertForbidden();
        $this->como('admin.b@augi.local', self::B)->get(route('financeiro.titulo', $p->titulo_id))->assertNotFound();
        $this->como('admin.b@augi.local', self::B)->get(route('financeiro.conciliacao', $this->conta))->assertNotFound();
        // Conta de outra empresa não serve para baixa
        $contaB = ContaFinanceira::create(['organizacao_id' => $this->empresa(self::B)->id, 'nome' => 'B', 'tipo' => 'corrente', 'saldo_inicial_em' => '2026-11-01']);
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $p), ['conta_id' => $contaB->id, 'data' => '2026-11-15', 'valor' => '1,00'])->assertSessionHasErrors('valor');
    }

    public function test_telas_renderizam_e_painel_integra(): void
    {
        $t = $this->novaDespesa();
        $this->baixar($t->parcelas()->sole()->id, '1.000,00');
        foreach ([route('financeiro.index', ['competencia' => '2026-11']), route('financeiro.titulo', $t), route('financeiro.contas'),
            route('financeiro.conciliacao', $this->conta), route('financeiro.categorias'), route('financeiro.novo')] as $url) {
            $this->como('financeiro@augi.local')->get($url)->assertOk();
        }
        $this->como('financeiro@augi.local')->get(route('painel', ['competencia' => '2026-11']))->assertOk()
            ->assertSee('Saldo em contas')->assertSee('R$ 9.000,00');
    }
}
