<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\FaturaCartao;
use App\Models\Movimento;
use App\Models\Parcela;
use App\Models\SugestaoDispensada;
use App\Models\Titulo;
use App\Models\Transferencia;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Assistente;
use App\Services\Financeiro;
use App\Services\PosicaoFinanceira;
use App\Services\Recebimentos;
use App\Services\Sugestoes;
use App\Support\Contexto;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ComContexto;
use Tests\TestCase;

/**
 * Financeiro 2.0 — critérios de aceite: compras, cartões, recebimentos parcelados,
 * inadimplência, transferências, Meu Caixa, sugestões e assistente sem tokens.
 */
class Financeiro2Test extends TestCase
{
    use ComContexto, RefreshDatabase;

    private ContaFinanceira $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00')); // sexta-feira
        $this->seed(DatabaseSeeder::class);
        // Base limpa: só o que cada teste criar entra nas contas e projeções.
        DB::table('titulos')->update(['cancelado_em' => now()]);
        $this->conta = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Itaú PJ', 'tipo' => 'corrente',
            'saldo_inicial_centavos' => 800000, 'saldo_inicial_em' => '2026-11-01']);
    }

    /** Ativa o contexto da empresa A para chamar serviços diretamente (como numa requisição). */
    private function ctxA(string $email = 'financeiro@augi.local'): void
    {
        $u = User::where('email', $email)->firstOrFail();
        $v = Vinculo::vigentes()->where('user_id', $u->id)->where('organizacao_id', $this->empresa()->id)->firstOrFail();
        $this->actingAs($u);
        app(Contexto::class)->ativar($u, ['vinculo' => $v->id, 'empresa' => null]);
    }

    private function compra(array $d): Compra
    {
        $r = $this->como('financeiro@augi.local')->post(route('compras.store'), $d + [
            'descricao' => 'Notebook', 'fornecedor' => 'Loja Exemplo', 'data_compra' => '2026-11-20', 'modalidade' => 'boleto',
            'parcelas' => 1, 'primeiro_vencimento' => '2026-12-10',
        ]);
        $r->assertRedirect()->assertSessionHasNoErrors();

        return Compra::latest('id')->firstOrFail();
    }

    private function cartao(): Cartao
    {
        $this->como('financeiro@augi.local')->post(route('cartoes.store'), [
            'nome' => 'Itaú Empresas', 'instituicao' => 'Itaú', 'final' => '1234', 'limite' => '10.000,00',
            'dia_fechamento' => 3, 'dia_vencimento' => 10, 'conta_pagamento_id' => $this->conta->id,
        ])->assertSessionHasNoErrors();

        return Cartao::sole();
    }

    private function receita(string $valor, int $parcelas, string $venc, string $cliente = 'Cliente A'): Titulo
    {
        $this->como('financeiro@augi.local')->post(route('financeiro.store'), [
            'tipo' => 'receber', 'descricao' => 'Serviço '.$cliente, 'contraparte' => $cliente, 'competencia' => '2026-11',
            'valor' => $valor, 'parcelas' => $parcelas, 'vencimento' => $venc,
        ])->assertSessionHasNoErrors();

        return Titulo::where('contraparte', $cliente)->latest('id')->firstOrFail();
    }

    private function despesa(string $valor, string $venc, string $favorecido = 'Fornecedor X'): Titulo
    {
        $this->como('financeiro@augi.local')->post(route('financeiro.store'), [
            'tipo' => 'pagar', 'descricao' => 'Despesa '.$favorecido, 'contraparte' => $favorecido, 'competencia' => '2026-11',
            'valor' => $valor, 'parcelas' => 1, 'vencimento' => $venc,
        ])->assertSessionHasNoErrors();

        return Titulo::where('contraparte', $favorecido)->latest('id')->firstOrFail();
    }

    // ---------------- 1. Compras ----------------

    public function test_compra_parcelada_cria_todos_os_compromissos_em_um_unico_titulo(): void
    {
        $c = $this->compra(['valor_bruto' => '4.500,00', 'parcelas' => 10]);
        $t = $c->titulo;

        $this->assertSame(450000, $c->total_centavos);
        $this->assertSame('pagar', $t->tipo);
        $this->assertSame(10, $t->parcelas()->count());
        $this->assertSame([45000], $t->parcelas()->pluck('valor_centavos')->unique()->values()->all());
        $this->assertSame('2026-12-10', $t->parcelas()->first()->vencimento->toDateString());
        $this->assertSame('2027-09-10', $t->parcelas()->get()->last()->vencimento->toDateString());
        $this->assertSame(1, Titulo::where('origem_type', $c->getMorphClass())->where('origem_id', $c->id)->count(), 'um título por compra');
    }

    public function test_compra_com_itens_desconto_juros_e_entrada_paga(): void
    {
        $c = $this->compra([
            'itens' => [['descricao' => 'Monitor', 'quantidade' => '2', 'unitario' => '1.000,00'], ['descricao' => 'Cabo', 'quantidade' => '1', 'unitario' => '100,01']],
            'desconto' => '100,00', 'juros' => '99,99', 'entrada' => '500,00', 'parcelas' => 3,
            'conta_id' => $this->conta->id, 'quitar_primeira' => 1,
        ]);
        $t = $c->titulo()->with('parcelas.movimentos.estornoDe')->first();

        $this->assertSame(210001, $c->valor_bruto_centavos, 'bruto = soma dos itens');
        $this->assertSame(210000, $c->total_centavos, '2.100,01 − 100,00 + 99,99');
        $this->assertSame([50000, 53333, 53333, 53334], $t->parcelas->pluck('valor_centavos')->all(), 'entrada + 3 parcelas; centavos na última');
        $this->assertSame(0, $t->parcelas[0]->saldo(), 'entrada já paga');
        $this->assertSame(160000, $t->saldo());
        $this->assertSame(800000 - 50000, $this->conta->saldo());
    }

    public function test_cancelar_compra_so_sem_pagamentos(): void
    {
        $c = $this->compra(['valor_bruto' => '1.000,00', 'parcelas' => 2]);
        $this->como('financeiro@augi.local')->post(route('compras.cancelar', $c), ['motivo' => 'Desisti'])->assertSessionHasNoErrors();
        $this->assertTrue($c->fresh()->cancelada());
        $this->assertNotNull($c->titulo->fresh()->cancelado_em);

        $pago = $this->compra(['valor_bruto' => '1.000,00', 'conta_id' => $this->conta->id, 'quitar_primeira' => 1]);
        $this->como('financeiro@augi.local')->post(route('compras.cancelar', $pago), ['motivo' => 'x'])->assertSessionHasErrors('motivo');
        $this->assertFalse($pago->fresh()->cancelada());
    }

    // ---------------- 2. Cartões ----------------

    public function test_compra_no_cartao_vai_para_as_faturas_certas(): void
    {
        $cartao = $this->cartao();
        // 20/11, depois do fechamento (dia 3) → fecha 03/12, vence 10/12.
        $c = $this->compra(['valor_bruto' => '900,00', 'parcelas' => 3, 'modalidade' => 'cartao', 'cartao_id' => $cartao->id]);
        $ps = $c->titulo->parcelas()->with('fatura')->get();

        $this->assertSame(['2026-12-10', '2027-01-10', '2027-02-10'], $ps->map(fn ($p) => $p->vencimento->toDateString())->all());
        $this->assertSame(['2026-12-03', '2027-01-03', '2027-02-03'], $ps->map(fn ($p) => $p->fatura->fechamento->toDateString())->all());
        $this->assertSame(3, FaturaCartao::count());

        // Compra no dia 02/12 (antes do fechamento) cai na mesma fatura de dezembro.
        $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00'));
        $c2 = $this->compra(['valor_bruto' => '100,00', 'modalidade' => 'cartao', 'cartao_id' => $cartao->id, 'data_compra' => '2026-12-02']);
        $this->assertSame($ps[0]->fatura_cartao_id, $c2->titulo->parcelas()->first()->fatura_cartao_id);
        $this->assertSame(100000, $cartao->fresh()->emAberto());
        $this->assertSame(900000, $cartao->fresh()->limiteDisponivel());
    }

    public function test_fatura_paga_nao_duplica_despesa_e_encargos_ficam_separados(): void
    {
        $cartao = $this->cartao();
        $this->compra(['descricao' => 'Software', 'valor_bruto' => '600,00', 'parcelas' => 2, 'modalidade' => 'cartao', 'cartao_id' => $cartao->id]);
        $this->compra(['descricao' => 'Passagem', 'valor_bruto' => '400,00', 'modalidade' => 'cartao', 'cartao_id' => $cartao->id]);
        $fatura = FaturaCartao::orderBy('referencia')->first();
        $despesasAntes = Titulo::whereNull('cancelado_em')->where('tipo', 'pagar')->sum('valor_total_centavos');
        $parcela = $fatura->parcelas()->first();

        // Parcela avulsa de cartão não pode ser baixada (sairia duas vezes do caixa).
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $parcela), ['conta_id' => $this->conta->id, 'data' => '2026-11-20', 'valor' => '300,00'])
            ->assertSessionHasErrors('valor');
        // Fatura ainda não fechou.
        $this->como('financeiro@augi.local')->post(route('cartoes.pagar', $fatura), ['conta_id' => $this->conta->id, 'data' => '2026-11-20'])->assertSessionHasErrors('conta_id');

        $this->travelTo(CarbonImmutable::parse('2026-12-08 10:00'));
        $dados = ['conta_id' => $this->conta->id, 'data' => '2026-12-08', 'encargos' => '12,50', 'chave' => 'k1'];
        $this->como('financeiro@augi.local')->post(route('cartoes.pagar', $fatura), $dados)->assertRedirect()->assertSessionHasNoErrors();
        $this->como('financeiro@augi.local')->post(route('cartoes.pagar', $fatura), $dados)->assertSessionHasErrors('conta_id'); // duplo envio

        $f = $fatura->fresh()->load('parcelas.movimentos.estornoDe');
        $this->assertSame(0, $f->saldo());
        $this->assertSame('paga', $f->situacao());
        $this->assertSame(70000, $f->total(), '300 (1/2 do software) + 400');
        $this->assertSame(800000 - 70000 - 1250, $this->conta->fresh()->saldo(), 'sai só a fatura + encargos, uma vez');
        $this->assertSame((int) $despesasAntes + 1250, (int) Titulo::whereNull('cancelado_em')->where('tipo', 'pagar')->sum('valor_total_centavos'),
            'despesa das compras não é lançada de novo; encargos entram como título próprio');
        $this->assertSame('Juros e encargos financeiros', $f->encargos->categoria->nome);
        $this->assertSame(1, Movimento::where('observacao', 'like', 'Encargos%')->count());
    }

    // ---------------- 3. Recebimentos e inadimplência ----------------

    public function test_recebimento_parcelado_liquidado_por_parcela_e_parcial(): void
    {
        $t = $this->receita('12.000,00', 3, '2026-12-10');
        $this->assertSame([400000, 400000, 400000], $t->parcelas()->pluck('valor_centavos')->all());
        $this->assertSame(['2026-12-10', '2027-01-10', '2027-02-10'], $t->parcelas()->get()->map(fn ($p) => $p->vencimento->toDateString())->all());

        $p1 = $t->parcelas()->first();
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $p1), ['conta_id' => $this->conta->id, 'data' => '2026-11-20', 'valor' => '2.000,00'])
            ->assertSessionHasNoErrors();
        $t = $t->fresh()->load('parcelas.movimentos.estornoDe');
        $this->assertSame(200000, $t->parcelas[0]->saldo());
        $this->assertSame(400000, $t->parcelas[1]->saldo());
        $this->assertSame('parcial', $t->situacao());
    }

    public function test_inadimplencia_por_faixa_e_atraso_recorrente(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00'));
        $t = $this->receita('3.000,00', 3, '2026-08-10', 'Cliente C');
        $this->receita('500,00', 1, '2026-11-15', 'Cliente D');
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        // Cliente C pagou as duas primeiras com atraso; a terceira (10/10) segue aberta.
        foreach ([[0, '2026-08-20'], [1, '2026-09-25']] as [$i, $data]) {
            $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $t->parcelas[$i]), ['conta_id' => $this->conta->id, 'data' => $data, 'valor' => '1.000,00'])
                ->assertSessionHasNoErrors();
        }
        $this->ctxA();
        $r = app(Recebimentos::class);
        $v = $r->vencidas();
        $this->assertSame(['31-60', '1-7'], $v->pluck('faixa')->all(), 'C: 41 dias; D: 5 dias');
        $this->assertSame(['Cliente C'], $r->atrasosRecorrentes()->pluck('cliente')->all());
        $this->assertTrue($r->porCliente()->firstWhere('cliente', 'Cliente C')['recorrente']);

        $this->como('financeiro@augi.local')->get(route('financeiro.inadimplencia', ['faixa' => '1-7']))
            ->assertOk()->assertSee('Cliente D')->assertDontSee('Cliente C');
    }

    // ---------------- 4. Transferências ----------------

    public function test_transferencia_nao_distorce_receitas_e_despesas(): void
    {
        $poup = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Reserva', 'tipo' => 'poupanca', 'saldo_inicial_em' => '2026-11-01']);
        $this->ctxA();
        $antes = app(Financeiro::class)->painel(CarbonImmutable::parse('2026-11-01'));

        $this->como('financeiro@augi.local')->post(route('transferencias.store'), [
            'conta_origem_id' => $this->conta->id, 'conta_destino_id' => $poup->id, 'data' => '2026-11-20', 'valor' => '5.000,00', 'tarifa' => '8,50', 'chave' => 'tr1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->como('financeiro@augi.local')->post(route('transferencias.store'), [ // duplo envio
            'conta_origem_id' => $this->conta->id, 'conta_destino_id' => $poup->id, 'data' => '2026-11-20', 'valor' => '5.000,00', 'tarifa' => '8,50', 'chave' => 'tr1',
        ]);

        $this->assertSame(1, Transferencia::count());
        $this->assertSame(800000 - 500000 - 850, $this->conta->fresh()->saldo());
        $this->assertSame(500000, $poup->fresh()->saldo());
        $this->ctxA();
        $depois = app(Financeiro::class)->painel(CarbonImmutable::parse('2026-11-01'));
        $this->assertSame($antes['receitas_previstas'], $depois['receitas_previstas'], 'transferência não é receita');
        $this->assertSame($antes['despesas_previstas'] + 850, $depois['despesas_previstas'], 'só a tarifa vira despesa');

        // Mesma conta: recusado. Estorno: saldo volta, original preservado.
        $this->como('financeiro@augi.local')->post(route('transferencias.store'), ['conta_origem_id' => $poup->id, 'conta_destino_id' => $poup->id, 'data' => '2026-11-20', 'valor' => '1,00'])
            ->assertSessionHasErrors('conta_destino_id');
        $this->como('financeiro@augi.local')->post(route('transferencias.estornar', Transferencia::first()), ['motivo' => 'Errado'])->assertSessionHasNoErrors();
        $this->assertSame(0, $poup->fresh()->saldo());
        $this->assertSame(2, Transferencia::count());
        $this->expectException(\LogicException::class);
        Transferencia::first()->delete();
    }

    public function test_conciliacao_de_transferencia_e_saldo_confirmado_do_ofx(): void
    {
        $poup = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Reserva', 'tipo' => 'poupanca', 'saldo_inicial_em' => '2026-11-01']);
        $this->como('financeiro@augi.local')->post(route('transferencias.store'), [
            'conta_origem_id' => $this->conta->id, 'conta_destino_id' => $poup->id, 'data' => '2026-11-19', 'valor' => '1.000,00',
        ])->assertSessionHasNoErrors();
        $ofx = "<OFX><STMTRS><BANKTRANLIST><DTSTART>20261101<DTEND>20261119\n<STMTTRN><DTPOSTED>20261119<TRNAMT>-1000.00<FITID>T1<MEMO>TED RESERVA</STMTTRN></BANKTRANLIST>"
            .'<LEDGERBAL><BALAMT>6990.00<DTASOF>20261119</LEDGERBAL></STMTRS></OFX>';
        $this->como('financeiro@augi.local')->post(route('financeiro.importar', $this->conta), ['arquivo' => UploadedFile::fake()->createWithContent('e.ofx', $ofx)])
            ->assertSessionHasNoErrors();

        $conta = $this->conta->fresh();
        $this->assertSame(699000, $conta->saldo_extrato_centavos);
        $this->assertSame('2026-11-19', $conta->saldo_extrato_em->toDateString());
        $this->ctxA();
        $this->assertSame(1000, app(PosicaoFinanceira::class)->confirmados(collect([$conta]))->first()['diferenca'], 'Augi 7.000,00 × banco 6.990,00');

        $linha = $conta->extrato()->sole();
        $this->como('financeiro@augi.local')->get(route('financeiro.conciliacao', $conta))->assertOk()->assertSee('Transferência Itaú PJ → Reserva');
        $this->como('financeiro@augi.local')->post(route('financeiro.conciliar_transferencia', $linha), ['transferencia_id' => Transferencia::sole()->id])->assertSessionHasNoErrors();
        $this->assertSame(Transferencia::sole()->id, $linha->fresh()->transferencia_id);
        $this->como('financeiro@augi.local')->post(route('transferencias.estornar', Transferencia::sole()), ['motivo' => 'x'])->assertSessionHasErrors('motivo');
    }

    // ---------------- 5. Meu Caixa ----------------

    public function test_meu_caixa_projeta_por_data_e_detecta_falta_antes_do_recebimento(): void
    {
        // Exemplo da especificação: saldo 8.000, a receber 12.000, a pagar 8.900.
        $this->receita('12.000,00', 1, '2026-12-15');
        $this->despesa('8.900,00', '2026-12-05');
        $this->ctxA();
        $p = app(PosicaoFinanceira::class)->projetar(30, [$this->conta->id]);

        $this->assertSame(800000, $p['saldo_atual']);
        $this->assertSame(-90000, $p['apos_pagar']);
        $this->assertSame(1110000, $p['saldo_final']);
        $this->assertSame('2026-12-05', $p['negativo']['data']->toDateString(), 'falta dinheiro no dia 05, antes do recebimento do dia 15');
        $this->assertSame(90000, $p['negativo']['deficit']);
        $this->assertSame(0, $p['livre']);

        // Simulação não grava nada.
        $antes = Titulo::count();
        $this->como('financeiro@augi.local')->get(route('financeiro.caixa', ['contas' => [$this->conta->id], 'simular_valor' => '500,00', 'simular_data' => '2026-11-25']))
            ->assertOk()->assertSee('Não cabe agora')->assertSee('Falta dinheiro em 05/12/2026');
        $this->assertSame($antes, Titulo::count());
    }

    public function test_recebimento_vencido_fica_fora_da_projecao_por_padrao(): void
    {
        $this->receita('1.000,00', 1, '2026-11-10');
        $this->despesa('300,00', '2026-11-01');
        $this->ctxA();
        $pos = app(PosicaoFinanceira::class);
        $p = $pos->projetar(7, [$this->conta->id]);
        $this->assertSame(800000 - 30000, $p['serie'][0]['saldo'], 'pagamento vencido entra hoje; recebimento vencido não');
        $this->assertSame(100000, $p['vencidos_receber']);
        $this->assertSame(800000 + 100000 - 30000, $pos->projetar(7, [$this->conta->id], true)['serie'][0]['saldo']);
    }

    // ---------------- 6. Sugestões e assistente ----------------

    public function test_sugestoes_identificam_e_podem_ser_dispensadas(): void
    {
        $this->despesa('8.900,00', '2026-12-05');
        $this->despesa('250,00', '2026-11-25', 'Hospedagem');
        $this->despesa('250,00', '2026-11-25', 'Hospedagem');
        $this->ctxA();
        $s = app(Sugestoes::class)->gerar();
        $this->assertTrue($s->contains('tipo', 'duplicado'));
        $this->assertTrue($s->contains('tipo', 'sem_categoria'));
        $this->assertSame('caixa:2026-12-05', $s->first()['chave'], 'falta de caixa vem primeiro (mais urgente)');

        $dup = $s->firstWhere('tipo', 'duplicado');
        $this->como('financeiro@augi.local')->post(route('inteligencia.dispensar'), ['chave' => $dup['chave'], 'dias' => 7])->assertSessionHasNoErrors();
        $this->ctxA();
        $this->assertFalse(app(Sugestoes::class)->gerar()->contains('chave', $dup['chave']));
        $this->assertSame('2026-11-27', SugestaoDispensada::sole()->ate->toDateString());
        $this->como('contabilidade@augi.local')->post(route('inteligencia.dispensar'), ['chave' => 'x'])->assertForbidden();
    }

    public function test_assistente_responde_com_os_mesmos_numeros_dos_servicos(): void
    {
        $this->receita('12.000,00', 3, '2026-12-10');
        $this->despesa('8.900,00', '2026-11-21');
        $this->ctxA();
        $a = app(Assistente::class);
        $pos = app(PosicaoFinanceira::class);
        $saldo = (int) ContaFinanceira::where('ativa', true)->get()->sum(fn ($c) => $c->saldo());

        $this->assertSame(brl($saldo), $a->responder('Quanto tenho disponível?')['destaque']);
        $receber = (int) $pos->compromissos(CarbonImmutable::today()->addDays(30))->where('tipo', 'receber')->sum('valor');
        $this->assertSame(brl($receber), $a->responder('quanto vou receber nos proximos 30 dias')['destaque']);
        $this->assertSame(brl(400000), $a->responder('quanto vou receber nos próximos 30 dias?')['destaque'], 'só a 1ª parcela vence em 30 dias');
        $this->assertSame('vencimentos', $a->responder('O que vence amanhã?')['intencao']);
        $this->assertSame([['21/11', 'Pagar', 'Despesa Fornecedor X', brl(890000)]], $a->responder('O que vence amanhã?')['linhas']);
        $this->assertSame(brl(app(PosicaoFinanceira::class)->projetar(30)['saldo_final']).' ao fim do período', $a->responder('Como estará meu caixa?')['destaque']);
        $this->assertSame('ajuda', $a->responder('qual a capital da França?')['intencao']);

        // "Registrar compra": só prepara o formulário; nada é gravado.
        $r = $a->responder('Quero registrar uma compra de R$ 2.400 em seis parcelas');
        $this->assertSame('compra', $r['intencao']);
        $this->assertStringContainsString('valor=2.400%2C00', $r['links'][0][1]);
        $this->assertStringContainsString('parcelas=6', $r['links'][0][1]);
        $this->assertSame(0, Compra::count());

        $this->como('financeiro@augi.local')->get(route('inteligencia.assistente', ['q' => 'Quais clientes estão atrasados?']))->assertOk()->assertSee('Clientes com pagamento em atraso');
    }

    // ---------------- 7. Permissões e isolamento ----------------

    public function test_permissoes_e_isolamento_do_financeiro_2(): void
    {
        $c = $this->compra(['valor_bruto' => '1.000,00']);
        $cartao = $this->cartao();

        // Escritório consulta, não grava.
        $this->como('contabilidade@augi.local')->get(route('financeiro.caixa'))->assertOk();
        $this->como('contabilidade@augi.local')->get(route('compras.show', $c))->assertOk()->assertDontSee('Cancelar compra');
        $this->como('contabilidade@augi.local')->post(route('compras.store'), [])->assertForbidden();
        $this->como('contabilidade@augi.local')->get(route('inteligencia.assistente', ['q' => 'registrar compra de 100']))->assertOk()->assertSee('Seu acesso é de consulta');
        // Prestador não vê saldos da contratante.
        foreach (['financeiro.caixa', 'financeiro.agenda', 'compras.index', 'cartoes.index', 'inteligencia.assistente'] as $rota) {
            $this->como('prestador@augi.local')->get(route($rota))->assertForbidden();
        }
        // Outra empresa: 404.
        $this->como('admin.b@augi.local', self::B)->get(route('compras.show', $c))->assertNotFound();
        $this->como('admin.b@augi.local', self::B)->get(route('cartoes.show', $cartao))->assertNotFound();
        $this->como('admin.b@augi.local', self::B)->get(route('inteligencia.assistente', ['q' => 'quanto tenho disponivel']))->assertOk()->assertDontSee('Itaú PJ');
    }

    public function test_telas_novas_abrem(): void
    {
        $cartao = $this->cartao();
        $this->compra(['valor_bruto' => '900,00', 'parcelas' => 3, 'modalidade' => 'cartao', 'cartao_id' => $cartao->id]);
        $this->receita('1.000,00', 2, '2026-11-10');
        $f = FaturaCartao::first();
        foreach ([
            route('financeiro.caixa'), route('financeiro.caixa', ['dias' => 90, 'vencidos' => 1]), route('financeiro.agenda'),
            route('financeiro.agenda', ['visao' => 'mes', 'mes' => '2026-12']), route('financeiro.agenda', ['dia' => '2026-12-10']),
            route('financeiro.inadimplencia'), route('compras.index'), route('compras.index', ['aba' => 'todas']), route('compras.index', ['aba' => 'parcelamentos']),
            route('compras.create', ['valor' => '100,00', 'parcelas' => 2]), route('compras.show', Compra::first()),
            route('compras.show', [Compra::first(), 'aba' => 'parcelas']), route('compras.show', [Compra::first(), 'aba' => 'historico']),
            route('cartoes.index'), route('cartoes.show', $cartao), route('cartoes.show', [$cartao, 'aba' => 'compras']), route('cartoes.fatura', $f),
            route('transferencias.index'), route('inteligencia.sugestoes'), route('financeiro.titulo', Compra::first()->titulo),
        ] as $u) {
            $this->como('financeiro@augi.local')->get($u)->assertOk();
        }
        foreach (Assistente::SUGERIDAS as $q) {
            $this->como('financeiro@augi.local')->get(route('inteligencia.assistente', ['q' => $q]))->assertOk()->assertDontSee('Ainda não sei responder');
        }
    }
}
