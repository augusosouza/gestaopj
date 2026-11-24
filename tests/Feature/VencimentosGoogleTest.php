<?php

namespace Tests\Feature;

use App\Http\Controllers\ContasPagarController;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\GoogleConexao;
use App\Models\GoogleEvento;
use App\Models\Notificacao;
use App\Models\Titulo;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\GoogleAgenda;
use App\Services\LembretesVencimento;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ComContexto;
use Tests\TestCase;

/** FIN-REM-01 — Central de Vencimentos e Google Agenda: critérios de aceite. */
class VencimentosGoogleTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private Titulo $t;

    private ContaFinanceira $conta;

    private User $fin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'cliente-teste', 'services.google.client_secret' => 'segredo-teste']);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->seed(DatabaseSeeder::class);
        DB::table('titulos')->update(['cancelado_em' => now()]);
        $this->fin = User::where('email', 'financeiro@augi.local')->sole();
        $this->conta = ContaFinanceira::create(['organizacao_id' => $this->empresa()->id, 'nome' => 'Itaú', 'tipo' => 'corrente', 'saldo_inicial_centavos' => 500000, 'saldo_inicial_em' => '2026-11-01']);
        $this->como('financeiro@augi.local')->post(route('financeiro.store'), ['tipo' => 'pagar', 'descricao' => 'Aluguel do escritório', 'contraparte' => 'Imobiliária Exemplo',
            'competencia' => '2026-12', 'valor' => '1.400,00', 'parcelas' => 1, 'vencimento' => '2026-12-20'])->assertSessionHasNoErrors();
        $this->t = Titulo::where('descricao', 'Aluguel do escritório')->sole();
        // Padrão do usuário: 15, 10, 5, 1 dia antes e no dia, às 9h.
        $this->como('financeiro@augi.local')->put(route('configuracoes.vencimentos.salvar', 'pagar'),
            ['ativo' => 1, 'dias' => [15, 10, 5, 1, 0], 'hora' => '09:00', 'canais_google' => ['popup']])->assertSessionHasNoErrors();
    }

    private function avisos(): Collection
    {
        return Notificacao::where('user_id', $this->fin->id)->where('tipo', 'vencimento')->orderBy('id')->get();
    }

    /** Roda o agendador no instante dado; devolve quantos avisos NOVOS a pessoa do financeiro recebeu. */
    private function rodarEm(string $quando): int
    {
        $this->travelTo(CarbonImmutable::parse($quando));
        $antes = $this->avisos()->count();
        app(LembretesVencimento::class)->notificar();

        return $this->avisos()->count() - $antes;
    }

    private function conexao(array $extra = []): GoogleConexao
    {
        return GoogleConexao::withoutGlobalScope('organizacao')->create($extra + ['organizacao_id' => $this->empresa()->id, 'user_id' => $this->fin->id,
            'email_google' => 'financeiro@exemplo.test', 'access_token' => 'tok-acesso', 'refresh_token' => 'tok-refresh', 'token_expira_em' => now()->addHour(),
            'modo' => 'dedicada', 'calendar_id' => 'cal-augi', 'calendar_nome' => GoogleAgenda::NOME_AGENDA, 'sincronizar' => ['pagar' => true, 'receber' => false, 'cartao' => true]]);
    }

    // ---------------- Lembretes internos ----------------

    public function test_vencimento_em_20_12_gera_alertas_em_05_10_15_19_e_20_12(): void
    {
        $cfg = app(LembretesVencimento::class)->config($this->fin->id, $this->empresa()->id, 'pagar', $this->t->id);
        $m = app(LembretesVencimento::class)->momentos(CarbonImmutable::parse('2026-12-20'), $cfg);
        $this->assertSame(['05/12/2026 09:00', '10/12/2026 09:00', '15/12/2026 09:00', '19/12/2026 09:00', '20/12/2026 09:00'], array_map(fn ($x) => $x['em']->format('d/m/Y H:i'), $m));
        $this->assertSame('America/Sao_Paulo', $m[0]['em']->getTimezone()->getName());

        $this->assertSame(0, $this->rodarEm('2026-12-05 08:50'), 'antes do horário: nada');
        $this->assertSame(1, $this->rodarEm('2026-12-05 09:10'));
        $this->assertSame(0, $this->rodarEm('2026-12-05 11:00'), 'não repete o mesmo aviso');
        foreach (['2026-12-10 09:05', '2026-12-15 09:05', '2026-12-19 09:05', '2026-12-20 09:05'] as $q) {
            $this->assertSame(1, $this->rodarEm($q));
        }
        $this->assertSame(['Vence em 15 dias: Aluguel do escritório', 'Vence em 10 dias: Aluguel do escritório', 'Vence em 5 dias: Aluguel do escritório',
            'Vence amanhã: Aluguel do escritório', 'Vence hoje: Aluguel do escritório'], $this->avisos()->pluck('titulo')->all());
        $this->assertStringContainsString('A pagar R$ 1.400,00', $this->avisos()->first()->mensagem);
    }

    public function test_quitada_para_e_parcial_mantem_com_o_saldo(): void
    {
        $p = $this->t->parcelas()->first();
        $this->travelTo(CarbonImmutable::parse('2026-12-04 12:00'));
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $p), ['conta_id' => $this->conta->id, 'data' => '2026-12-04', 'valor' => '400,00'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rodarEm('2026-12-05 09:10'));
        $this->assertStringContainsString('A pagar R$ 1.000,00 (saldo de R$ 1.400,00)', $this->avisos()->last()->mensagem, 'parcial: avisa o saldo restante');

        $this->travelTo(CarbonImmutable::parse('2026-12-06 12:00'));
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $p), ['conta_id' => $this->conta->id, 'data' => '2026-12-06', 'valor' => '1.000,00'])->assertSessionHasNoErrors();
        foreach (['2026-12-10 09:05', '2026-12-15 09:05', '2026-12-19 09:05', '2026-12-20 09:05'] as $q) {
            $this->assertSame(0, $this->rodarEm($q), 'quitada: nenhum aviso futuro');
        }
    }

    public function test_alterar_vencimento_recalcula_e_fica_auditado(): void
    {
        $this->assertSame(1, $this->rodarEm('2026-12-05 09:10'));
        $this->travelTo(CarbonImmutable::parse('2026-12-06 10:00'));
        $p = $this->t->parcelas()->first();
        $this->como('financeiro@augi.local')->put(route('financeiro.vencimento', $p), ['vencimento' => '2026-12-28', 'motivo' => 'Renegociado'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-28', $p->fresh()->vencimento->toDateString());
        $this->assertSame(['parcela' => 1, 'de' => '20/12/2026', 'para' => '28/12/2026', 'motivo' => 'Renegociado'],
            Evento::where('acao', 'financeiro.vencimento_alterado')->sole()->dados);
        $this->assertSame(0, $this->rodarEm('2026-12-10 09:05'), 'o aviso do vencimento antigo não sai mais');
        $this->assertSame(1, $this->rodarEm('2026-12-13 09:05'), 'novo: 15 dias antes de 28/12');
        // Parcela de cartão não tem vencimento próprio; quem não opera não altera.
        $this->como('contabilidade@augi.local')->put(route('financeiro.vencimento', $p), ['vencimento' => '2026-12-30', 'motivo' => 'x'])->assertForbidden();
    }

    public function test_configuracao_por_obrigacao_e_por_tipo(): void
    {
        $this->como('financeiro@augi.local')->put(route('lembretes.titulo', $this->t), ['ativo' => 1, 'dias' => [1], 'hora' => '18:00'])->assertSessionHasNoErrors();
        $cfg = app(LembretesVencimento::class)->config($this->fin->id, $this->empresa()->id, 'pagar', $this->t->id);
        $this->assertSame(['ativo' => true, 'dias' => [1], 'hora' => '18:00', 'origem' => 'obrigacao'], array_intersect_key($cfg, array_flip(['ativo', 'dias', 'hora', 'origem'])));
        $this->assertSame(0, $this->rodarEm('2026-12-05 09:10'), 'ajuste da obrigação vence o padrão');
        $this->assertSame(1, $this->rodarEm('2026-12-19 18:05'));
        $this->como('financeiro@augi.local')->put(route('lembretes.titulo', $this->t), ['restaurar' => 1, 'hora' => '09:00'])->assertSessionHasNoErrors();
        $this->assertSame('usuario', app(LembretesVencimento::class)->config($this->fin->id, $this->empresa()->id, 'pagar', $this->t->id)['origem']);

        $this->como('financeiro@augi.local')->put(route('configuracoes.vencimentos.salvar', 'pagar'), ['dias' => [20], 'hora' => '09:00'])->assertSessionHasErrors('dias');
        $this->como('financeiro@augi.local')->put(route('configuracoes.vencimentos.salvar', 'pagar'), ['dias' => [1], 'hora' => '25:00'])->assertSessionHasErrors('hora');
        // Admin recebe com o padrão do sistema (5, 1, 0); desativar só vale para quem desativou.
        $admin = User::where('email', 'admin@augi.local')->sole();
        $this->rodarEm('2026-12-15 09:05');
        $this->assertTrue(Notificacao::where('user_id', $admin->id)->where('chave', 'venc:p'.$this->t->parcelas()->first()->id.':20261220:5')->exists(),
            'admin recebe pelo padrão do sistema (5 dias antes)');
    }

    // ---------------- Google Agenda ----------------

    public function test_cria_um_evento_com_cinco_lembretes_e_atualiza_o_mesmo_ao_mudar_vencimento(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/cal-augi/events' => Http::response(['id' => 'ev-1'], 200),
            'www.googleapis.com/calendar/v3/calendars/cal-augi/events/ev-1' => Http::response(['id' => 'ev-1'], 200),
        ]);
        $c = $this->conexao();
        $this->assertSame(['criados' => 1, 'atualizados' => 0, 'removidos' => 0, 'erros' => 0], app(GoogleAgenda::class)->sincronizar($c));
        $this->assertSame(0, app(GoogleAgenda::class)->sincronizar($c->fresh())['criados'], 'sem mudança: nada é reenviado');

        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST') {
                return false;
            }
            $b = $r->data();

            return $r->hasHeader('Authorization', 'Bearer tok-acesso')
                && $b['start'] === ['dateTime' => '2026-12-20T09:00:00', 'timeZone' => 'America/Sao_Paulo']
                && array_column($b['reminders']['overrides'], 'minutes') === [21600, 14400, 7200, 1440, 0]
                && $b['summary'] === 'Pagamento — Augi' // discreto: sem nome nem valor
                && ! str_contains($b['description'], 'Imobiliária') && ! str_contains($b['description'], '1.400')
                && $b['visibility'] === 'private';
        });

        $this->como('financeiro@augi.local')->put(route('financeiro.vencimento', $this->t->parcelas()->first()), ['vencimento' => '2026-12-28', 'motivo' => 'Renegociado'])->assertSessionHasNoErrors();
        $this->assertSame(1, GoogleEvento::count(), 'mesmo evento, sem duplicar');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/events/ev-1') && $r->data()['start']['dateTime'] === '2026-12-28T09:00:00');
        $this->assertTrue(Evento::where('acao', 'google.sincronizado')->exists(), 'sincronização auditada');
    }

    public function test_troca_de_agenda_preserva_vinculo_quando_google_falha_e_aceita_retomada(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [['id' => 'nova', 'summary' => 'Nova']]]),
            'www.googleapis.com/calendar/v3/calendars/cal-augi/events' => Http::response(['id' => 'antigo']),
            'www.googleapis.com/calendar/v3/calendars/cal-augi/events/antigo' => Http::sequence()->pushStatus(500)->pushStatus(410),
            'www.googleapis.com/calendar/v3/calendars/nova/events' => Http::response(['id' => 'novo']),
        ]);
        $c = $this->conexao(['modo' => 'existente']);
        app(GoogleAgenda::class)->sincronizar($c);
        $dados = ['calendar_id' => 'nova', 'ao_quitar' => 'remover', 'pagar' => 1];

        $this->como('financeiro@augi.local')->put(route('integracoes.google.salvar'), $dados)->assertSessionHas('erro');
        $this->assertSame('cal-augi', $c->fresh()->calendar_id);
        $this->assertNotNull($c->fresh()->ultimo_erro);
        $this->assertSame('antigo', GoogleEvento::sole()->event_id);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/calendars/nova/'));

        $this->como('financeiro@augi.local')->put(route('integracoes.google.salvar'), $dados)->assertSessionHas('ok');
        $this->assertSame('nova', $c->fresh()->calendar_id);
        $this->assertSame('novo', GoogleEvento::sole()->event_id);
    }

    public function test_quitada_remove_ou_marca_o_evento(): void
    {
        Http::fake(['*/events' => Http::response(['id' => 'ev-1']), '*/events/ev-1' => Http::response([], 204)]);
        $c = $this->conexao();
        app(GoogleAgenda::class)->sincronizar($c);
        $p = $this->t->parcelas()->first();
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $p), ['conta_id' => $this->conta->id, 'data' => '2026-11-20', 'valor' => '1.400,00'])->assertSessionHasNoErrors();
        $this->assertSame(1, app(GoogleAgenda::class)->sincronizar($c->fresh())['removidos']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/ev-1'));
        $this->assertSame(0, GoogleEvento::count());
    }

    public function test_marcar_quitado_mantem_evento_sem_alertas(): void
    {
        Http::fake(['*/events' => Http::response(['id' => 'ev-1']), '*/events/ev-1' => Http::response(['id' => 'ev-1'])]);
        $c = $this->conexao(['ao_quitar' => 'marcar']);
        app(GoogleAgenda::class)->sincronizar($c);
        $this->como('financeiro@augi.local')->post(route('financeiro.baixar', $this->t->parcelas()->first()), ['conta_id' => $this->conta->id, 'data' => '2026-11-20', 'valor' => '1.400,00']);
        app(GoogleAgenda::class)->sincronizar($c->fresh());
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && $r->data()['summary'] === '✓ Quitado — Augi' && $r->data()['reminders']['overrides'] === []);
        $this->assertSame('quitado', GoogleEvento::sole()->estado);
    }

    public function test_falha_do_google_nao_para_o_financeiro_nem_os_lembretes(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 500]], 500)]);
        $this->conexao();
        $this->como('financeiro@augi.local')->put(route('financeiro.vencimento', $this->t->parcelas()->first()), ['vencimento' => '2026-12-22', 'motivo' => 'x'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-12-22', $this->t->parcelas()->first()->vencimento->toDateString());
        $this->assertSame('Google respondeu 500.', GoogleConexao::withoutGlobalScope('organizacao')->sole()->ultimo_erro);
        $this->assertSame(1, $this->rodarEm('2026-12-07 09:05'), 'lembrete interno continua');
    }

    public function test_autorizacao_revogada(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400), '*' => Http::response(['id' => 'x'])]);
        $c = $this->conexao(['token_expira_em' => now()->subMinute()]);
        $this->assertSame(1, app(GoogleAgenda::class)->sincronizar($c)['erros']);
        $c->refresh();
        $this->assertSame('revogada', $c->status);
        $this->assertTrue(Notificacao::where('user_id', $this->fin->id)->where('titulo', 'Google Agenda desconectado')->exists());
        $this->assertSame(0, app(GoogleAgenda::class)->sincronizar($c)['criados'], 'revogada: não tenta mais');
    }

    public function test_limite_de_requisicoes_pausa_a_sincronizacao(): void
    {
        Http::fake(['*' => Http::response(['error' => ['errors' => [['reason' => 'rateLimitExceeded']]]], 403)]);
        $c2 = $this->conexao();
        app(GoogleAgenda::class)->sincronizar($c2);
        $this->assertTrue($c2->fresh()->pausada_ate->isFuture(), 'limite: pausa antes de tentar de novo');
        Http::assertSentCount(1);
    }

    public function test_oauth_com_escopo_minimo_estado_e_tokens_criptografados(): void
    {
        $r = $this->como('financeiro@augi.local')->post(route('integracoes.google.conectar'), ['modo' => 'dedicada']);
        $url = $r->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('openid email https://www.googleapis.com/auth/calendar.app.created', $q['scope'], 'só agendas criadas pelo Augi');
        $this->assertSame('offline', $q['access_type']);

        $this->como('financeiro@augi.local')->get(route('integracoes.google.retorno', ['state' => 'forjado', 'code' => 'c']))->assertSessionHas('erro');
        $this->assertSame(0, GoogleConexao::withoutGlobalScope('organizacao')->count());

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'novo-acesso', 'refresh_token' => 'novo-refresh', 'expires_in' => 3600,
                'scope' => 'openid https://www.googleapis.com/auth/calendar.app.created https://www.googleapis.com/auth/userinfo.email']),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'financeiro@exemplo.test', 'email_verified' => true, 'sub' => 'google-conta-1']),
            'www.googleapis.com/calendar/v3/calendars' => Http::response(['id' => 'cal-novo']),
            '*' => Http::response(['id' => 'ev']),
        ]);
        $this->actingAs($this->fin)->withSession(['ctx' => session('ctx') ?? $this->ctxSessao(), 'google_oauth' => ['state' => 'ok123', 'modo' => 'dedicada', 'org' => $this->empresa()->id, 'user' => $this->fin->id]])
            ->get(route('integracoes.google.retorno', ['state' => 'ok123', 'code' => 'codigo']))->assertSessionHas('ok');
        $c = GoogleConexao::withoutGlobalScope('organizacao')->sole();
        $this->assertSame(['cal-novo', 'financeiro@exemplo.test', 'ativa'], [$c->calendar_id, $c->email_google, $c->status]);
        $this->assertSame('google-conta-1', $c->google_subject);
        $bruto = DB::table('google_conexoes')->value('refresh_token');
        $this->assertNotSame('novo-refresh', $bruto, 'token guardado criptografado');
        $this->assertSame('novo-refresh', $c->refresh_token);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://www.googleapis.com/calendar/v3/calendars' && $r->data()['summary'] === GoogleAgenda::NOME_AGENDA);
    }

    public function test_reconexao_da_mesma_conta_preserva_agenda_e_outra_identidade_e_recusada(): void
    {
        $c = $this->conexao(['google_subject' => 'conta-original']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'novo', 'scope' => 'https://www.googleapis.com/auth/calendar.app.created']),
            'openidconnect.googleapis.com/*' => Http::sequence()
                ->push(['email' => 'novo@exemplo.test', 'email_verified' => true, 'sub' => 'conta-original'])
                ->push(['email' => 'novo@exemplo.test', 'email_verified' => true, 'sub' => 'outra-conta']),
        ]);
        $g = app(GoogleAgenda::class);
        $atual = $g->concluir('codigo', 'dedicada', $this->fin, $this->empresa()->id);
        $this->assertSame($c->id, $atual->id);
        $this->assertSame('cal-augi', $atual->calendar_id);
        $this->assertSame('tok-refresh', $atual->refresh_token);

        try {
            $g->concluir('codigo', 'dedicada', $this->fin, $this->empresa()->id);
            $this->fail('Uma identidade diferente não pode assumir a conexão.');
        } catch (\RuntimeException $erro) {
            $this->assertStringContainsString('desconecte a conta anterior', $erro->getMessage());
        }
        $this->assertSame('conta-original', $c->fresh()->google_subject);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'calendar/v3'));
    }

    public function test_conexao_de_uma_empresa_nao_ve_outra_e_desconectar_limpa(): void
    {
        Http::fake(['*' => Http::response(['id' => 'ev-1'])]);
        $c = $this->conexao();
        // Conta a pagar da empresa B: não pode ir para a agenda da conexão da empresa A.
        $this->como('admin.b@augi.local', self::B)->post(route('financeiro.store'), ['tipo' => 'pagar', 'descricao' => 'Segredo da B', 'competencia' => '2026-12',
            'valor' => '9,99', 'parcelas' => 1, 'vencimento' => '2026-12-10'])->assertSessionHasNoErrors();
        app(GoogleAgenda::class)->sincronizar($c);
        $this->assertSame(1, GoogleEvento::withoutGlobalScope('organizacao')->count());
        Http::assertNotSent(fn (HttpRequest $r) => str_contains(json_encode($r->data()), 'Segredo'));
        $this->como('admin.b@augi.local', self::B)->get(route('integracoes.google'))->assertOk()->assertDontSee('financeiro@exemplo.test');
        $this->como('prestador@augi.local')->get(route('integracoes.google'))->assertForbidden();

        $this->como('financeiro@augi.local')->delete(route('integracoes.google.desconectar'), ['limpar' => 1])->assertRedirect();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/calendars/cal-augi'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://oauth2.googleapis.com/revoke');
        $this->assertSame(0, GoogleConexao::withoutGlobalScope('organizacao')->count());
        $this->assertTrue(Evento::where('acao', 'google.desconectado')->exists());
    }

    public function test_limite_de_cinco_lembretes_com_dois_canais(): void
    {
        $o = GoogleAgenda::overrides([15, 10, 5, 1, 0], ['popup', 'email']);
        $this->assertCount(5, $o);
        $this->assertSame(['popup'], array_values(array_unique(array_column($o, 'method'))), 'notificação em todos os dias tem prioridade');
        $this->assertSame([['method' => 'popup', 'minutes' => 1440], ['method' => 'popup', 'minutes' => 0], ['method' => 'email', 'minutes' => 0], ['method' => 'email', 'minutes' => 1440]],
            GoogleAgenda::overrides([1, 0], ['popup', 'email']), 'sobrando espaço, e-mail nos dias mais próximos');
        $this->assertSame([['method' => 'email', 'minutes' => 7200]], GoogleAgenda::overrides([5], ['email']));
    }

    // ---------------- Tela ----------------

    public function test_central_de_contas_a_pagar(): void
    {
        $this->como('financeiro@augi.local')->post(route('financeiro.store'), ['tipo' => 'pagar', 'descricao' => 'Internet', 'contraparte' => 'Provedor', 'competencia' => '2026-11',
            'valor' => '200,00', 'parcelas' => 1, 'vencimento' => '2026-11-15'])->assertSessionHasNoErrors(); // atrasada
        $r = $this->como('financeiro@augi.local')->get(route('contas_pagar.index'))->assertOk();
        $r->assertSee('Total em aberto')->assertSee('R$ 1.600,00')->assertSee('Vencido')->assertSee('Vence hoje')->assertSee('Próximos 30 dias')->assertSee('R$ 200,00');
        $this->como('financeiro@augi.local')->get(route('contas_pagar.index', ['aba' => 'vencidas']))->assertSee('Internet')->assertDontSee('Aluguel do escritório');
        foreach (array_keys(ContasPagarController::ABAS) as $aba) {
            $this->como('financeiro@augi.local')->get(route('contas_pagar.index', ['aba' => $aba]))->assertOk();
        }
        $this->como('financeiro@augi.local')->get(route('contas_pagar.index', ['aba' => 'aberto', 'situacao' => 'vencido']))->assertSee('Internet')->assertDontSee('Aluguel do escritório');
        $this->como('financeiro@augi.local')->get(route('contas_pagar.index', ['aba' => 'aberto', 'fornecedor' => 'imobili']))->assertSee('Aluguel do escritório')->assertDontSee('Internet');
        $this->como('financeiro@augi.local')->get(route('contas_pagar.index', ['aba' => 'aberto', 'min' => '500,00']))->assertDontSee('Internet');
        $this->como('financeiro@augi.local')->get(route('contas_pagar.index', ['aba' => 'calendario', 'mes' => '2026-12']))->assertSee('Aluguel do escrit');
        $this->como('financeiro@augi.local')->get(route('financeiro.titulo', [$this->t, 'aba' => 'lembretes']))->assertOk()
            ->assertSee('05/12/2026 às 09:00')->assertSee('Adicionar ao Google Agenda (manual)');
        $this->como('prestador@augi.local')->get(route('contas_pagar.index'))->assertForbidden();
        $r->assertSee(route('financeiro.exportar', ['relatorio' => 'lancamentos', 'tipo' => 'pagar', 'periodo' => 'todos', 'situacao' => 'aberto', 'formato' => 'xlsx']));
    }

    private function ctxSessao(): array
    {
        $v = Vinculo::vigentes()->where('user_id', $this->fin->id)->where('organizacao_id', $this->empresa()->id)->sole();

        return ['vinculo' => $v->id, 'empresa' => null];
    }
}
