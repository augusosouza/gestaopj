<?php

namespace Tests\Feature;

use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\Titulo;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Relatorios;
use App\Support\Contexto;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** Augi 3.0, etapa 9 — relatórios gerenciais e DRE gerencial batendo com os lançamentos. */
class RelatoriosTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private ContaFinanceira $conta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
        DB::table('titulos')->update(['cancelado_em' => now()]);
        config(['gestao.pasta_exportacoes' => sys_get_temp_dir().'/augi-rel-'.getmypid().'-'.uniqid()]);
        $this->como('financeiro@augi.local')->get(route('financeiro.categorias'))->assertOk(); // cria as categorias padrão
        $this->conta = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Itaú', 'tipo' => 'corrente', 'saldo_inicial_em' => '2026-01-01']);

        // Outubro e novembro: receita, imposto, prestador, aluguel, tarifa, rendimento.
        $l = [
            ['receber', 'Receita de serviços', 'Consultoria', '10.000,00', '2026-10', 'Cliente A'],
            ['receber', 'Receita de serviços', 'Consultoria', '12.000,00', '2026-11', 'Cliente A'],
            ['receber', 'Rendimentos financeiros', 'Rendimento', '50,00', '2026-11', 'Banco'],
            ['pagar', 'Impostos e taxas', 'Simples Nacional', '1.200,00', '2026-11', 'Receita Federal'],
            ['pagar', 'Prestadores de serviço', 'Consultor', '5.000,00', '2026-11', 'Consultor ME'],
            ['pagar', 'Aluguel e condomínio', 'Aluguel', '2.000,00', '2026-11', 'Imobiliária'],
            ['pagar', 'Tarifas bancárias', 'Tarifa', '30,00', '2026-11', 'Banco'],
        ];
        foreach ($l as [$tipo, $cat, $desc, $valor, $comp, $quem]) {
            $this->como('financeiro@augi.local')->post(route('financeiro.store'), ['tipo' => $tipo, 'descricao' => $desc.' '.$comp, 'contraparte' => $quem,
                'categoria_id' => CategoriaFinanceira::where('nome', $cat)->value('id'), 'competencia' => $comp, 'valor' => $valor, 'parcelas' => 1,
                'vencimento' => $comp.'-10'])->assertSessionHasNoErrors();
        }
    }

    private function rel(string $slug, string $de = '2026-10', string $ate = '2026-11', string $regime = 'competencia'): array
    {
        $u = User::where('email', 'financeiro@augi.local')->sole();
        $this->actingAs($u);
        app(Contexto::class)->ativar($u, ['vinculo' => Vinculo::vigentes()->where('user_id', $u->id)->where('organizacao_id', $this->empresa()->id)->value('id'), 'empresa' => null]);

        return app(Relatorios::class)->gerar($slug, CarbonImmutable::parse($de.'-01'), CarbonImmutable::parse($ate.'-01')->endOfMonth(), $regime);
    }

    private function linha(array $r, string $rotulo): array
    {
        return collect($r['estrutura']['dre'])->firstWhere('rotulo', $rotulo) ?? $this->fail("Linha {$rotulo} ausente");
    }

    public function test_dre_por_competencia_fecha_com_os_lancamentos(): void
    {
        $r = $this->rel('dre');
        $this->assertSame([1000000, 1200000], $this->linha($r, 'Receita bruta')['valores']);
        $this->assertSame([0, 120000], $this->linha($r, 'Deduções e impostos sobre a receita')['valores']);
        $this->assertSame([1000000, 1080000], $this->linha($r, '(=) Receita líquida')['valores']);
        $this->assertSame([1000000, 580000], $this->linha($r, '(=) Lucro bruto')['valores'], 'menos prestadores (custo)');
        $this->assertSame([1000000, 380000], $this->linha($r, '(=) Resultado operacional')['valores'], 'menos aluguel');
        $this->assertSame([1000000, 382000], $this->linha($r, '(=) Resultado antes de IRPJ/CSLL')['valores'], '+50 rendimento −30 tarifa');
        $this->assertSame(1382000, $this->linha($r, '(=) Resultado líquido')['total']);
        $this->assertSame(66, $r['estrutura']['margem'], '13.820 ÷ 20.800');
        $this->assertSame('Receita de serviços', trim(collect($r['estrutura']['dre'])->firstWhere('nivel', 1)['rotulo']));

        // Conferência independente: resultado = receitas − despesas lançadas no período.
        $rec = (int) Titulo::where('tipo', 'receber')->whereNull('cancelado_em')->sum('valor_total_centavos');
        $desp = (int) Titulo::where('tipo', 'pagar')->whereNull('cancelado_em')->sum('valor_total_centavos');
        $this->assertSame($rec - $desp, $this->linha($r, '(=) Resultado líquido')['total']);
    }

    public function test_dre_por_caixa_so_conta_o_que_foi_pago(): void
    {
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', Titulo::where('descricao', 'Consultoria 2026-11')->sole()->parcelas()->first()),
            ['conta_id' => $this->conta->id, 'data' => '2026-11-15', 'valor' => '12.000,00'])->assertSessionHasNoErrors();
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', Titulo::where('descricao', 'Aluguel 2026-11')->sole()->parcelas()->first()),
            ['conta_id' => $this->conta->id, 'data' => '2026-11-15', 'valor' => '2.000,00'])->assertSessionHasNoErrors();
        $r = $this->rel('dre', '2026-11', '2026-11', 'caixa');
        $this->assertSame(1200000, $this->linha($r, 'Receita bruta')['total']);
        $this->assertSame(1000000, $this->linha($r, '(=) Resultado líquido')['total']);

        $f = $this->rel('fluxo-caixa', '2026-11', '2026-11');
        $this->assertSame([['11/2026', 1200000, 200000, 1000000, 1000000]], $f['linhas']);
        $this->assertSame($this->conta->saldo(), $f['linhas'][0][4], 'fluxo do período = variação do saldo da conta');
    }

    public function test_categoria_muda_de_linha_na_dre(): void
    {
        $alug = CategoriaFinanceira::where('nome', 'Aluguel e condomínio')->sole();
        $this->como('financeiro@augi.local')->put(route('financeiro.categoria_dre', $alug), ['grupo_dre' => 'custos'])->assertSessionHasNoErrors();
        $this->assertSame(380000, $this->linha($this->rel('dre', '2026-11', '2026-11'), '(=) Lucro bruto')['total']);
        $this->como('financeiro@augi.local')->put(route('financeiro.categoria_dre', $alug), ['grupo_dre' => 'receita_bruta'])->assertSessionHasErrors('grupo_dre');
        // Subcategoria herda a linha da mãe.
        $this->como('financeiro@augi.local')->post(route('financeiro.categoria_salvar'), ['tipo' => 'despesa', 'nome' => 'IPTU', 'pai_id' => $alug->id])->assertSessionHasNoErrors();
        $this->assertSame('custos', CategoriaFinanceira::where('nome', 'IPTU')->sole()->grupoDre());
    }

    public function test_outros_relatorios_e_exportacao(): void
    {
        $prev = $this->rel('previsto-realizado', '2026-11', '2026-11');
        $this->assertSame([['11/2026', 1205000, 0, 0.0, 823000, 0, 0.0]], $prev['linhas']);
        $desp = $this->rel('despesas-categoria', '2026-11', '2026-11');
        $this->assertSame(['Prestadores de serviço', 500000], array_slice($desp['linhas'][0], 0, 2));
        $this->assertSame(823000, $desp['total'][1]);
        $cli = $this->rel('clientes');
        $this->assertSame(['Cliente A', 2200000, 0, 2200000], array_slice($cli['linhas'][0], 0, 4));
        $this->assertSame(2200000, $cli['linhas'][0][4], 'vencido: as duas consultorias (10/10 e 10/11) já venceram em 20/11');

        foreach (array_keys(Relatorios::LISTA) as $slug) {
            $this->como('financeiro@augi.local')->get(route('relatorios.show', [$slug, 'de' => '2026-10', 'ate' => '2026-11']))->assertOk();
        }
        $this->como('financeiro@augi.local')->get(route('relatorios.index'))->assertOk()->assertSee('DRE gerencial');
        $this->como('financeiro@augi.local')->get(route('relatorios.show', ['dre', 'de' => '2026-11', 'ate' => '2026-11']))
            ->assertSee('Não substitui a DRE contábil')->assertSee('R$ 3.820,00');

        $resp = $this->como('financeiro@augi.local')->get(route('relatorios.exportar', ['dre', 'de' => '2026-11', 'ate' => '2026-11', 'formato' => 'xlsx']))->assertOk();
        $linhas = SimpleExcelReader::create($resp->baseResponse->getFile()->getPathname(), 'xlsx')->getRows()->all();
        $final = collect($linhas)->first(fn ($l) => str_contains($l['Linha'], 'Resultado líquido'));
        $this->assertEquals(3820.0, $final['Total']);
        @unlink($resp->baseResponse->getFile()->getPathname());

        $this->como('prestador@augi.local')->get(route('relatorios.show', 'dre'))->assertForbidden();
        $this->como('contabilidade@augi.local')->get(route('relatorios.show', 'dre'))->assertOk();
        $this->como('admin.b@augi.local', self::B)->get(route('relatorios.show', ['clientes', 'de' => '2026-10', 'ate' => '2026-11']))->assertOk()->assertDontSee('Cliente A');
        $this->como('financeiro@augi.local')->get(route('relatorios.show', 'inexistente'))->assertNotFound();
    }
}
