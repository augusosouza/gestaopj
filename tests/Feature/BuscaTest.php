<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\Contrato;
use App\Models\ExtratoLinha;
use App\Models\Fatura;
use App\Models\NotaFiscal;
use App\Models\Titulo;
use App\Models\VisaoSalva;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComContexto;
use Tests\TestCase;

class BuscaTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    public function test_pesquisa_documento_formatado_com_isolamento_e_permissoes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cliente = Cliente::create(['organizacao_id' => $this->empresa()->id,
            'razao_social' => 'Empresa Pesquisa Alfa', 'documento' => '12ABC34501DE35']);
        $externo = Cliente::create(['organizacao_id' => $this->empresa(self::B)->id,
            'razao_social' => 'Empresa Pesquisa Externa', 'documento' => '12ABC34501DE35']);

        $this->como('admin@augi.local')->post(route('busca'), ['q' => '12.ABC.345/01DE-35'])
            ->assertOk()->assertSee(route('clientes.show', $cliente))->assertDontSee(route('clientes.show', $externo))
            ->assertDontSee('Empresa Pesquisa Externa');
        $this->post(route('busca'), ['q' => 'Empresa Pesquisa'])->assertOk()
            ->assertSee('Empresa Pesquisa Alfa')->assertDontSee('Empresa Pesquisa Externa');
        $this->post(route('busca'), ['q' => '%_'])->assertOk()->assertSee('Nenhum resultado encontrado');
        $this->como('financeiro@augi.local')->post(route('busca'), ['q' => 'Empresa Pesquisa Alfa'])
            ->assertOk()->assertDontSee(route('clientes.show', $cliente));
        $this->como('prestador@augi.local')->post(route('busca'), ['q' => 'Relatórios'])->assertForbidden();
    }

    public function test_busca_de_faturas_respeita_empresa_competencia_e_situacao(): void
    {
        $this->seed(DatabaseSeeder::class);
        $faturas = [];
        foreach ([self::A, self::B] as $cnpj) {
            $org = $this->empresa($cnpj)->id;
            $contrato = Contrato::where('organizacao_id', $org)->firstOrFail();
            $faturas[] = Fatura::create(['organizacao_id' => $org, 'contrato_id' => $contrato->id,
                'competencia' => '2026-11-01', 'vencimento' => '2026-12-10', 'valor_centavos' => 100, 'status' => 'aberta']);
        }
        [$local, $externa] = $faturas;
        $this->como('financeiro@augi.local')->post(route('busca'), ['q' => '#'.$externa->id])
            ->assertOk()->assertDontSee(route('faturas.show', $externa));
        $this->post(route('busca'), ['q' => '#'.$local->id])->assertOk()->assertSee(route('faturas.show', $local));
        $this->get(route('faturas.index', ['q' => '#'.$local->id, 'competencia' => '2026-11', 'situacao' => 'aberta']))
            ->assertOk()->assertSee(route('faturas.show', $local));
        $this->get(route('faturas.index', ['q' => '#'.$local->id, 'competencia' => '2026-11', 'situacao' => 'cancelada']))
            ->assertOk()->assertDontSee(route('faturas.show', $local));
    }

    public function test_visao_salva_pertence_ao_usuario_e_nao_aceita_filtros_arbitrarios(): void
    {
        $this->seed(DatabaseSeeder::class);
        $filtros = ['situacao' => 'aberta', 'competencia' => '2026-11'];
        $this->como('admin@augi.local')->post(route('visoes.salvar', 'faturas'), [
            'nome' => 'Faturas do mês em aberto', 'filtros' => $filtros,
        ])->assertRedirect(route('faturas.index', $filtros));
        $visao = VisaoSalva::query()->where('nome', 'Faturas do mês em aberto')->firstOrFail();
        $this->get(route('faturas.index'))->assertOk()->assertSee('Faturas do mês em aberto');
        $this->postJson(route('visoes.salvar', 'faturas'), [
            'nome' => 'Insegura', 'filtros' => ['organizacao_id' => $this->empresa(self::B)->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('filtros');

        $this->como('financeiro@augi.local')->get(route('faturas.index'))
            ->assertOk()->assertDontSee('Faturas do mês em aberto');
        $this->delete(route('visoes.remover', ['faturas', $visao]))->assertNotFound();
        $this->como('admin@augi.local')->delete(route('visoes.remover', ['faturas', $visao]))->assertRedirect();
        $this->assertModelMissing($visao);
    }

    public function test_busca_nota_e_extrato_abre_os_registros_da_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $urlNotas = $urlExtratos = [];
        foreach ([self::A, self::B] as $cnpj) {
            $org = $this->empresa($cnpj)->id;
            $nota = NotaFiscal::create(['organizacao_id' => $org, 'numero' => 'NF-4926', 'direcao' => 'emitida',
                'chave_acesso' => str_repeat('1', 50), 'emitente_documento' => $cnpj, 'origem' => 'chave']);
            $conta = ContaFinanceira::create(['organizacao_id' => $org, 'nome' => 'Conta teste', 'tipo' => 'corrente',
                'saldo_inicial_centavos' => 0, 'saldo_inicial_em' => '2026-11-01']);
            $linha = ExtratoLinha::create(['organizacao_id' => $org, 'conta_id' => $conta->id, 'descricao' => 'Recebimento Alvorada',
                'data' => '2026-11-02', 'valor_centavos' => 100, 'identificador' => 'fitid-alvorada']);
            $urlNotas[] = route('notas.index', ['nota' => $nota->id]);
            $urlExtratos[] = route('financeiro.conciliacao', [$conta, 'linha' => $linha->id, 'mostrar' => 'todas']);
        }
        $this->como('financeiro@augi.local')->post(route('busca'), ['q' => 'NF-4926'])
            ->assertOk()->assertSee($urlNotas[0])->assertDontSee($urlNotas[1]);
        $this->post(route('busca'), ['q' => 'fitid-alvorada'])->assertOk()->assertSee($urlExtratos[0])->assertDontSee($urlExtratos[1]);
        $this->get($urlExtratos[0])->assertOk()->assertSee('Recebimento Alvorada');
        $this->get($urlExtratos[1])->assertNotFound();
    }

    public function test_ferramentas_validam_cnpj_e_simulam_sem_criar_titulos(): void
    {
        $this->seed(DatabaseSeeder::class);
        $antes = Titulo::count();
        $this->como('financeiro@augi.local')->post(route('ferramentas'), [
            'acao' => 'validar', 'tipo' => 'cnpj', 'valor' => '12.ABC.345/01DE-35',
        ])->assertOk()->assertSee('Dado válido');
        $this->post(route('ferramentas'), [
            'acao' => 'simular', 'total' => '100,00', 'quantidade' => 3, 'inicio' => '2026-01-31',
        ])->assertOk()->assertSee('28/02/2026')->assertSee('R$ 33,34')->assertSee('R$ 33,33');
        $this->assertDatabaseCount('titulos', $antes);
    }

    public function test_visao_compartilhada_fica_na_empresa_e_retorna_ao_modo_pessoal(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dados = ['nome' => 'Visão equipe de teste', 'filtros' => ['situacao' => 'aberta']];
        $this->como('admin@augi.local')->post(route('visoes.salvar', 'faturas'), $dados)->assertRedirect();
        $visao = VisaoSalva::query()->where('nome', $dados['nome'])->firstOrFail();
        $url = route('visoes.compartilhar', ['faturas', $visao]);
        $this->patch($url, ['compartilhada' => 1])->assertRedirect();
        $this->como('financeiro@augi.local')->get(route('faturas.index'))->assertOk()->assertSee($dados['nome']);
        $this->patch($url, ['compartilhada' => 0])->assertNotFound();
        $this->como('admin.b@augi.local', self::B)->get(route('faturas.index'))->assertOk()->assertDontSee($dados['nome']);
        $this->como('admin@augi.local')->patch($url, ['compartilhada' => 0])->assertRedirect();
        $this->como('financeiro@augi.local')->get(route('faturas.index'))->assertOk()->assertDontSee($dados['nome']);
    }

    public function test_lote_exporta_apenas_a_previa_autorizada_e_audita_o_download(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ids = [];
        foreach ([self::A, self::B] as $cnpj) {
            $org = $this->empresa($cnpj)->id;
            $contrato = Contrato::where('organizacao_id', $org)->firstOrFail();
            $ids[] = Fatura::create(['organizacao_id' => $org, 'contrato_id' => $contrato->id,
                'competencia' => '2026-11-01', 'vencimento' => '2026-12-10', 'valor_centavos' => 100, 'status' => 'aberta'])->id;
        }
        $previa = $this->como('admin@augi.local')->post(route('faturas.lote.previa'), ['faturas' => $ids])->assertOk()
            ->assertViewHas('bloqueadas', 1)->assertViewHas('faturas', fn ($registros) => $registros->modelKeys() === [$ids[0]]);
        $payload = ['confirmacao' => $previa->viewData('confirmacao')];
        $this->como('financeiro@augi.local')->post(route('faturas.lote.exportar'), $payload)->assertUnprocessable();
        $exportacao = $this->como('admin@augi.local')->post(route('faturas.lote.exportar'), $payload)->assertOk()->assertDownload();
        $arquivo = $exportacao->baseResponse->getFile()->getPathname();
        $this->assertFileExists($arquivo);
        unlink($arquivo);
        $this->assertDatabaseHas('eventos', ['acao' => 'financeiro.exportado', 'organizacao_id' => $this->empresa()->id]);
    }
}
