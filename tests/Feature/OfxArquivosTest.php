<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\ExtratoLinha;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ComContexto;
use Tests\TestCase;

/**
 * Extratos OFX no formato real dos bancos (tests/fixtures/ofx).
 * Cada arquivo .ofx tem ao lado um .esperado.json conferido à mão contra o extrato do banco.
 * Para incluir um arquivo real: anonimize com `php artisan ofx:anonimizar`, confira o
 * .esperado.json gerado e coloque os dois nesta pasta — o teste roda sozinho.
 */
class OfxArquivosTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    public static function arquivos(): array
    {
        $out = [];
        // O provider roda antes do Laravel subir: caminho relativo ao arquivo, sem base_path().
        foreach (glob(dirname(__DIR__).'/fixtures/ofx/*.ofx') as $f) {
            $esperado = json_decode(file_get_contents(substr($f, 0, -4).'.esperado.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($esperado['conferido'] ?? false) { // rascunho do ofx:anonimizar ainda não conferido à mão: fica de fora
                $out[basename($f)] = [$f, $esperado];
            }
        }

        return $out;
    }

    public function test_anonimizador_remove_dados_pessoais(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ofx');
        file_put_contents($tmp, mb_convert_encoding("<OFX><BANKACCTFROM><BANKID>0341<BRANCHID>1234<ACCTID>98765-4</BANKACCTFROM><BANKTRANLIST><DTSTART>20261101<DTEND>20261130\n"
            .'<STMTTRN><DTPOSTED>20261105<TRNAMT>-50.00<FITID>ABC123<MEMO>PIX ENVIADO JOÃO DA SILVA 123.456.789-00</STMTTRN></BANKTRANLIST>'
            .'<LEDGERBAL><BALAMT>100.00<DTASOF>20261130</LEDGERBAL></OFX>', 'Windows-1252', 'UTF-8'));
        $this->artisan('ofx:anonimizar', ['entrada' => $tmp, 'nome' => 'Teste Anonimizacao'])->assertSuccessful();
        $base = base_path('tests/fixtures/ofx/teste-anonimizacao');
        try {
            $ofx = file_get_contents($base.'.ofx');
            foreach (['1234', '98765', 'JOÃO', 'SILVA', '123.456', 'ABC123'] as $sensivel) {
                $this->assertStringNotContainsString($sensivel, $ofx);
            }
            $this->assertStringContainsString('<MEMO>PIX ENVIADO X X X X', $ofx);
            $this->assertStringContainsString('<BANKID>0341', $ofx);
            $e = json_decode(file_get_contents($base.'.esperado.json'), true);
            $this->assertFalse($e['conferido'], 'rascunho não entra nos testes até ser conferido');
            $this->assertSame([['2026-11-05', -5000, 'PIX ENVIADO X X X X']], $e['linhas']);
            $this->assertSame([10000, '2026-11-30'], [$e['saldo_banco'], $e['saldo_em']]);
        } finally {
            @unlink($base.'.ofx');
            @unlink($base.'.esperado.json');
            @unlink($tmp);
        }
    }

    #[DataProvider('arquivos')]
    public function test_importa_como_o_banco_informou(string $arquivo, array $esperado): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00'));
        $this->seed(DatabaseSeeder::class);
        $conta = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Conta', 'tipo' => 'corrente', 'saldo_inicial_em' => '2026-10-01']);
        $enviar = fn () => $this->como('financeiro@augi.local')->post(route('financeiro.importar', $conta),
            ['arquivo' => UploadedFile::fake()->createWithContent(basename($arquivo), file_get_contents($arquivo))]);

        $enviar()->assertSessionHasNoErrors();
        $linhas = ExtratoLinha::where('conta_id', $conta->id)->orderBy('data')->orderBy('id')->get()
            ->map(fn ($l) => [$l->data->toDateString(), $l->valor_centavos, $l->descricao])->all();
        $this->assertSame($esperado['linhas'], $linhas, $esperado['descricao']);

        $conta->refresh();
        $this->assertSame($esperado['periodo'][1], $conta->extrato_ate?->toDateString(), 'período coberto (DTEND)');
        $this->assertSame($esperado['saldo_banco'], $conta->saldo_extrato_centavos, 'saldo informado pelo banco (LEDGERBAL)');
        $this->assertSame($esperado['saldo_em'], $conta->saldo_extrato_em?->toDateString());

        $enviar()->assertSessionHasNoErrors(); // reimportar não duplica
        $this->assertSame(count($esperado['linhas']), ExtratoLinha::where('conta_id', $conta->id)->count());
    }
}
