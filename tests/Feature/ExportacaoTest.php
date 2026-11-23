<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Evento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** Exportação XLSX/CSV: mesmos números da tela, isolamento por empresa, auditoria e arquivo temporário apagado. */
class ExportacaoTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        // Pasta própria desta execução: testes em paralelo (ex.: Infection) não apagam o arquivo um do outro.
        $this->pasta = sys_get_temp_dir().'/augi-exportacoes-'.getmypid().'-'.uniqid();
        config(['gestao.pasta_exportacoes' => $this->pasta]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
        DB::table('titulos')->update(['cancelado_em' => now()]);
        $conta = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Itaú PJ', 'tipo' => 'corrente', 'saldo_inicial_centavos' => 100000, 'saldo_inicial_em' => '2026-11-01']);
        $f = fn (array $d) => $this->como('financeiro@augi.local')->post(route('financeiro.store'), $d + ['competencia' => '2026-11', 'parcelas' => 1])->assertSessionHasNoErrors();
        $f(['tipo' => 'pagar', 'descricao' => 'Aluguel', 'contraparte' => 'Imobiliária', 'valor' => '1.234,56', 'vencimento' => '2026-11-25']);
        $f(['tipo' => 'receber', 'descricao' => 'Consultoria; "especial"', 'contraparte' => 'Cliente Ação', 'valor' => '500,00', 'vencimento' => '2026-11-10']);
        $this->como('financeiro@augi.local')->post(route('compras.store'), ['descricao' => 'Notebook', 'data_compra' => '2026-11-20', 'modalidade' => 'pix',
            'valor_bruto' => '4.500,00', 'parcelas' => 3, 'primeiro_vencimento' => '2026-12-10', 'conta_id' => $conta->id])->assertSessionHasNoErrors();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->pasta.'/*') ?: []); // respostas de teste não são "enviadas"
        @rmdir($this->pasta);
        parent::tearDown();
    }

    private function baixar(string $relatorio, array $q = [], string $email = 'financeiro@augi.local', string $cnpj = self::A): array
    {
        $r = $this->como($email, $cnpj)->get(route('financeiro.exportar', ['relatorio' => $relatorio] + $q));
        $r->assertOk();
        $arquivo = $r->baseResponse->getFile()->getPathname();
        $copia = tempnam(sys_get_temp_dir(), 'exp').'.'.($q['formato'] ?? 'xlsx');
        copy($arquivo, $copia);
        $leitor = SimpleExcelReader::create($copia);
        if (($q['formato'] ?? 'xlsx') === 'csv') {
            $leitor->useDelimiter(';');
        }
        $linhas = $leitor->getRows()->all();

        return [$r, $linhas, file_get_contents($copia)];
    }

    public function test_lancamentos_xlsx_tem_os_mesmos_valores_e_filtros_da_tela(): void
    {
        [$r, $l] = $this->baixar('lancamentos', ['tipo' => 'pagar']);
        $this->assertStringContainsString('lancamentos-2026-11-20.xlsx', $r->headers->get('content-disposition'));
        $this->assertSame(['Aluguel', 'Compra: Notebook'], array_column($l, 'Descrição'), 'só "a pagar", como o filtro da tela');
        $this->assertEquals(1234.56, $l[0]['Valor original']);
        $this->assertEquals(1234.56, $l[0]['Em aberto']);
        $this->assertSame('25/11/2026', $l[0]['Próximo vencimento']);
        $this->assertSame('Em aberto', $l[0]['Situação']);
        $this->assertSame(1, Evento::where('acao', 'financeiro.exportado')->count());
    }

    public function test_csv_brasileiro_com_acentos_e_campos_especiais(): void
    {
        [$r, $l, $bruto] = $this->baixar('lancamentos', ['formato' => 'csv', 'tipo' => 'receber']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $bruto, 'BOM: Excel abre acentos corretamente');
        $this->assertStringContainsString(';', $bruto);
        $this->assertSame('Consultoria; "especial"', $l[0]['Descrição'], 'ponto e vírgula e aspas não quebram a coluna');
        $this->assertSame('Cliente Ação', $l[0]['Contraparte']);
        $this->assertSame('500,00', $l[0]['Valor original']);
        $this->assertSame('Vencido', $l[0]['Situação']);
    }

    public function test_compras_inadimplencia_e_caixa(): void
    {
        [, $c] = $this->baixar('compras');
        $this->assertSame([['Notebook', 3, 4500.0, 0.0, 4500.0, 'Pix']],
            array_map(fn ($x) => [$x['Descrição'], (int) $x['Parcelas'], (float) $x['Total'], (float) $x['Pago'], (float) $x['Restante'], $x['Forma']], $c));

        [, $i] = $this->baixar('inadimplencia');
        $this->assertSame([['Cliente Ação', '10/11/2026', 10, '8-15 dias', 500.0]],
            array_map(fn ($x) => [$x['Cliente'], $x['Vencimento'], (int) $x['Dias de atraso'], $x['Faixa'], (float) $x['Em aberto']], $i));

        [, $k] = $this->baixar('caixa', ['dias' => 30]);
        $this->assertSame(['Saldo registrado', 'Aluguel', 'Compra: Notebook (1/3)'], array_column($k, 'Descrição'));
        $this->assertEquals([1000.0, -234.56, -1734.56], array_map(fn ($x) => (float) $x['Saldo após'], $k), 'saldo após cada compromisso, como na tela');
    }

    public function test_isolamento_permissoes_e_arquivo_temporario(): void
    {
        [, $b] = $this->baixar('lancamentos', ['periodo' => 'todos'], 'admin.b@augi.local', self::B);
        $this->assertNotContains('Aluguel', array_column($b, 'Descrição'), 'empresa B não vê dados da A');
        $this->como('contabilidade@augi.local')->get(route('financeiro.exportar', ['relatorio' => 'compras']))->assertOk(); // escritório consulta
        $this->como('prestador@augi.local')->get(route('financeiro.exportar', ['relatorio' => 'compras']))->assertForbidden();
        $this->como('financeiro@augi.local')->get(route('financeiro.exportar', ['relatorio' => 'senhas']))->assertNotFound();

        // Download entregue: o temporário é apagado depois do envio.
        $r = $this->como('financeiro@augi.local')->get(route('financeiro.exportar', ['relatorio' => 'compras']));
        $arquivo = $r->baseResponse->getFile()->getPathname();
        ob_start();
        $r->baseResponse->sendContent();
        ob_end_clean();
        $this->assertFileDoesNotExist($arquivo);

        // Download interrompido: a limpeza agendada apaga o que tiver mais de 1 hora.
        $velho = $this->pasta.'/velho.xlsx';
        $novo = $this->pasta.'/novo.xlsx';
        file_put_contents($velho, 'x');
        file_put_contents($novo, 'x');
        touch($velho, time() - 7200);
        $this->artisan('exportacoes:limpar')->assertSuccessful();
        $this->assertFileDoesNotExist($velho);
        $this->assertFileExists($novo);

    }
}
