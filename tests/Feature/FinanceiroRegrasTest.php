<?php

namespace Tests\Feature;

use App\Enums\EtapaContabil;
use App\Models\Cartao;
use App\Models\CompetenciaContabil;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\FaturaCartao;
use App\Models\Movimento;
use App\Models\Titulo;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Compras;
use App\Services\Financeiro;
use App\Services\PosicaoFinanceira;
use App\Support\Contexto;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ComContexto;
use Tests\TestCase;

/**
 * Regras finas de Compras, Cartões e Meu Caixa, no nível dos serviços.
 * Escrito a partir do teste de mutação (Infection): cada teste fecha uma
 * classe de alteração que antes passava despercebida.
 */
class FinanceiroRegrasTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private ContaFinanceira $conta;

    private Cartao $cartao;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
        DB::table('titulos')->update(['cancelado_em' => now()]);
        $this->user = User::where('email', 'financeiro@augi.local')->firstOrFail();
        $v = Vinculo::vigentes()->where('user_id', $this->user->id)->where('organizacao_id', $this->empresa()->id)->firstOrFail();
        $this->actingAs($this->user);
        app(Contexto::class)->ativar($this->user, ['vinculo' => $v->id, 'empresa' => null]);
        $this->conta = ContaFinanceira::create(['nome' => 'Itaú PJ', 'tipo' => 'corrente', 'saldo_inicial_centavos' => 100000, 'saldo_inicial_em' => '2026-11-01']);
        $this->cartao = Cartao::create(['nome' => 'Corporativo', 'instituicao' => 'Itaú', 'limite_centavos' => 500000, 'dia_fechamento' => 3, 'dia_vencimento' => 10]);
    }

    private function dados(array $d = []): array
    {
        return $d + [
            'descricao' => 'Notebook', 'fornecedor' => 'Loja', 'fornecedor_documento' => null, 'data_compra' => '2026-11-20',
            'valor_bruto' => 100000, 'desconto' => 0, 'juros' => 0, 'entrada' => 0, 'categoria_id' => null, 'modalidade' => 'boleto',
            'conta_id' => null, 'cartao_id' => null, 'parcelas' => 1, 'primeiro_vencimento' => '2026-12-10', 'quitar_primeira' => false,
            'nota_tipo' => null, 'nota_numero' => null, 'nota_chave' => null, 'garantia_ate' => null, 'observacao' => null, 'itens' => [],
        ];
    }

    private function registrar(array $d = []): Compra
    {
        return app(Compras::class)->registrar($this->empresa()->id, $this->dados($d));
    }

    private function erro(callable $f): string
    {
        try {
            $f();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }
        $this->fail('Esperava ValidationException.');
    }

    // ---------------- Validações da compra ----------------

    public static function compraInvalida(): array
    {
        return [
            'sem valor' => [['valor_bruto' => 0], 'Informe o valor da compra ou os itens.'],
            'desconto negativo' => [['desconto' => -1], 'Valores não podem ser negativos.'],
            'juros negativo' => [['juros' => -1], 'Valores não podem ser negativos.'],
            'entrada negativa' => [['entrada' => -1], 'Valores não podem ser negativos.'],
            'desconto igual ao valor' => [['desconto' => 100000], 'O desconto não pode ser maior que o valor da compra.'],
            'zero parcelas' => [['parcelas' => 0], 'Use de 1 a 120 parcelas.'],
            '121 parcelas' => [['parcelas' => 121], 'Use de 1 a 120 parcelas.'],
            'cartão não escolhido' => [['modalidade' => 'cartao'], 'Escolha um cartão ativo.'],
            'entrada igual ao total' => [['entrada' => 100000], 'A entrada precisa ser menor que o total. Para compra à vista, use 1 parcela sem entrada.'],
            'pago sem conta' => [['quitar_primeira' => true], 'Escolha a conta de onde saiu o pagamento.'],
            'sem primeiro vencimento' => [['primeiro_vencimento' => null], 'Informe o primeiro vencimento.'],
        ];
    }

    #[DataProvider('compraInvalida')]
    public function test_compra_invalida_tem_mensagem_propria(array $d, string $msg): void
    {
        $this->assertSame($msg, $this->erro(fn () => $this->registrar($d)));
        $this->assertSame(0, Compra::count());
    }

    public function test_regras_especificas_do_cartao(): void
    {
        $id = $this->cartao->id;
        $this->assertSame('Compra no cartão não tem entrada: ela entra inteira nas faturas.',
            $this->erro(fn () => $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $id, 'entrada' => 100])));
        $this->assertSame('Compra no cartão é paga pela fatura.',
            $this->erro(fn () => $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $id, 'quitar_primeira' => true, 'conta_id' => $this->conta->id])));
        $this->cartao->update(['ativo' => false]);
        $this->assertSame('Escolha um cartão ativo.', $this->erro(fn () => $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $id])));
        $this->cartao->update(['ativo' => true]);
        // Cartão não precisa de primeiro vencimento (vence com as faturas); boleto com cartao_id ignora o cartão.
        $c = $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $id, 'primeiro_vencimento' => null]);
        $this->assertSame($id, $c->cartao_id);
        $this->assertNull($this->registrar(['cartao_id' => $id])->cartao_id);
    }

    public function test_limites_que_sao_validos(): void
    {
        $this->assertSame(120, $this->registrar(['valor_bruto' => 120000, 'parcelas' => 120])->titulo->parcelas()->count());
        $this->assertSame([99999, 1], $this->registrar(['entrada' => 99999])->titulo->parcelas()->pluck('valor_centavos')->all(), 'entrada = total − 1 é aceita');
        $this->assertSame(1, $this->registrar(['desconto' => 99999])->total_centavos, 'desconto = valor − 1 é aceito');
    }

    public function test_pagamento_no_ato_com_data_futura_e_mes_fechado(): void
    {
        $this->assertSame('Pagamento já feito precisa de data de compra até hoje.',
            $this->erro(fn () => $this->registrar(['quitar_primeira' => true, 'conta_id' => $this->conta->id, 'data_compra' => '2026-11-21'])));
        CompetenciaContabil::create(['organizacao_id' => $this->empresa()->id, 'competencia' => '2026-10-01', 'etapa' => EtapaContabil::Fechada]);
        $this->assertStringContainsString('foi fechado pelo contador', $this->erro(fn () => $this->registrar(['data_compra' => '2026-10-15'])));
        $this->assertSame(0, Compra::count());
    }

    // ---------------- Itens e arredondamento ----------------

    public function test_itens_quantidade_decimal_arredonda_ao_centavo_mais_proximo(): void
    {
        $c = $this->registrar(['valor_bruto' => 999999, 'itens' => [
            ['descricao' => 'Cabo', 'quantidade' => '1,2', 'unitario' => 101],      // 121,2 → 121
            ['descricao' => '', 'quantidade' => '9', 'unitario' => 99999],         // sem descrição: ignorado
            ['descricao' => '   ', 'quantidade' => '9', 'unitario' => 99999],      // só espaços: ignorado
            ['descricao' => 'Fita', 'quantidade' => '1.7', 'unitario' => 101],     // 171,7 → 172
            ['descricao' => 'Parafuso', 'quantidade' => '', 'unitario' => 50],     // quantidade vazia = 1
        ]]);
        $this->assertSame([121, 172, 50], $c->itens()->pluck('total_centavos')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(343, $c->valor_bruto_centavos, 'com itens, o valor informado é ignorado');
        $this->assertSame(['Cabo', 'Fita', 'Parafuso'], $c->itens()->pluck('descricao')->all());
        $this->assertSame([1.2, 1.7, 1.0], $c->itens()->pluck('quantidade')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([101, 101, 50], $c->itens()->pluck('valor_unitario_centavos')->map(fn ($v) => (int) $v)->all());
    }

    // ---------------- O que fica gravado ----------------

    public function test_compra_grava_todos_os_dados_titulo_parcelas_e_auditoria(): void
    {
        $c = $this->registrar(['valor_bruto' => 120000, 'desconto' => 1000, 'juros' => 2000, 'entrada' => 21000, 'parcelas' => 2,
            'conta_id' => $this->conta->id, 'quitar_primeira' => true, 'nota_tipo' => 'NF-e', 'nota_numero' => '123', 'nota_chave' => 'CH',
            'garantia_ate' => '2027-11-20', 'observacao' => 'obs', 'fornecedor_documento' => '11222333000181', 'modalidade' => 'pix']);
        $c->refresh();
        $this->assertSame([$this->conta->id, null, $this->user->id, 'obs', '2027-11-20', 'NF-e', 'CH', '11222333000181', 'pix'],
            [$c->conta_id, $c->cartao_id, $c->criado_por, $c->observacao, $c->garantia_ate->toDateString(), $c->nota_tipo, $c->nota_chave, $c->fornecedor_documento, $c->modalidade]);
        $this->assertSame([120000, 1000, 2000, 121000, 21000], [$c->valor_bruto_centavos, $c->desconto_centavos, $c->juros_centavos, $c->total_centavos, $c->entrada_centavos]);

        $t = Titulo::sole();
        $this->assertSame(['pagar', 'Compra: Notebook', 'Loja', '2026-11-01', 121000, '123', 'pix', $this->user->id],
            [$t->tipo, $t->descricao, $t->contraparte, $t->competencia->toDateString(), $t->valor_total_centavos, $t->documento, $t->forma, $t->criado_por]);
        $this->assertSame([1, 2, 3], $t->parcelas()->pluck('numero')->all());
        $this->assertSame(['2026-11-20', '2026-12-10', '2027-01-10'], $t->parcelas()->get()->map(fn ($p) => $p->vencimento->toDateString())->all());
        $this->assertSame([21000, 50000, 50000], $t->parcelas()->pluck('valor_centavos')->all());
        $this->assertSame('Entrada paga no ato da compra', Movimento::sole()->observacao);

        $ev = Evento::where('acao', 'financeiro.compra_registrada')->sole();
        $this->assertSame(['total' => 121000, 'parcelas' => 2, 'modalidade' => 'pix'], $ev->dados);

        $avista = $this->registrar(['descricao' => 'Mouse', 'valor_bruto' => 5000, 'conta_id' => $this->conta->id, 'quitar_primeira' => true]);
        $this->assertSame('Pago no ato da compra', Movimento::latest('id')->first()->observacao);
        $this->assertSame(0, $avista->titulo->fresh()->load('parcelas.movimentos.estornoDe')->saldo());
    }

    public static function formas(): array
    {
        return ['pix' => ['pix', 'pix'], 'boleto' => ['boleto', 'boleto'], 'carnê' => ['carne', 'boleto'], 'transferência' => ['transferencia', 'transferencia'],
            'dinheiro' => ['dinheiro', 'dinheiro'], 'financiamento' => ['financiamento', 'outro'], 'outro' => ['outro', 'outro']];
    }

    #[DataProvider('formas')]
    public function test_forma_prevista_do_titulo(string $modalidade, string $forma): void
    {
        $this->registrar(['modalidade' => $modalidade]);
        $this->assertSame($forma, Titulo::sole()->forma);
    }

    public function test_forma_do_cartao_e_fatura_gravada(): void
    {
        $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $this->cartao->id]);
        $this->assertSame('cartao_credito', Titulo::sole()->forma);
        $f = FaturaCartao::sole();
        $this->assertSame([$this->empresa()->id, $this->cartao->id, '2026-12-01', '2026-12-03', '2026-12-10'],
            [$f->organizacao_id, $f->cartao_id, $f->referencia->toDateString(), $f->fechamento->toDateString(), $f->vencimento->toDateString()]);
    }

    public function test_cancelamento_fica_auditado_com_motivo(): void
    {
        $c = $this->registrar();
        app(Compras::class)->cancelar($c, 'Fornecedor desistiu');
        $this->assertSame(['motivo' => 'Fornecedor desistiu'], Evento::where('acao', 'financeiro.compra_cancelada')->sole()->dados);
        $this->assertSame('Compra cancelada: Fornecedor desistiu', Titulo::sole()->motivo_cancelamento);
        $this->assertSame('Compra já cancelada.', $this->erro(fn () => app(Compras::class)->cancelar($c->fresh(), 'de novo')));
    }

    // ---------------- Fatura: datas ----------------

    public function test_compra_no_dia_do_fechamento_vai_para_a_fatura_seguinte(): void
    {
        $s = app(Compras::class);
        $f = $s->faturaPara($this->cartao, CarbonImmutable::parse('2026-11-03'));
        $this->assertSame('2026-12-10', $f->vencimento->toDateString(), 'dia 3 = dia do fechamento: próxima');
        $this->assertSame('2026-11-10', $s->faturaPara($this->cartao, CarbonImmutable::parse('2026-11-02'))->vencimento->toDateString());
        $this->assertSame('2027-01-10', $s->faturaPara($this->cartao, CarbonImmutable::parse('2026-11-03'), 1)->vencimento->toDateString());

        // Vencimento antes do fechamento no mês: vence no mês seguinte ao fechamento.
        $c2 = Cartao::create(['nome' => 'Outro', 'dia_fechamento' => 25, 'dia_vencimento' => 5]);
        $f2 = $s->faturaPara($c2, CarbonImmutable::parse('2026-11-20'));
        $this->assertSame(['2026-11-25', '2026-12-05'], [$f2->fechamento->toDateString(), $f2->vencimento->toDateString()]);
    }

    // ---------------- Fatura: pagamento ----------------

    private function faturaFechada(int $valor = 30000): FaturaCartao
    {
        $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $this->cartao->id, 'valor_bruto' => $valor]);
        $this->travelTo(CarbonImmutable::parse('2026-12-05 10:00'));

        return FaturaCartao::sole();
    }

    public function test_pagar_fatura_sem_encargos_registra_quem_quando_e_valor(): void
    {
        $f = $this->faturaFechada();
        app(Compras::class)->pagarFatura($f, $this->conta->id, '2026-12-05');
        $f->refresh();
        $this->assertSame(['2026-12-05', $this->user->id, null], [$f->paga_em->toDateString(), $f->paga_por, $f->encargos_titulo_id]);
        $this->assertSame(1, Titulo::whereNull('cancelado_em')->count(), 'sem encargos, nenhum título extra');
        $this->assertSame(['valor' => 30000, 'encargos' => 0], Evento::where('acao', 'financeiro.fatura_cartao_paga')->sole()->dados);
        $this->assertNull(Movimento::sole()->chave_idempotencia, 'sem chave do formulário, sem chave no movimento');
        $this->assertSame('Esta fatura já foi paga em 05/12/2026.', $this->erro(fn () => app(Compras::class)->pagarFatura($f, $this->conta->id, '2026-12-05')));
    }

    public function test_encargos_negativos_e_contraparte_dos_encargos(): void
    {
        $f = $this->faturaFechada();
        $this->assertSame('Encargos não podem ser negativos.', $this->erro(fn () => app(Compras::class)->pagarFatura($f, $this->conta->id, '2026-12-05', -1)));
        $this->cartao->update(['instituicao' => null]);
        app(Compras::class)->pagarFatura($f->fresh(), $this->conta->id, '2026-12-05', 990, 'k');
        $enc = $f->fresh()->encargos;
        $this->assertSame(['Corporativo', 990, 'Juros e encargos financeiros'], [$enc->contraparte, $enc->valor_total_centavos, $enc->categoria->nome]);
        $this->assertSame(hash('sha256', 'k|'.$f->parcelas()->first()->id), Movimento::where('valor_centavos', 30000)->sole()->chave_idempotencia);
    }

    public function test_fatura_sem_compras_nao_tem_o_que_pagar(): void
    {
        $f = app(Compras::class)->faturaPara($this->cartao, CarbonImmutable::parse('2026-10-20'));
        $this->assertSame('Esta fatura não tem saldo em aberto.', $this->erro(fn () => app(Compras::class)->pagarFatura($f, $this->conta->id, '2026-11-20')));
    }

    // ---------------- Meu Caixa ----------------

    private function titulo(string $tipo, int $valor, string $venc, int $parcelas = 1, string $desc = 'X', ?string $contraparte = null): Titulo
    {
        return app(Financeiro::class)->criarTitulo($this->empresa()->id, ['tipo' => $tipo, 'descricao' => $desc, 'categoria_id' => null,
            'contraparte' => $contraparte, 'competencia' => '2026-11-01', 'documento' => null], $valor, $parcelas, $venc);
    }

    public function test_compromissos_respeitam_prazo_ordem_e_formato(): void
    {
        $pos = app(PosicaoFinanceira::class);
        $this->titulo('pagar', 5000, '2027-03-01', 1, 'Longe');            // fora do prazo (criado antes)
        $this->titulo('receber', 3000, '2026-11-25', 3, 'Projeto', 'Cliente');
        $this->titulo('pagar', 2000, '2026-11-25', 1, 'Aluguel', 'Imob');
        $quitado = $this->titulo('pagar', 700, '2026-11-22', 1, 'Pago');
        app(Financeiro::class)->baixar($quitado->parcelas()->first(), ['conta_id' => $this->conta->id, 'data' => '2026-11-20', 'valor' => 700]);
        $this->titulo('receber', 900, '2026-11-10', 1, 'Atrasado');        // vencido a receber
        $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $this->cartao->id, 'valor_bruto' => 4000, 'data_compra' => '2026-10-10']); // fatura de 10/11, vencida

        $ev = $pos->compromissos(CarbonImmutable::parse('2026-11-30'));
        $this->assertSame(['Fatura Corporativo — Novembro/2026', 'Aluguel', 'Projeto (1/3)'], $ev->pluck('descricao')->all(),
            'fatura vencida hoje; no mesmo dia, saídas antes de entradas; parcela numerada; vencido a receber e quitado ficam fora');
        $this->assertSame([true, false, false], $ev->pluck('cartao')->all());
        $this->assertSame([true, false, false], $ev->pluck('atrasado')->all());
        $this->assertSame(['2026-11-20', '2026-11-25', '2026-11-25'], $ev->map(fn ($e) => $e['data']->toDateString())->all());
        $this->assertSame([4000, 2000, 1000], $ev->pluck('valor')->all());
        $this->assertSame(['Itaú', 'Imob', 'Cliente'], $ev->pluck('contraparte')->all());
        $this->assertSame(route('cartoes.fatura', FaturaCartao::sole()), $ev[0]['url']);

        $com = $pos->compromissos(CarbonImmutable::parse('2026-11-30'), true);
        $this->assertCount(4, $com, 'incluindo recebimentos vencidos');
        $atr = $com->firstWhere('descricao', 'Atrasado');
        $this->assertSame([true, '2026-11-20', '2026-11-10', 'receber'], [$atr['atrasado'], $atr['data']->toDateString(), $atr['vencimento']->toDateString(), $atr['tipo']]);
        $this->assertSame('Atrasado', $com[1]['descricao'], 'hoje: saída (fatura) antes da entrada');
    }

    public function test_um_item_fora_do_criterio_nao_interrompe_os_seguintes(): void
    {
        // A ordem de criação importa: o item excluído vem ANTES dos que devem aparecer.
        $this->titulo('receber', 900, '2026-11-10', 1, 'Vencido');
        $this->titulo('receber', 500, '2026-11-28', 1, 'No prazo');
        $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $this->cartao->id, 'valor_bruto' => 100, 'data_compra' => '2026-12-05']); // fatura de jan
        $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $this->cartao->id, 'valor_bruto' => 200, 'data_compra' => '2026-11-20']); // fatura de dez
        $ev = app(PosicaoFinanceira::class)->compromissos(CarbonImmutable::parse('2026-12-31'));
        $this->assertSame(['No prazo', 'Fatura Corporativo — Dezembro/2026'], $ev->pluck('descricao')->all());
    }

    public function test_fatura_futura_fora_do_prazo_nao_entra(): void
    {
        $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $this->cartao->id, 'valor_bruto' => 4000, 'parcelas' => 2]);
        $ev = app(PosicaoFinanceira::class)->compromissos(CarbonImmutable::parse('2026-12-31'));
        $this->assertSame(['2026-12-10'], $ev->map(fn ($e) => $e['vencimento']->toDateString())->all(), 'só a fatura de dezembro; a de janeiro fica fora');
    }

    public function test_projecao_padrao_minimo_negativo_e_simulacao(): void
    {
        $pos = app(PosicaoFinanceira::class);
        $this->titulo('pagar', 100000, '2026-11-22');   // saldo vai a 0 no dia 22 (zero não é negativo)
        $this->titulo('receber', 50000, '2026-11-24');
        $this->titulo('pagar', 50000, '2026-11-26');    // volta a 0 no dia 26: o mínimo fica no dia 22 (primeiro)

        $p = $pos->projetar();
        $this->assertSame(30, $p['dias']);
        $this->assertSame('2026-12-20', $p['ate']->toDateString());
        $this->assertCount(31, $p['serie']);
        $this->assertSame(['valor' => 0, 'data' => '2026-11-22'], ['valor' => $p['minimo']['valor'], 'data' => $p['minimo']['data']->toDateString()]);
        $this->assertNull($p['negativo'], 'saldo zero não é falta de dinheiro');
        $this->assertSame([100000, 50000, 150000, 0, 0], [$p['saldo_atual'], $p['a_receber'], $p['a_pagar'], $p['saldo_final'], $p['livre']]);
        $this->assertSame([0, 100000], [$p['serie'][2]['entradas'], $p['serie'][2]['saidas']]);

        // Simulação: valor zero é ignorado; data passada vira hoje; entrada simulada não entra nos totais.
        $this->assertCount(3, $pos->projetar(30, null, false, ['valor' => 0, 'data' => '2026-11-21', 'tipo' => 'pagar'])['eventos']);
        $s = $pos->projetar(30, null, false, ['valor' => 1, 'data' => '2026-11-01', 'tipo' => 'pagar']);
        $this->assertSame(['2026-11-22', 1], [$s['negativo']['data']->toDateString(), $s['negativo']['deficit']]);
        $sim = $s['eventos']->firstWhere('simulado', true);
        $this->assertSame(['2026-11-20', 'pagar', false, false], [$sim['data']->toDateString(), $sim['tipo'], $sim['cartao'], $sim['atrasado']]);
        $this->assertSame(150000, $s['a_pagar'], 'simulação não soma no total previsto');
        $this->assertEquals($s['a_pagar'], $s['origens']->get('pagar')->flatten(1)->sum('valor'));
        $this->assertEquals($s['a_receber'], $s['origens']->get('receber')->flatten(1)->sum('valor'));
        $r = $pos->projetar(30, null, false, ['valor' => 7000, 'data' => '2026-11-30', 'tipo' => 'receber']);
        $this->assertSame([50000, 7000], [$r['a_receber'], $r['saldo_final']]);
    }

    public function test_projecao_conta_cartoes_e_vencidos_a_receber(): void
    {
        $this->registrar(['modalidade' => 'cartao', 'cartao_id' => $this->cartao->id, 'valor_bruto' => 3000, 'parcelas' => 3]);
        $this->titulo('receber', 800, '2026-11-01');
        $p = app(PosicaoFinanceira::class)->projetar(7);
        $this->assertSame([3000, 800, 7], [$p['cartoes'], $p['vencidos_receber'], $p['dias']]);
        $conf = app(PosicaoFinanceira::class)->confirmados(collect([$this->conta->fill(['saldo_extrato_centavos' => 90000, 'saldo_extrato_em' => '2026-11-15'])]))->sole();
        $this->assertSame([$this->conta->id, 90000, '2026-11-15', 10000], [$conf['conta']->id, $conf['valor'], $conf['em']->toDateString(), $conf['diferenca']]);
    }
}
