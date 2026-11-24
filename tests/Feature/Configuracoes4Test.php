<?php

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\Evento;
use App\Models\User;
use App\Models\Vinculo;
use App\Support\CorreioPlataforma;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ComContexto;
use Tests\TestCase;

class Configuracoes4Test extends TestCase
{
    use ComContexto, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_inteligencia_e_configurada_por_empresa_e_so_admin_pode_alterar(): void
    {
        $rota = route('configuracoes.inteligencia.salvar');
        $dados = ['assistente_ativo' => 0, 'sugestoes_ativas' => 0];

        $this->como('financeiro@augi.local')->get(route('configuracoes.inteligencia'))->assertOk()->assertDontSee('Salvar preferências da empresa');
        $this->como('financeiro@augi.local')->put($rota, $dados)->assertForbidden();
        $this->como('admin@augi.local')->put($rota, $dados)->assertSessionHas('ok');
        $this->como('financeiro@augi.local')->get(route('inteligencia.assistente'))->assertForbidden();
        $this->como('financeiro@augi.local')->get(route('inteligencia.sugestoes'))->assertForbidden();
        $this->como('admin.b@augi.local', self::B)->get(route('inteligencia.assistente'))->assertOk();
        $this->assertDatabaseHas('eventos', ['acao' => 'inteligencia.configurada', 'organizacao_id' => $this->empresa()->id]);
    }

    public function test_smtp_exige_admin_global_e_senha_atual_e_protege_credencial(): void
    {
        config(['mail.painel_host' => 'smtp.example.test']);
        $rota = route('plataforma.smtp.salvar');
        $dados = ['ativo' => 1, 'porta' => 587, 'remetente' => 'envio@example.test',
            'usuario' => 'envio@example.test', 'smtp_password' => 'segredo smtp de teste', 'current_password' => 'piloto-2026'];

        $this->como('admin.b@augi.local', self::B)->put($rota, $dados)->assertForbidden();
        $this->como('admin@augi.local')->put($rota, [...$dados, 'current_password' => 'errada'])->assertSessionHasErrors('current_password');
        $this->assertNull(session()->getOldInput('smtp_password'));
        $this->como('admin@augi.local')->put($rota, $dados)->assertSessionHas('ok');
        $this->assertStringNotContainsString($dados['smtp_password'], Configuracao::where('chave', 'smtp_configuracao')->sole()->valor);
        $this->assertSame('smtp-painel', Mail::getDefaultDriver());
        $this->assertSame('smtp.example.test', app(CorreioPlataforma::class)->transporte()['host']);
        $this->assertTrue(app(CorreioPlataforma::class)->transporte()['require_tls']);
        $this->assertSame($dados['smtp_password'], app(CorreioPlataforma::class)->transporte()['password']);
        $this->assertNull(config('mail.mailers.smtp.password'));
        $this->como('admin@augi.local')->get(route('plataforma.smtp'))->assertOk()->assertDontSee($dados['smtp_password']);

        $this->como('admin@augi.local')->put($rota, [...$dados, 'smtp_password' => '', 'remover_senha' => 1, 'ativo' => 0])->assertSessionHas('ok');
        $this->assertSame('array', Mail::getDefaultDriver());
        $this->assertSame('', app(CorreioPlataforma::class)->salvo()['senha']);
        $this->assertFalse(Evento::where('acao', 'plataforma.smtp_configurado')->whereNotNull('organizacao_id')->exists());
    }

    public function test_troca_de_papel_revalida_senha_e_preserva_historico(): void
    {
        $org = $this->empresa();
        $financeiro = User::where('email', 'financeiro@augi.local')->sole();
        $v = $financeiro->vinculos()->where('organizacao_id', $org->id)->sole();
        $rota = route('usuarios.papel', $v);

        $this->como('admin.b@augi.local', self::B)->patch($rota, ['papel' => 'admin', 'current_password' => 'piloto-2026'])->assertNotFound();
        $this->como('admin@augi.local')->patch($rota, ['papel' => 'admin', 'current_password' => 'errada'])->assertSessionHasErrors('current_password');
        $this->assertTrue($v->fresh()->vigente());
        $this->como('admin@augi.local')->patch($rota, ['papel' => 'admin', 'current_password' => 'piloto-2026'])->assertSessionHas('ok');

        $this->assertFalse($v->fresh()->vigente());
        $novo = Vinculo::vigentes()->where('organizacao_id', $org->id)->where('user_id', $financeiro->id)->sole();
        $this->assertSame('admin', $novo->papel->value);
        $this->assertDatabaseHas('eventos', ['acao' => 'vinculo.papel_alterado', 'organizacao_id' => $org->id, 'alvo_id' => $novo->id]);
    }
}
