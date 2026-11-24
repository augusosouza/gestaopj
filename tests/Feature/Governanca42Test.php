<?php

namespace Tests\Feature;

use App\Models\AlteracaoPix;
use App\Models\Evento;
use App\Models\Notificacao;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Vinculo;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ComContexto;
use Tests\TestCase;

final class Governanca42Test extends TestCase
{
    use ComContexto, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_simulador_respeita_contexto_papel_e_vigencia(): void
    {
        $prestador = User::where('email', 'prestador@augi.local')->sole();
        $vinculo = $prestador->vinculos()->where('organizacao_id', $this->empresa()->id)->sole();
        $destino = route('usuarios.simular', $vinculo);
        $this->como('admin@augi.local')->get($destino)->assertOk()->assertSee('Importar extrato')
            ->assertSee('O papel selecionado não atende')->assertDontSee('>Permitido<', false);
        $this->como('prestador@augi.local')->get(route('financeiro.contas'))->assertForbidden();
        $this->como('financeiro@augi.local')->get($destino)->assertForbidden();
        $this->como('admin.b@augi.local', self::B)->get($destino)->assertNotFound();

        $financeiro = User::where('email', 'financeiro@augi.local')->sole();
        $vinculoFinanceiro = $financeiro->vinculos()->sole();
        $this->como('admin@augi.local')->get(route('usuarios.simular', $vinculoFinanceiro))->assertOk()->assertSee('>Permitido<', false);
        $this->como('financeiro@augi.local')->get(route('financeiro.contas'))->assertOk();
        $vinculoFinanceiro->update(['fim' => now()->toDateString()]);
        $this->como('admin@augi.local')->get(route('usuarios.simular', $vinculoFinanceiro))->assertOk()->assertSee('O vínculo está encerrado');
    }

    public function test_pix_exige_reautenticacao_e_aprovacao_independente(): void
    {
        $empresa = $this->empresa();
        $prestador = Prestador::where('organizacao_id', $empresa->id)->firstOrFail();
        $prestador->update(['chave_pix' => 'chave-original@example.test']);
        $outroAdmin = User::where('email', 'financeiro@augi.local')->sole();
        Vinculo::create(['user_id' => $outroAdmin->id, 'organizacao_id' => $empresa->id,
            'papel' => 'admin', 'inicio' => now()->toDateString()]);

        $solicitar = route('prestadores.pix.solicitar', $prestador);
        $dados = ['nova_chave_pix' => 'nova-chave@example.test', 'motivo' => 'Troca confirmada com o fornecedor', 'current_password' => 'piloto-2026'];
        $this->como('admin.b@augi.local', self::B)->post($solicitar, $dados)->assertNotFound();
        $this->como('admin@augi.local')->post($solicitar, [...$dados, 'current_password' => 'errada'])->assertSessionHasErrors('current_password');
        $this->assertNull(session()->getOldInput('nova_chave_pix'));
        $this->post($solicitar, $dados)->assertSessionHas('ok');
        $this->assertSame('chave-original@example.test', $prestador->fresh()->chave_pix);
        $alteracao = AlteracaoPix::withoutGlobalScopes()->sole();
        $this->assertStringNotContainsString($dados['nova_chave_pix'], DB::table('alteracoes_pix')->value('chave_proposta'));
        $this->assertDatabaseHas('notificacoes', ['user_id' => $outroAdmin->id, 'organizacao_id' => $empresa->id, 'chave' => 'pix:revisar:'.$alteracao->id]);

        $decidir = route('prestadores.pix.decidir', [$prestador, $alteracao]);
        $decisao = ['decisao' => 'aprovar', 'justificativa_decisao' => 'Conferência independente', 'current_password' => 'piloto-2026'];
        $this->patch($decidir, $decisao)->assertForbidden();
        $this->como('admin.b@augi.local', self::B)->patch($decidir, $decisao)->assertNotFound();
        $this->actingAs($outroAdmin)->withSession(['ctx' => ['vinculo' => $outroAdmin->vinculos()->where('papel', 'admin')->sole()->id]])
            ->patch($decidir, $decisao)->assertSessionHas('ok');
        $this->assertSame($dados['nova_chave_pix'], $prestador->fresh()->chave_pix);
        $this->assertSame('aplicada', $alteracao->fresh()->estado);
        $this->patch($decidir, $decisao)->assertUnprocessable();

        $this->get(route('governanca.index'))->assertOk()->assertSee('Alteração de chave Pix aplicada')
            ->assertDontSee($dados['nova_chave_pix']);
        $this->como('admin.b@augi.local', self::B)->get(route('governanca.index'))->assertOk()->assertDontSee('Alteração de chave Pix aplicada');
    }

    public function test_edicao_do_prestador_nao_atualiza_pix_diretamente(): void
    {
        $p = Prestador::withoutGlobalScopes()->where('organizacao_id', $this->empresa()->id)->firstOrFail();
        $p->update(['chave_pix' => 'atual@example.test']);
        $this->como('admin@augi.local')->put(route('prestadores.update', $p), [
            'nome' => $p->nome, 'tipo' => $p->tipo->value, 'documento' => $p->documento, 'chave_pix' => 'indevida@example.test',
        ])->assertSessionHasErrors('chave_pix');
        $this->assertSame('atual@example.test', $p->fresh()->chave_pix);
        $this->assertFalse(Evento::where('acao', 'pix.alteracao_aplicada')->exists());
        $this->assertSame(0, Notificacao::where('tipo', 'seguranca')->count());
    }
}
