<?php

namespace App\Services;

use App\Models\Evento;
use App\Models\GoogleConexao;
use App\Models\GoogleEvento;
use App\Models\Notificacao;
use App\Models\Parcela;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FIN-REM-01 — sincronização Augi → Google Agenda (um sentido).
 *
 *  - OAuth por usuário e por empresa; tokens criptografados; permissões mínimas:
 *      agenda dedicada: calendar.app.created (só agendas criadas pelo Augi e seus eventos);
 *      agenda existente: calendar.events.owned + calendar.calendarlist.readonly.
 *  - Um evento por parcela (ou por fatura de cartão), com até 5 lembretes. O vínculo
 *    parcela ↔ evento fica em google_eventos: mudar o vencimento ATUALIZA o mesmo evento.
 *  - Só reenvia quando o conteúdo muda (hash). Quitado: remove ou marca; cancelado: remove.
 *  - Falha do Google nunca interrompe o financeiro: o erro fica registrado e é refeito depois.
 *  - Privacidade: por padrão, título discreto sem nome nem valor; o detalhe fica no Augi.
 */
final class GoogleAgenda
{
    public const ESCOPOS = [
        'dedicada' => ['openid', 'email', 'https://www.googleapis.com/auth/calendar.app.created'],
        'existente' => ['openid', 'email', 'https://www.googleapis.com/auth/calendar.events.owned', 'https://www.googleapis.com/auth/calendar.calendarlist.readonly'],
    ];

    public const NOME_AGENDA = 'Augi Gestão — Financeiro';

    private const API = 'https://www.googleapis.com/calendar/v3';

    /** Limite do Google: até 5 lembretes por evento, no máximo 4 semanas antes. */
    public const MAX_LEMBRETES = 5;

    public function __construct(private LembretesVencimento $lembretes) {}

    public function configurado(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function urlAutorizacao(string $modo, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => $this->redirect(), 'response_type' => 'code',
            'scope' => implode(' ', self::ESCOPOS[$modo]), 'access_type' => 'offline', 'prompt' => 'consent',
            'include_granted_scopes' => 'false', 'state' => $state,
        ]);
    }

    /** Troca o código pelos tokens e prepara a agenda. */
    public function concluir(string $code, string $modo, User $user, int $orgId): GoogleConexao
    {
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->redirect(), 'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new RuntimeException('O Google recusou a autorização. Tente conectar de novo.');
        }
        $escopos = (string) $r->json('scope');
        foreach (self::ESCOPOS[$modo] as $e) {
            if (str_starts_with($e, 'https://') && ! str_contains($escopos, $e)) {
                throw new RuntimeException('A permissão de agenda não foi concedida. Marque a opção de agenda na tela do Google.');
            }
        }
        $email = Http::withToken($r->json('access_token'))->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');

        $c = GoogleConexao::withoutGlobalScope('organizacao')->updateOrCreate(['organizacao_id' => $orgId, 'user_id' => $user->id], [
            'email_google' => $email, 'access_token' => $r->json('access_token'), 'refresh_token' => $r->json('refresh_token'),
            'token_expira_em' => now()->addSeconds((int) $r->json('expires_in', 3600) - 60), 'escopos' => $escopos, 'modo' => $modo,
            'status' => 'ativa', 'ultimo_erro' => null, 'pausada_ate' => null,
            'sincronizar' => ['pagar' => true, 'receber' => false, 'cartao' => true],
        ]);
        if ($modo === 'dedicada') {
            $cal = $this->req($c, 'post', '/calendars', ['summary' => self::NOME_AGENDA, 'timeZone' => config('app.timezone'),
                'description' => 'Vencimentos sincronizados pelo Augi Gestão. Alterações feitas aqui não mudam o financeiro.']);
            $c->update(['calendar_id' => $cal->json('id'), 'calendar_nome' => self::NOME_AGENDA]);
        } else {
            $c->update(['calendar_id' => 'primary', 'calendar_nome' => 'Agenda principal']);
        }
        Evento::registrar('google.conectado', $user, ['empresa' => $orgId, 'conta' => $email, 'modo' => $modo]);

        return $c;
    }

    /** Agendas em que o usuário pode criar eventos (modo "existente"). */
    public function agendas(GoogleConexao $c): Collection
    {
        return collect($this->req($c, 'get', '/users/me/calendarList?minAccessRole=owner')->json('items', []))
            ->map(fn ($a) => ['id' => $a['id'], 'nome' => $a['summary'] ?? $a['id'], 'compartilhada' => ($a['accessRole'] ?? '') !== 'owner']);
    }

    /**
     * Sincroniza os compromissos da empresa com a agenda do usuário.
     *
     * @param  int|null  $tituloId  só os itens deste lançamento (botão "Adicionar ao Google Agenda")
     * @return array{criados: int, atualizados: int, removidos: int, erros: int}
     */
    public function sincronizar(GoogleConexao $c, ?int $tituloId = null): array
    {
        $st = ['criados' => 0, 'atualizados' => 0, 'removidos' => 0, 'erros' => 0];
        if ($c->status !== 'ativa' || ! $c->calendar_id || ($c->pausada_ate && $c->pausada_ate->isFuture())) {
            return $st;
        }
        $hoje = CarbonImmutable::today();
        $desejados = $this->lembretes->compromissos($c->organizacao_id, $hoje->subDays(30), $hoje->addYear())
            ->filter(fn ($i) => $c->sincroniza($i['tipo']))
            ->when($tituloId, fn ($x) => $x->where('titulo_id', $tituloId))
            ->keyBy(fn ($i) => $i['alvo'].':'.$i['id']);
        $existentes = GoogleEvento::withoutGlobalScope('organizacao')->where('google_conexao_id', $c->id)
            ->when($tituloId, fn ($q) => $q->where('alvo', 'parcela')->whereIn('alvo_id', Parcela::withoutGlobalScope('organizacao')->where('titulo_id', $tituloId)->pluck('id')))
            ->get()->keyBy(fn ($e) => $e->alvo.':'.$e->alvo_id);

        try {
            foreach ($desejados as $chave => $item) {
                $corpo = $this->evento($c, $item);
                $hash = hash('sha256', json_encode($corpo));
                $ev = $existentes->get($chave);
                if ($ev && $ev->hash === $hash && $ev->estado === 'ativo' && ! $ev->erro) {
                    continue;
                }
                try {
                    if ($ev) {
                        $r = $this->req($c, 'patch', $this->urlEvento($c, $ev->event_id), $corpo, [404, 410]);
                        if (in_array($r->status(), [404, 410], true)) { // apagado no Google: recria
                            $r = $this->req($c, 'post', $this->urlEvento($c), $corpo);
                        }
                        $ev->update(['event_id' => $r->json('id'), 'hash' => $hash, 'estado' => 'ativo', 'sincronizado_em' => now(), 'erro' => null]);
                        $st['atualizados']++;
                    } else {
                        $r = $this->req($c, 'post', $this->urlEvento($c), $corpo);
                        GoogleEvento::withoutGlobalScope('organizacao')->create(['organizacao_id' => $c->organizacao_id, 'google_conexao_id' => $c->id,
                            'alvo' => $item['alvo'], 'alvo_id' => $item['id'], 'event_id' => $r->json('id'), 'hash' => $hash, 'sincronizado_em' => now()]);
                        $st['criados']++;
                    }
                } catch (FalhaGoogle $e) {
                    if ($e instanceof LimiteGoogle || $e instanceof AutorizacaoRevogada) {
                        throw $e; // para tudo: não adianta insistir agora
                    }
                    $ev?->update(['erro' => $e->getMessage()]);
                    $st['erros']++;
                }
            }
            // Não está mais entre os desejados: quitado (remove ou marca) ou cancelado/fora do filtro (remove).
            foreach ($existentes->diffKeys($desejados) as $ev) {
                if ($ev->estado === 'quitado') {
                    continue;
                }
                try {
                    if ($c->ao_quitar === 'marcar' && $this->quitado($ev)) {
                        $this->req($c, 'patch', $this->urlEvento($c, $ev->event_id), ['summary' => '✓ Quitado — Augi', 'reminders' => ['useDefault' => false, 'overrides' => []]], [404, 410]);
                        $ev->update(['estado' => 'quitado', 'sincronizado_em' => now(), 'erro' => null]);
                    } else {
                        $this->req($c, 'delete', $this->urlEvento($c, $ev->event_id), null, [404, 410]);
                        $ev->delete();
                    }
                    $st['removidos']++;
                } catch (FalhaGoogle $e) {
                    if ($e instanceof LimiteGoogle || $e instanceof AutorizacaoRevogada) {
                        throw $e;
                    }
                    $ev->update(['erro' => $e->getMessage()]);
                    $st['erros']++;
                }
            }
        } catch (AutorizacaoRevogada|LimiteGoogle) {
            $st['erros']++; // estado já registrado na conexão; tenta de novo depois
        }

        $c->update(['sincronizado_em' => now()] + ($st['erros'] ? [] : ['ultimo_erro' => null]));
        if ($st['criados'] + $st['atualizados'] + $st['removidos'] + $st['erros'] > 0) {
            Evento::registrar($st['erros'] ? 'google.erro' : 'google.sincronizado', $c, $st);
        }

        return $st;
    }

    /** Desconecta: opcionalmente apaga o que a integração criou, revoga o acesso e esquece os tokens. */
    public function desconectar(GoogleConexao $c, bool $limpar): void
    {
        if ($limpar && $c->status === 'ativa') {
            try {
                if ($c->modo === 'dedicada' && $c->calendar_id) {
                    $this->req($c, 'delete', '/calendars/'.rawurlencode($c->calendar_id), null, [404, 410]);
                } else {
                    foreach ($c->eventos()->withoutGlobalScope('organizacao')->get() as $ev) {
                        $this->req($c, 'delete', $this->urlEvento($c, $ev->event_id), null, [404, 410]);
                    }
                }
            } catch (\Throwable $e) {
                report($e); // desconectar nunca falha por causa do Google
            }
        }
        try {
            if ($t = $c->refresh_token ?: $c->access_token) {
                Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $t]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
        Evento::registrar('google.desconectado', $c->user, ['empresa' => $c->organizacao_id, 'conta' => $c->email_google, 'eventos_apagados' => $limpar ? 'sim' : 'não']);
        $c->delete();
    }

    /** Link "Adicionar ao Google Agenda" sem integração (o usuário salva o evento manualmente). */
    public static function linkModelo(string $titulo, CarbonImmutable $dia, string $hora, string $detalhes): string
    {
        [$h, $m] = array_map('intval', explode(':', $hora));
        $ini = $dia->setTime($h, $m);

        return 'https://calendar.google.com/calendar/render?'.http_build_query(['action' => 'TEMPLATE', 'text' => $titulo,
            'dates' => $ini->format('Ymd\THis').'/'.$ini->addMinutes(30)->format('Ymd\THis'), 'ctz' => config('app.timezone'), 'details' => $detalhes]);
    }

    // ---------------- Montagem do evento ----------------

    /** Corpo do evento: horário do lembrete no dia do vencimento, até 5 lembretes, privado e sem bloquear a agenda. */
    public function evento(GoogleConexao $c, array $item): array
    {
        $cfg = $this->lembretes->config($c->user_id, $c->organizacao_id, $item['tipo'], $item['titulo_id']);
        [$h, $m] = array_map('intval', explode(':', $cfg['hora']));
        $ini = $item['vencimento']->setTime($h, $m);
        $acao = ['pagar' => 'Pagamento', 'receber' => 'Recebimento previsto', 'cartao' => 'Fatura de cartão'][$item['tipo']];
        $resumo = $c->titulos_discretos
            ? $acao.' — Augi'
            : $acao.': '.$item['descricao'].($item['rotulo_parcela'] ? ' ('.$item['rotulo_parcela'].')' : '').' — '.brl($item['saldo']);

        return [
            'summary' => $resumo,
            'description' => ($c->titulos_discretos ? '' : ($item['contraparte'] ? $item['contraparte']."\n" : '').'Saldo: '.brl($item['saldo'])."\n")
                .'Abrir no Augi Gestão (requer login): '.$item['url'],
            'start' => ['dateTime' => $ini->format('Y-m-d\TH:i:s'), 'timeZone' => config('app.timezone')],
            'end' => ['dateTime' => $ini->addMinutes(30)->format('Y-m-d\TH:i:s'), 'timeZone' => config('app.timezone')],
            'visibility' => 'private', 'transparency' => 'transparent',
            'reminders' => ['useDefault' => false, 'overrides' => $cfg['ativo'] ? self::overrides($cfg['dias'], $cfg['canais_google'] ?? ['popup']) : []],
            'extendedProperties' => ['private' => ['augi' => $item['alvo'].':'.$item['id']]],
        ];
    }

    /**
     * Lembretes do Google (minutos antes do evento). Limite de 5: primeiro uma notificação por dia
     * escolhido; sobrando espaço, e-mail nos dias mais próximos do vencimento.
     */
    public static function overrides(array $dias, array $canais): array
    {
        rsort($dias);
        $out = [];
        $primeiro = in_array('popup', $canais, true) ? 'popup' : 'email';
        foreach ($dias as $d) {
            $out[] = ['method' => $primeiro, 'minutes' => $d * 1440];
        }
        if ($primeiro === 'popup' && in_array('email', $canais, true)) {
            foreach (array_reverse($dias) as $d) {
                $out[] = ['method' => 'email', 'minutes' => $d * 1440];
            }
        }

        return array_slice($out, 0, self::MAX_LEMBRETES);
    }

    // ---------------- HTTP ----------------

    private function req(GoogleConexao $c, string $metodo, string $caminho, ?array $corpo = null, array $aceitos = []): Response
    {
        $tentar = function () use ($c, $metodo, $caminho, $corpo) {
            $h = Http::withToken($this->token($c))->acceptJson()->timeout(20);

            return $corpo === null ? $h->{$metodo}(self::API.$caminho) : $h->{$metodo}(self::API.$caminho, $corpo);
        };
        $r = $tentar();
        if ($r->status() === 401) { // token expirado antes da hora: renova uma vez
            $c->update(['token_expira_em' => now()->subMinute()]);
            $r = $tentar();
        }
        if ($r->successful() || in_array($r->status(), $aceitos, true)) {
            return $r;
        }
        $motivo = $r->json('error.errors.0.reason') ?? $r->json('error.status') ?? '';
        if ($r->status() === 429 || in_array($motivo, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true)) {
            $c->update(['pausada_ate' => now()->addMinutes(15), 'ultimo_erro' => 'Limite de requisições do Google atingido; nova tentativa em 15 minutos.']);
            throw new LimiteGoogle('limite');
        }
        if ($r->status() === 401 || ($r->status() === 403 && $motivo === 'insufficientPermissions')) {
            $this->revogada($c, 'A autorização do Google não permite mais acessar a agenda. Conecte de novo.');
        }
        $c->update(['ultimo_erro' => 'Google respondeu '.$r->status().($motivo ? " ({$motivo})" : '').'.']);
        throw new FalhaGoogle('Google respondeu '.$r->status());
    }

    private function token(GoogleConexao $c): string
    {
        if ($c->access_token && $c->token_expira_em && $c->token_expira_em->isFuture()) {
            return $c->access_token;
        }
        if (! $c->refresh_token) {
            $this->revogada($c, 'Sem permissão de acesso contínuo. Conecte o Google Agenda de novo.');
        }
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $c->refresh_token, 'grant_type' => 'refresh_token',
        ]);
        if ($r->json('error') === 'invalid_grant') {
            $this->revogada($c, 'O acesso foi revogado na conta Google. Conecte de novo para voltar a sincronizar.');
        }
        if (! $r->successful()) {
            $c->update(['ultimo_erro' => 'Não foi possível renovar o acesso ao Google ('.$r->status().').']);
            throw new FalhaGoogle('token');
        }
        $c->update(['access_token' => $r->json('access_token'), 'token_expira_em' => now()->addSeconds((int) $r->json('expires_in', 3600) - 60)]);

        return $c->access_token;
    }

    private function revogada(GoogleConexao $c, string $msg): never
    {
        $c->update(['status' => 'revogada', 'ultimo_erro' => $msg]);
        Notificacao::avisar($c->user_id, $c->organizacao_id, 'google', 'google:revogada:'.$c->id.':'.now()->format('Ymd'),
            'Google Agenda desconectado', $msg.' Os lembretes internos do Augi continuam funcionando.', route('integracoes.google'));
        throw new AutorizacaoRevogada($msg);
    }

    private function urlEvento(GoogleConexao $c, ?string $id = null): string
    {
        return '/calendars/'.rawurlencode($c->calendar_id).'/events'.($id ? '/'.rawurlencode($id) : '');
    }

    private function quitado(GoogleEvento $ev): bool
    {
        if ($ev->alvo !== 'parcela') {
            return true; // fatura sem saldo
        }
        $p = Parcela::withoutGlobalScope('organizacao')->with(['titulo' => fn ($t) => $t->withoutGlobalScope('organizacao'),
            'movimentos' => fn ($m) => $m->withoutGlobalScope('organizacao'), 'movimentos.estornoDe' => fn ($m) => $m->withoutGlobalScope('organizacao')])->find($ev->alvo_id);

        return $p && ! $p->titulo->cancelado() && $p->saldo() === 0;
    }

    private function redirect(): string
    {
        return config('services.google.redirect') ?: route('integracoes.google.retorno');
    }
}

class FalhaGoogle extends RuntimeException {}
class LimiteGoogle extends FalhaGoogle {}
class AutorizacaoRevogada extends FalhaGoogle {}
