<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Organizacao;
use App\Models\Parcela;
use App\Services\Financeiro;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConcorrenciaMariaDbTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrar;
    }

    public function runDatabaseMigrations(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true) || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Exige MariaDB e PCNTL para executar duas conexões concorrentes.');
        }
        $this->migrar();
    }

    public function test_duas_baixas_concorrentes_nao_ultrapassam_saldo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $org = Organizacao::where('cnpj', '11222333000181')->sole()->id;
        $data = now()->toDateString();
        $conta = ContaFinanceira::create(['organizacao_id' => $org, 'nome' => 'Teste concorrente', 'tipo' => 'corrente',
            'saldo_inicial_centavos' => 0, 'saldo_inicial_em' => $data]);
        $parcela = app(Financeiro::class)->criarTitulo($org, [
            'tipo' => 'receber', 'descricao' => 'Concorrência', 'competencia' => now()->startOfMonth()->toDateString(),
        ], 10000, 1, $data)->parcelas()->sole();

        DB::disconnect();
        $filhos = [];
        $sinais = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $this->assertIsArray($sockets);
                $pid = pcntl_fork();
                $this->assertGreaterThanOrEqual(0, $pid);
                if ($pid === 0) {
                    fclose($sockets[0]);
                    foreach ($sinais as $sinal) {
                        fclose($sinal);
                    }
                    if (fread($sockets[1], 1) !== '1') {
                        exit(3);
                    }
                    try {
                        app(Financeiro::class)->baixar($parcela, [
                            'conta_id' => $conta->id, 'data' => $data, 'valor' => 6000, 'chave' => 'concorrencia-'.$i,
                        ]);
                        exit(0);
                    } catch (ValidationException) {
                        exit(1);
                    } catch (\Throwable) {
                        exit(2);
                    }
                }
                fclose($sockets[1]);
                $sinais[] = $sockets[0];
                $filhos[] = $pid;
            }
            foreach ($sinais as $sinal) {
                fwrite($sinal, '1');
            }
        } finally {
            foreach ($sinais as $sinal) {
                fclose($sinal);
            }
        }

        $resultados = [];
        foreach ($filhos as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $resultados[] = pcntl_wexitstatus($status);
        }
        sort($resultados);
        $this->assertSame([0, 1], $resultados);
        $parcela = Parcela::findOrFail($parcela->id);
        $this->assertSame(1, $parcela->movimentos()->count());
        $this->assertSame(4000, $parcela->saldo());
    }
}
