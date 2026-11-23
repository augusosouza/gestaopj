<?php

namespace Tests\Feature;

use App\Http\Controllers\ContasPagarController;
use App\Models\CobrancaRegistro;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\Notificacao;
use App\Models\Titulo;
use App\Models\User;
use App\Services\GoogleAgenda;
use App\Services\LembretesVencimento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** Augi 3.0, etapa 7 — Central de Recebimentos, histórico de cobrança, promessas, renegociação e régua. */
class RecebimentosCobrancaTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private Titulo $t;

    private ContaFinanceira $conta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
        DB::table('titulos')->update(['cancelado_em' => now()]);
        $this->conta = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Itaú', 'tipo' => 'corrente', 'saldo_inicial_em' => '2026-11-01']);
        $this->como('financeiro@augi.local')->post(route('financeiro.store'), ['tipo' => 'receber', 'descricao' => 'Projeto de implantação', 'contraparte' => 'Cliente B S.A.',
            'competencia' => '2026-10', 'valor' => '3.000,00', 'parcelas' => 3, 'vencimento' => '2026-11-05'])->assertSessionHasNoErrors();
        $this->t = Titulo::where('descricao', 'Projeto de implantação')->sole();
    }

    private function contato(array $d)
    {
        return $this->como('financeiro@augi.local')->post(route('cobranca.registrar', $this->t), $d + ['tipo' => 'contato', 'canal' => 'whatsapp', 'data' => '2026-11-20T09:30']);
    }

    public function test_central_de_recebimentos_indicadores_e_abas(): void
    {
        $r = $this->como('financeiro@augi.local')->get(route('contas_receber.index'))->assertOk();
        $r->assertSee('Contas a receber')->assertSee('Atrasado')->assertSee('R$ 1.000,00')->assertSee('1 cliente(s)')->assertSee('Cliente B S.A.');
        foreach (array_keys(ContasPagarController::ABAS_RECEBER) as $aba) {
            $this->como('financeiro@augi.local')->get(route('contas_receber.index', ['aba' => $aba]))->assertOk();
        }
        $this->como('financeiro@augi.local')->get(route('contas_receber.index', ['aba' => 'vencidas']))->assertSee('Parcela 1/3')->assertSee('Cobrar');
        $this->como('financeiro@augi.local')->get(route('contas_pagar.index', ['aba' => 'aberto']))->assertDontSee('Projeto de implantação');
        $this->como('prestador@augi.local')->get(route('contas_receber.index'))->assertForbidden();
    }

    public function test_contato_e_promessa_nao_viram_recebimento(): void
    {
        $p1 = $this->t->parcelas()->first();
        $this->contato(['mensagem' => 'Lembrei do boleto vencido', 'resposta' => 'Vai pagar sexta'])->assertSessionHasNoErrors();
        $this->contato(['tipo' => 'promessa', 'parcela_id' => $p1->id, 'promessa_data' => '2026-11-27', 'promessa_valor' => '1.000,00'])->assertSessionHasNoErrors();

        $prom = CobrancaRegistro::where('tipo', 'promessa')->sole();
        $this->assertSame('aguardando', $prom->situacaoPromessa());
        $this->assertSame(1000 * 100, $this->t->fresh()->load('parcelas.movimentos.estornoDe')->parcelas[0]->saldo(), 'promessa não abate nada');
        $this->assertSame(0, $this->conta->saldo());
        $this->assertTrue(Evento::where('acao', 'cobranca.promessa')->exists());

        // Recebeu R$ 600 (parcial): ainda não cumpriu. Recebeu o resto até a data: cumprida.
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $p1), ['conta_id' => $this->conta->id, 'data' => '2026-11-20', 'valor' => '600,00'])->assertSessionHasNoErrors();
        $this->assertSame('aguardando', $prom->fresh()->situacaoPromessa());
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00'));
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $p1), ['conta_id' => $this->conta->id, 'data' => '2026-11-26', 'valor' => '400,00'])->assertSessionHasNoErrors();
        $this->assertSame('cumprida', $prom->fresh()->situacaoPromessa());

        $this->como('financeiro@augi.local')->get(route('financeiro.titulo', [$this->t, 'aba' => 'cobranca']))->assertOk()
            ->assertSee('Lembrei do boleto vencido')->assertSee('Vai pagar sexta')->assertSee('Cumprida');
        $this->expectException(\LogicException::class);
        $prom->delete();
    }

    public function test_promessa_nao_cumprida_e_validacoes(): void
    {
        $p1 = $this->t->parcelas()->first();
        $this->contato(['tipo' => 'promessa', 'parcela_id' => $p1->id, 'promessa_data' => '2026-11-22', 'promessa_valor' => '1.000,00'])->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00'));
        $this->assertSame('quebrada', CobrancaRegistro::sole()->situacaoPromessa());

        $this->contato(['tipo' => 'promessa', 'parcela_id' => $p1->id, 'promessa_data' => '2026-11-30', 'promessa_valor' => '1.000,01'])
            ->assertSessionHasErrors(['tipo' => 'O valor prometido é maior que o saldo em aberto.']);
        $this->contato(['tipo' => 'promessa', 'promessa_valor' => '10,00'])->assertSessionHasErrors('tipo');
        $this->contato(['data' => '2026-12-01T10:00'])->assertSessionHasErrors('tipo');
        $this->como('contabilidade@augi.local')->post(route('cobranca.registrar', $this->t), ['tipo' => 'contato', 'data' => '2026-11-20T09:00'])->assertForbidden();
        $pagar = Titulo::create(['organizacao_id' => $this->empresa()->id, 'tipo' => 'pagar', 'descricao' => 'X', 'competencia' => '2026-11-01', 'valor_total_centavos' => 100]);
        $this->como('financeiro@augi.local')->post(route('cobranca.registrar', $pagar), ['tipo' => 'contato', 'data' => '2026-11-20T09:00'])
            ->assertSessionHasErrors(['tipo' => 'Histórico de cobrança é para contas a receber.']);
    }

    public function test_renegociacao_muda_datas_e_fica_no_historico(): void
    {
        [$p1, $p2] = $this->t->parcelas()->take(2)->get()->all();
        $this->como('financeiro@augi.local')->post(route('cobranca.renegociar', $this->t), [
            'parcelas' => [$p1->id, $p2->id], 'datas' => [$p1->id => '2026-12-10', $p2->id => '2027-01-10'], 'motivo' => 'Cliente pediu prazo', 'condicoes' => 'Multa de 2%',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['2026-12-10', '2027-01-10'], [$p1->fresh()->vencimento->toDateString(), $p2->fresh()->vencimento->toDateString()]);
        $r = CobrancaRegistro::where('tipo', 'renegociacao')->sole();
        $this->assertSame([['parcela' => 1, 'de' => '05/11/2026', 'para' => '10/12/2026', 'saldo' => 100000], ['parcela' => 2, 'de' => '05/12/2026', 'para' => '10/01/2027', 'saldo' => 100000]],
            $r->detalhes['parcelas']);
        $this->assertSame(2, Evento::where('acao', 'financeiro.vencimento_alterado')->count(), 'cada data mudada fica auditada');
        $this->assertSame(1000 * 100 * 3, $this->t->fresh()->valor_total_centavos, 'renegociar não muda o valor do título');
        $this->como('financeiro@augi.local')->get(route('contas_receber.index', ['aba' => 'renegociacoes']))->assertSee('Cliente pediu prazo')->assertSee('10/12/2026');
        $this->como('financeiro@augi.local')->get(route('contas_receber.index'))->assertSee('Renegociado neste mês: R$ 2.000,00');

        $this->como('financeiro@augi.local')->post(route('cobranca.renegociar', $this->t), ['parcelas' => [$p1->id], 'datas' => [], 'motivo' => 'x'])->assertSessionHasErrors('parcelas');
    }

    public function test_regua_de_cobranca_depois_do_vencimento(): void
    {
        $fin = User::where('email', 'financeiro@augi.local')->sole();
        $this->como('financeiro@augi.local')->put(route('configuracoes.vencimentos.salvar', 'receber'), ['ativo' => 1, 'dias' => [1, 0, -3, -7], 'hora' => '09:00'])->assertSessionHasNoErrors();
        $this->como('financeiro@augi.local')->put(route('configuracoes.vencimentos.salvar', 'pagar'), ['dias' => [-3], 'hora' => '09:00'])->assertSessionHasErrors('dias');

        // Parcela 1 venceu 05/11: 3 dias depois = 08/11, 7 dias depois = 12/11 (já passaram); parcela 2 vence 05/12.
        $this->travelTo(CarbonImmutable::parse('2026-12-08 09:05'));
        app(LembretesVencimento::class)->notificar();
        $titulos = Notificacao::where('user_id', $fin->id)->where('tipo', 'vencimento')->pluck('titulo')->all();
        $this->assertContains('Cobrar: atrasado há 3 dias: Projeto de implantação', $titulos, 'parcela 2 venceu 05/12');

        // Recebida, a régua para.
        $p2 = $this->t->parcelas()->where('numero', 2)->sole();
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $p2), ['conta_id' => $this->conta->id, 'data' => '2026-12-08', 'valor' => '1.000,00'])->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-12-12 09:05'));
        app(LembretesVencimento::class)->notificar();
        $this->assertFalse(Notificacao::where('user_id', $fin->id)->where('chave', 'venc:p'.$p2->id.':20261205:-7')->exists(), 'quitada: sem cobrança de 7 dias');

        // Google: só lembretes antes do vencimento.
        $this->assertSame([1440, 0], array_column(GoogleAgenda::overrides([1, 0, -3, -7], ['popup']), 'minutes'));
    }
}
