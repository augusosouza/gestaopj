<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Notificacao;
use App\Models\User;
use App\Services\LembretesBancarios;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** FIN-ALERT-01 — lembretes de atualização de extrato por conta. */
class LembretesBancariosTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private ContaFinanceira $conta;

    protected function setUp(): void
    {
        parent::setUp();
        // Terça-feira, 24/11/2026, 10:00
        $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00'));
        $this->seed(DatabaseSeeder::class);
        $this->conta = ContaFinanceira::create([
            'organizacao_id' => $this->empresa()->id, 'nome' => 'Banco X — PJ', 'tipo' => 'corrente', 'saldo_inicial_em' => '2026-10-01',
            'lembrete_ativo' => true, 'lembrete_frequencia' => 'semanal', 'lembrete_dia' => 1, 'lembrete_hora' => '09:00', 'lembrete_repetir_dias' => 2,
        ]);
    }

    private function ofx(string $fim, string $movimento = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('extrato.ofx', "<OFX><BANKTRANLIST><DTSTART>20261101<DTEND>{$fim}\n{$movimento}</BANKTRANLIST></OFX>");
    }

    private function importar(UploadedFile $f): void
    {
        $this->como('financeiro@augi.local')->post(route('financeiro.importar', $this->conta), ['arquivo' => $f])->assertSessionHasNoErrors();
        $this->conta->refresh();
    }

    private function situacao(): array
    {
        return app(LembretesBancarios::class)->situacao($this->conta->fresh());
    }

    public function test_arquivo_antigo_nao_atualiza_so_o_periodo_coberto_conta(): void
    {
        $s = $this->situacao();
        $this->assertSame('desatualizado', $s['estado']);
        $this->assertSame('2026-11-22', $s['exigido_ate']->toDateString(), 'segunda 23/11 09:00 exige até domingo 22/11');

        // Enviado HOJE, mas cobre só até 12/11: continua pendente
        $this->importar($this->ofx('20261112', '<STMTTRN><DTPOSTED>20261110<TRNAMT>-10.00<FITID>1<MEMO>TARIFA</STMTTRN>'));
        $this->assertSame('2026-11-12', $this->conta->extrato_ate->toDateString());
        $this->assertSame('desatualizado', $this->situacao()['estado']);

        // Extrato até 22/11 (mesmo sem movimento no fim): em dia
        $this->importar($this->ofx('20261122'));
        $this->assertSame('em_dia', $this->situacao()['estado']);

        // Reimportar o arquivo antigo não faz a cobertura voltar
        $this->importar($this->ofx('20261112'));
        $this->assertSame('2026-11-22', $this->conta->fresh()->extrato_ate->toDateString());
        $this->assertDatabaseCount('extrato_importacoes', 3);
    }

    public function test_csv_usa_a_ultima_data_com_lancamento(): void
    {
        $this->importar(UploadedFile::fake()->createWithContent('e.csv', "data;descricao;valor\n20/11/2026;PIX;10,00\n22/11/2026;TARIFA;-5,00\n"));
        $this->assertSame('2026-11-22', $this->conta->extrato_ate->toDateString());
        $this->assertSame('em_dia', $this->situacao()['estado']);
    }

    public function test_antes_do_horario_vale_o_prazo_anterior(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 08:00')); // segunda, antes das 09:00
        $this->assertSame('2026-11-22', $this->situacao()['exigido_ate']->toDateString());
        $this->travelTo(CarbonImmutable::parse('2026-11-30 09:30'));
        $this->assertSame('2026-11-29', $this->situacao()['exigido_ate']->toDateString());
    }

    public function test_mensal_e_conta_nova_nao_cobrada_por_periodo_anterior(): void
    {
        $this->conta->update(['lembrete_frequencia' => 'mensal', 'lembrete_dia' => 5]);
        $this->assertSame('2026-11-04', $this->situacao()['exigido_ate']->toDateString());
        $this->conta->update(['saldo_inicial_em' => '2026-11-20']);
        $this->assertSame('em_dia', $this->situacao()['estado'], 'conta cadastrada depois do período exigido');
    }

    public function test_notifica_repete_no_intervalo_e_respeita_responsavel(): void
    {
        $l = app(LembretesBancarios::class);
        $this->assertSame(2, $l->notificar(), 'admin e financeiro da empresa A');
        $this->assertSame(0, $l->notificar(), 'não repete no mesmo intervalo');
        $this->travel(2)->days();
        $this->assertSame(2, $l->notificar(), 'repete após 2 dias de atraso');

        $fin = User::where('email', 'financeiro@augi.local')->sole();
        $this->conta->update(['lembrete_responsavel_id' => $fin->id]);
        $this->travel(2)->days();
        $this->assertSame(1, $l->notificar());
        $n = Notificacao::where('user_id', $fin->id)->latest('id')->first();
        $this->assertStringContainsString('Banco X — PJ', $n->mensagem);
        $this->assertSame('extrato_pendente', $n->tipo);
    }

    public function test_dispensa_suspende_e_fica_na_auditoria(): void
    {
        $this->como('financeiro@augi.local')->post(route('financeiro.conta_dispensar', $this->conta), ['ate' => '2026-11-30', 'motivo' => 'Conta sem movimento'])
            ->assertSessionHasNoErrors();
        $this->assertSame('dispensado', $this->situacao()['estado']);
        $this->assertSame(0, app(LembretesBancarios::class)->notificar());
        $this->assertDatabaseHas('eventos', ['acao' => 'financeiro.lembrete_dispensado']);
        $this->como('financeiro@augi.local')->post(route('financeiro.conta_dispensar', $this->conta), ['ate' => '2027-06-01', 'motivo' => 'x'])
            ->assertSessionHasErrors('ate');
    }

    public function test_conciliacao_pendente_e_alerta_separado(): void
    {
        $this->importar($this->ofx('20261122', '<STMTTRN><DTPOSTED>20261110<TRNAMT>-45.90<FITID>T1<MEMO>TARIFA</STMTTRN>'));
        $this->assertSame('em_dia', $this->situacao()['estado']);
        $this->assertSame(1, $this->situacao()['a_conciliar']);
        app(LembretesBancarios::class)->notificar();
        $this->assertSame(0, Notificacao::where('tipo', 'extrato_pendente')->count());
        $this->assertSame(2, Notificacao::where('tipo', 'conciliacao_pendente')->count());
    }

    public function test_tela_configuracao_permissoes_e_notificacoes(): void
    {
        $this->como('financeiro@augi.local')->put(route('financeiro.conta_lembrete', $this->conta), [
            'lembrete_ativo' => 1, 'lembrete_frequencia' => 'mensal', 'lembrete_dia' => 31, 'lembrete_hora' => '08:30', 'lembrete_antecedencia' => 2, 'lembrete_repetir_dias' => 3,
        ])->assertSessionHasErrors('lembrete_dia');
        $this->como('financeiro@augi.local')->put(route('financeiro.conta_lembrete', $this->conta), [
            'lembrete_ativo' => 1, 'lembrete_frequencia' => 'mensal', 'lembrete_dia' => 10, 'lembrete_hora' => '08:30', 'lembrete_antecedencia' => 2, 'lembrete_repetir_dias' => 3,
        ])->assertSessionHasNoErrors();
        $this->assertSame('todo dia 10 do mês às 08:30', $this->conta->fresh()->regraLembrete());
        $this->como('contabilidade@augi.local')->put(route('financeiro.conta_lembrete', $this->conta), [])->assertForbidden();
        foreach (['resumo', 'lembrete', 'importacoes'] as $aba) {
            $this->como('financeiro@augi.local')->get(route('financeiro.conta', [$this->conta, 'aba' => $aba]))->assertOk();
        }
        $this->como('financeiro@augi.local')->get(route('financeiro.contas'))->assertOk()->assertSee('Atualização pendente');

        // Notificações: abre, marca lida, e outro usuário não abre
        app(LembretesBancarios::class)->notificar();
        $n = Notificacao::where('user_id', User::where('email', 'financeiro@augi.local')->sole()->id)->firstOrFail();
        $this->como('financeiro@augi.local')->get(route('notificacoes.index'))->assertOk()->assertSee('Extrato bancário pendente');
        $this->como('admin.b@augi.local', self::B)->get(route('notificacoes.abrir', $n))->assertNotFound();
        $this->como('financeiro@augi.local')->get(route('notificacoes.abrir', $n))->assertRedirect(route('financeiro.conta', $this->conta));
        $this->assertNotNull($n->fresh()->lida_em);
    }
}
