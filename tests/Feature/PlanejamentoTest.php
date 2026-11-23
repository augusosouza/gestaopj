<?php

namespace Tests\Feature;

use App\Http\Controllers\PlanejamentoController;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\Orcamento;
use App\Models\Titulo;
use App\Models\TituloRateio;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Assistente;
use App\Services\Planejamento;
use App\Services\Sugestoes;
use App\Support\Contexto;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** Augi 3.0, etapa 8 — subcategorias, centros de custo, rateio exato e orçamentos. */
class PlanejamentoTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private CategoriaFinanceira $tec;

    private CategoriaFinanceira $serv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
        DB::table('titulos')->update(['cancelado_em' => now()]);
        $this->post_('financeiro.categoria_salvar', ['tipo' => 'despesa', 'nome' => 'Tecnologia']);
        $this->tec = CategoriaFinanceira::where('nome', 'Tecnologia')->sole();
        $this->post_('financeiro.categoria_salvar', ['tipo' => 'despesa', 'nome' => 'Servidores', 'pai_id' => $this->tec->id]);
        $this->serv = CategoriaFinanceira::where('nome', 'Servidores')->sole();
        foreach (['Operação', 'Comercial', 'Administrativo'] as $n) {
            $this->post_('planejamento.centro', ['nome' => $n, 'tipo' => 'departamento']);
        }
    }

    private function post_(string $rota, array $d, $param = null)
    {
        return $this->como('financeiro@augi.local')->post(route($rota, $param), $d)->assertSessionHasNoErrors();
    }

    private function despesa(string $desc, string $valor, ?int $cat, ?int $centro = null, string $comp = '2026-11'): Titulo
    {
        $this->post_('financeiro.store', ['tipo' => 'pagar', 'descricao' => $desc, 'categoria_id' => $cat, 'centro_custo_id' => $centro,
            'competencia' => $comp, 'valor' => $valor, 'parcelas' => 1, 'vencimento' => '2026-11-25']);

        return Titulo::where('descricao', $desc)->sole();
    }

    private function cc(string $nome): int
    {
        return CentroCusto::where('nome', $nome)->value('id');
    }

    private function ctx(): void
    {
        $u = User::where('email', 'financeiro@augi.local')->sole();
        $this->actingAs($u);
        app(Contexto::class)->ativar($u, ['vinculo' => Vinculo::vigentes()->where('user_id', $u->id)->where('organizacao_id', $this->empresa()->id)->value('id'), 'empresa' => null]);
    }

    public function test_rateio_soma_exatamente_o_valor(): void
    {
        $t = $this->despesa('Licença de software', '100,00', $this->serv->id);
        $this->como('financeiro@augi.local')->put(route('planejamento.ratear', $t), ['rateio' => [
            $this->cc('Operação') => '33,34', $this->cc('Comercial') => '33,33', $this->cc('Administrativo') => '33,33']])->assertSessionHasNoErrors();
        $this->assertSame(10000, (int) TituloRateio::sum('valor_centavos'));
        $this->assertSame([3334, 3333, 3333], TituloRateio::orderBy('id')->pluck('valor_centavos')->map(fn ($v) => (int) $v)->all());

        $this->como('financeiro@augi.local')->put(route('planejamento.ratear', $t), ['rateio' => [$this->cc('Operação') => '50', $this->cc('Comercial') => '40']])
            ->assertSessionHasErrors(['rateio' => 'A soma dos percentuais precisa ser 100% (está em 90,00%).']);
        $this->como('financeiro@augi.local')->put(route('planejamento.ratear', $t), ['rateio' => [$this->cc('Operação') => 'abc']])->assertSessionHasErrors('rateio');
        $this->assertSame(3, TituloRateio::count(), 'erro não apaga o rateio anterior');
        $this->assertSame('sem rateio', Evento::where('acao', 'financeiro.rateio')->sole()->dados['de']);

        // Vazio remove; cadastro com centro já sai 100% rateado.
        $this->como('financeiro@augi.local')->put(route('planejamento.ratear', $t), ['rateio' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, TituloRateio::count());
        $t2 = $this->despesa('Viagem', '999,99', null, $this->cc('Comercial'));
        $this->assertSame([[10000, 99999]], $t2->rateios()->get()->map(fn ($r) => [(int) $r->percentual_bp, (int) $r->valor_centavos])->all());
    }

    public function test_rateio_ignora_centro_inativo_e_de_outra_empresa(): void
    {
        $t = $this->despesa('X', '10,00', null);
        $this->como('financeiro@augi.local')->patch(route('planejamento.centro_alternar', $this->cc('Comercial')))->assertSessionHasNoErrors();
        $this->como('financeiro@augi.local')->put(route('planejamento.ratear', $t), ['rateio' => [$this->cc('Comercial') => '100']])
            ->assertSessionHasErrors(['rateio' => 'Centro de custo inválido ou inativo.']);
        $this->como('admin.b@augi.local', self::B)->post(route('planejamento.centro'), ['nome' => 'Centro B', 'tipo' => 'projeto'])->assertSessionHasNoErrors();
        $deB = CentroCusto::withoutGlobalScope('organizacao')->where('nome', 'Centro B')->value('id');
        $this->como('financeiro@augi.local')->put(route('planejamento.ratear', $t), ['rateio' => [$deB => '100']])->assertSessionHasErrors('rateio');
        $this->como('financeiro@augi.local')->patch(route('planejamento.centro_alternar', $deB))->assertNotFound();
        $this->como('financeiro@augi.local')->get(route('planejamento.index', ['aba' => 'centros']))->assertDontSee('Centro B');
    }

    public function test_orcamento_por_categoria_inclui_subcategorias_e_alerta(): void
    {
        $this->post_('planejamento.orcamento', ['competencia' => '2026-11', 'alvo' => 'cat:'.$this->tec->id, 'valor' => '1.000,00']);
        $conta = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Itaú', 'tipo' => 'corrente', 'saldo_inicial_em' => '2026-11-01']);
        $a = $this->despesa('Servidor', '600,00', $this->serv->id);           // subcategoria conta
        $this->despesa('Notebook', '300,00', $this->tec->id);
        $this->despesa('Outro mês', '5.000,00', $this->tec->id, null, '2026-12'); // fora do mês
        $this->post_('financeiro.baixar', ['conta_id' => $conta->id, 'data' => '2026-11-20', 'valor' => '600,00'], $a->parcelas()->first());

        $this->ctx();
        $o = app(Planejamento::class)->acompanhamento(CarbonImmutable::parse('2026-11-01'))->sole();
        $this->assertSame(['Tecnologia', 100000, 90000, 60000, 10000, 90, 'alerta'],
            [$o['rotulo'], $o['orcado'], $o['comprometido'], $o['realizado'], $o['disponivel'], $o['pct'], $o['estado']]);
        $this->assertTrue(app(Sugestoes::class)->gerar()->contains('titulo', 'Orçamento perto do limite: Tecnologia'));

        $this->despesa('Mais um', '200,00', $this->serv->id);
        $this->ctx();
        $o = app(Planejamento::class)->acompanhamento(CarbonImmutable::parse('2026-11-01'))->sole();
        $this->assertSame(['excedido', -10000], [$o['estado'], $o['disponivel']]);
        $s = app(Sugestoes::class)->gerar()->firstWhere('tipo', 'orcamento');
        $this->assertSame(['bad', 'Orçamento ultrapassado: Tecnologia'], [$s['nivel'], $s['titulo']]);
        $r = app(Assistente::class)->responder('Quais despesas ultrapassaram o orçamento?');
        $this->assertSame(['orcamento', '1 ultrapassado(s)'], [$r['intencao'], $r['destaque']]);

        $this->como('financeiro@augi.local')->get(route('planejamento.index', ['competencia' => '2026-11']))->assertOk()
            ->assertSee('1 orçamento(s) ultrapassado(s)')->assertSee('R$ 1.100,00');
    }

    public function test_orcamento_por_centro_usa_o_valor_rateado(): void
    {
        $this->post_('planejamento.orcamento', ['competencia' => '2026-11', 'alvo' => 'cc:'.$this->cc('Comercial'), 'valor' => '500,00']);
        $t = $this->despesa('Feira', '1.000,00', null);
        $this->como('financeiro@augi.local')->put(route('planejamento.ratear', $t), ['rateio' => [$this->cc('Comercial') => '30', $this->cc('Operação') => '70']]);
        $this->ctx();
        $o = app(Planejamento::class)->acompanhamento(CarbonImmutable::parse('2026-11-01'))->sole();
        $this->assertSame([30000, 60, 'ok'], [$o['comprometido'], $o['pct'], $o['estado']]);
        $porCentro = app(Planejamento::class)->porCentro(CarbonImmutable::parse('2026-11-01'))->pluck('valor', 'rotulo')->all();
        $this->assertSame(['Operação' => 70000, 'Comercial' => 30000], $porCentro);

        // Copiar para dezembro; regras de alvo.
        $this->post_('planejamento.copiar', ['competencia' => '2026-12']);
        $this->assertSame(1, Orcamento::whereDate('competencia', '2026-12-01')->count());
        $this->como('financeiro@augi.local')->post(route('planejamento.orcamento'), ['competencia' => '2026-11', 'alvo' => 'cat:'.$this->tec->id, 'valor' => '0'])->assertSessionHasErrors('valor');
        $receita = CategoriaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'tipo' => 'receita', 'nome' => 'Serviços '.uniqid()]);
        $this->como('financeiro@augi.local')->post(route('planejamento.orcamento'), ['competencia' => '2026-11', 'alvo' => 'cat:'.$receita->id, 'valor' => '10,00'])->assertSessionHasErrors('valor');
    }

    public function test_subcategorias_comparativo_permissoes_e_telas(): void
    {
        $this->assertSame('Tecnologia → Servidores', $this->serv->caminho());
        $this->post_('financeiro.categoria_salvar', ['tipo' => 'despesa', 'nome' => 'Nuvem', 'pai_id' => $this->serv->id]);
        $nuvem = CategoriaFinanceira::where('nome', 'Nuvem')->sole();
        $this->como('financeiro@augi.local')->post(route('financeiro.categoria_salvar'), ['tipo' => 'despesa', 'nome' => 'Quarto nível', 'pai_id' => $nuvem->id])
            ->assertSessionHasErrors('pai_id');
        $receita = CategoriaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'tipo' => 'receita', 'nome' => 'Serviços '.uniqid()]);
        $this->como('financeiro@augi.local')->post(route('financeiro.categoria_salvar'), ['tipo' => 'despesa', 'nome' => 'Mista', 'pai_id' => $receita->id])->assertSessionHasErrors('pai_id');

        foreach (['2026-09' => '100,00', '2026-10' => '100,00', '2026-11' => '400,00'] as $m => $v) {
            $this->despesa('Nuvem '.$m, $v, $nuvem->id, null, $m);
        }
        $this->ctx();
        $c = app(Planejamento::class)->comparativo(CarbonImmutable::parse('2026-11-01'), 3);
        $l = $c['linhas']->firstWhere('rotulo', 'Tecnologia');
        $this->assertSame([[10000, 10000, 40000], 300], [$l['valores'], $l['variacao']], 'agrupa pela categoria principal; +300% sobre a média');

        foreach (array_keys(PlanejamentoController::ABAS) as $aba) {
            $this->como('financeiro@augi.local')->get(route('planejamento.index', ['aba' => $aba]))->assertOk();
        }
        $this->como('contabilidade@augi.local')->get(route('planejamento.index'))->assertOk()->assertDontSee('Definir orçamento');
        $this->como('contabilidade@augi.local')->post(route('planejamento.orcamento'), ['competencia' => '2026-11', 'alvo' => 'cat:'.$this->tec->id, 'valor' => '1,00'])->assertForbidden();
        $this->como('prestador@augi.local')->get(route('planejamento.index'))->assertForbidden();
        $this->como('financeiro@augi.local')->get(route('financeiro.categorias'))->assertSee('Tecnologia → Servidores');
    }

    public function test_ratear_unitario(): void
    {
        $this->assertSame([30000, 18000, 12000], Dinheiro::ratear(60000, [5000, 3000, 2000]));
        $this->assertSame([3334, 3333, 3333], Dinheiro::ratear(10000, [1, 1, 1]));
        $this->assertSame(['a' => 1, 'b' => 0], Dinheiro::ratear(1, ['a' => 1, 'b' => 1]), 'empate: a primeira recebe');
        $this->assertSame([0, 2], Dinheiro::ratear(2, [0, 1]));
        $this->assertSame([33, 67], Dinheiro::ratear(100, [3333, 6667]));
        $this->expectException(\InvalidArgumentException::class);
        Dinheiro::ratear(100, [0, 0]);
    }
}
