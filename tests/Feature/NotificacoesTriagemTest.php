<?php

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComContexto;
use Tests\TestCase;

class NotificacoesTriagemTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    public function test_paginacao_e_adiamento_afetam_apenas_lembretes_do_usuario(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'financeiro@augi.local')->firstOrFail();
        for ($i = 1; $i <= 26; $i++) {
            Notificacao::avisar($user->id, $this->empresa()->id, 'vencimento', 'teste:'.$i, 'Aviso '.$i, 'Lembrete financeiro', null);
        }
        Notificacao::avisar($user->id, null, 'seguranca', 'teste:seg', 'Aviso de segurança', 'Conferir segurança', null);
        $seguranca = Notificacao::where('chave', 'teste:seg')->firstOrFail();
        $lembrete = Notificacao::where('chave', 'teste:26')->firstOrFail();
        $url = route('notificacoes.index', ['filtro' => 'financeiras']);
        $this->como('financeiro@augi.local')->get($url)->assertOk()->assertViewHas('notificacoes', fn ($p) => $p->total() === 26 && $p->count() === 25);
        $this->patch(route('notificacoes.adiar', $seguranca))->assertUnprocessable();
        $this->patch(route('notificacoes.adiar', $lembrete))->assertRedirect();
        $this->get(route('notificacoes.index'))->assertOk()->assertDontSee('Aviso 26')->assertSee('Aviso de segurança');
        $this->get(route('notificacoes.index', ['filtro' => 'todas']))->assertOk()->assertSee('Adiada até');
        $this->travel(25)->hours();
        $this->get(route('notificacoes.index'))->assertOk()->assertSee('Aviso 26');
        $this->como('admin@augi.local')->patch(route('notificacoes.adiar', $lembrete))->assertNotFound();
    }
}
