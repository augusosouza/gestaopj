<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ComContexto;
use Tests\TestCase;

class DoisFatoresTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['gestao.exigir_2fa' => true]);
    }

    private function ativar(User $user): string
    {
        $segredo = (new Google2FA)->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $segredo, 'two_factor_recovery_codes' => ['AAAAA-BBBBB'], 'two_factor_confirmed_at' => now()])->save();

        return $segredo;
    }

    public function test_admin_e_revisor_sem_2fa_sao_levados_a_ativar(): void
    {
        $this->como('admin@augi.local')->get(route('contratos.index'))->assertRedirect(route('seguranca'));
        $this->como('revisor@augi.local')->get(route('carteira'))->assertRedirect(route('seguranca'));
        $this->como('financeiro@augi.local')->get(route('cobrancas.index'))->assertOk(); // não privilegiado
        $this->como('admin@augi.local')->get(route('seguranca'))->assertOk()->assertSee('Ativar verificação');
    }

    public function test_cadastro_do_2fa_com_qr_code_e_codigos_de_recuperacao(): void
    {
        $admin = User::where('email', 'admin@augi.local')->sole();
        $this->actingAs($admin)->post(route('2fa.iniciar'))->assertRedirect(route('seguranca'));
        $segredo = session('2fa.novo_segredo');
        $this->actingAs($admin)->get(route('seguranca'))->assertOk()->assertSee('<svg', false);

        $this->actingAs($admin)->post(route('2fa.confirmar'), ['codigo' => '000000'])->assertSessionHasErrors('codigo');
        $this->actingAs($admin)->post(route('2fa.confirmar'), ['codigo' => (new Google2FA)->getCurrentOtp($segredo)])
            ->assertRedirect(route('seguranca'))->assertSessionHas('2fa.codigos');

        $admin->refresh();
        $this->assertTrue($admin->temDoisFatores());
        $this->assertCount(8, $admin->two_factor_recovery_codes);
        $this->assertNotSame($segredo, $admin->getRawOriginal('two_factor_secret'), 'segredo deve ficar criptografado no banco');
        $this->como('admin@augi.local')->get(route('contratos.index'))->assertOk();
    }

    public function test_login_com_2fa_exige_o_codigo_e_nao_aceita_reutilizacao(): void
    {
        $admin = User::where('email', 'admin@augi.local')->sole();
        $segredo = $this->ativar($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'piloto-2026'])->assertRedirect(route('2fa.desafio'));
        $this->assertGuest();
        $this->get(route('painel'))->assertRedirect('/login');

        $this->post(route('2fa.verificar'), ['codigo' => '123456'])->assertSessionHasErrors('codigo');
        $this->assertGuest();

        $codigo = (new Google2FA)->getCurrentOtp($segredo);
        $this->post(route('2fa.verificar'), ['codigo' => $codigo])->assertRedirect(route('contexto.escolher'));
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('eventos', ['acao' => 'login', 'user_id' => $admin->id]);

        // Mesmo código numa nova tentativa de login: recusado
        $this->post('/logout');
        $this->post('/login', ['email' => $admin->email, 'password' => 'piloto-2026']);
        $this->post(route('2fa.verificar'), ['codigo' => $codigo])->assertSessionHasErrors('codigo');
        $this->assertGuest();
    }

    public function test_codigo_de_recuperacao_funciona_uma_unica_vez(): void
    {
        $admin = User::where('email', 'admin@augi.local')->sole();
        $this->ativar($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'piloto-2026']);
        $this->post(route('2fa.verificar'), ['codigo' => 'aaaaa-bbbbb'])->assertRedirect(route('contexto.escolher'));
        $this->assertSame([], $admin->fresh()->two_factor_recovery_codes);

        $this->post('/logout');
        $this->post('/login', ['email' => $admin->email, 'password' => 'piloto-2026']);
        $this->post(route('2fa.verificar'), ['codigo' => 'AAAAA-BBBBB'])->assertSessionHasErrors('codigo');
        $this->assertGuest();
    }

    public function test_desafio_expira_e_senha_errada_nao_chega_ao_desafio(): void
    {
        $admin = User::where('email', 'admin@augi.local')->sole();
        $segredo = $this->ativar($admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->get(route('2fa.desafio'))->assertRedirect(route('login'));

        $this->post('/login', ['email' => $admin->email, 'password' => 'piloto-2026']);
        $this->travel(6)->minutes();
        $this->post(route('2fa.verificar'), ['codigo' => (new Google2FA)->getCurrentOtp($segredo)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_desativar_exige_senha(): void
    {
        $admin = User::where('email', 'admin@augi.local')->sole();
        $this->ativar($admin);
        $this->actingAs($admin)->delete(route('2fa.desativar'), ['senha' => 'errada'])->assertSessionHasErrors('senha');
        $this->assertTrue($admin->fresh()->temDoisFatores());
        $this->actingAs($admin)->delete(route('2fa.desativar'), ['senha' => 'piloto-2026'])->assertSessionHasNoErrors();
        $this->assertFalse($admin->fresh()->temDoisFatores());
    }
}
