<?php

namespace App\Services;

use App\Enums\Papel;
use App\Models\FaturaCartao;
use App\Models\LembreteConfig;
use App\Models\Notificacao;
use App\Models\Organizacao;
use App\Models\Parcela;
use App\Models\User;
use App\Models\Vinculo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * FIN-REM-01 — lembretes de vencimento.
 *
 *  - Momentos: vencimento − N dias, no horário escolhido, no fuso da aplicação (America/Sao_Paulo).
 *  - Internos (central de notificações) não dependem do Google: funcionam sempre.
 *  - Sem repetição: cada aviso tem chave (parcela + vencimento + dias). Mudou o vencimento,
 *    a chave muda e os avisos são recalculados.
 *  - Quitou, para: só há aviso enquanto houver saldo. Pagamento parcial mantém os avisos do saldo.
 *  - Cartão: um aviso por fatura (não por compra).
 */
final class LembretesVencimento
{
    /** Atraso máximo para entregar um aviso perdido (ex.: cron parado por algumas horas). */
    private const JANELA_HORAS = 24;

    /**
     * Compromissos com saldo de uma empresa (sem depender do contexto da requisição).
     *
     * @return Collection<int, array{alvo: string, id: int, tipo: string, titulo_id: ?int, descricao: string, contraparte: ?string, vencimento: CarbonImmutable, saldo: int, valor: int, rotulo_parcela: ?string, url: string}>
     */
    public function compromissos(int $orgId, ?CarbonImmutable $de = null, ?CarbonImmutable $ate = null): Collection
    {
        $q = Parcela::withoutGlobalScope('organizacao')->where('organizacao_id', $orgId)
            ->with(['titulo' => fn ($t) => $t->withoutGlobalScope('organizacao')->withCount('parcelas'), 'movimentos' => fn ($m) => $m->withoutGlobalScope('organizacao'),
                'movimentos.estornoDe' => fn ($m) => $m->withoutGlobalScope('organizacao')])
            ->whereHas('titulo', fn ($t) => $t->withoutGlobalScope('organizacao')->whereNull('cancelado_em'))
            ->when($de, fn ($w) => $w->whereDate('vencimento', '>=', $de))->when($ate, fn ($w) => $w->whereDate('vencimento', '<=', $ate))
            ->orderBy('vencimento')->orderBy('id');
        $parcelas = $q->get()->filter(fn (Parcela $p) => $p->saldo() > 0);

        $itens = $parcelas->whereNull('fatura_cartao_id')->map(fn (Parcela $p) => [
            'alvo' => 'parcela', 'id' => $p->id, 'tipo' => $p->titulo->tipo, 'titulo_id' => $p->titulo_id,
            'descricao' => $p->titulo->descricao, 'contraparte' => $p->titulo->contraparte,
            'vencimento' => CarbonImmutable::parse($p->vencimento->toDateString()), 'saldo' => $p->saldo(), 'valor' => $p->valor_centavos,
            'rotulo_parcela' => $p->titulo->parcelas_count > 1 ? $p->numero.'/'.$p->titulo->parcelas_count : null,
            'url' => route('financeiro.titulo', $p->titulo_id),
        ]);
        $faturas = $parcelas->whereNotNull('fatura_cartao_id')->groupBy('fatura_cartao_id')->map(function ($ps, $fid) {
            $f = FaturaCartao::withoutGlobalScope('organizacao')->with(['cartao' => fn ($c) => $c->withoutGlobalScope('organizacao')])->find($fid);

            return ['alvo' => 'fatura', 'id' => (int) $fid, 'tipo' => 'cartao', 'titulo_id' => null,
                'descricao' => 'Fatura '.$f->cartao->nome.' '.$f->rotulo(), 'contraparte' => $f->cartao->instituicao,
                'vencimento' => CarbonImmutable::parse($f->vencimento->toDateString()), 'saldo' => (int) $ps->sum(fn ($p) => $p->saldo()),
                'valor' => (int) $ps->sum('valor_centavos'), 'rotulo_parcela' => null, 'url' => route('cartoes.fatura', $fid)];
        });

        return $itens->concat($faturas->values())->sortBy(fn ($i) => $i['vencimento']->toDateString())->values();
    }

    /** Configuração efetiva: ajuste da obrigação > padrão do usuário para o tipo > padrão do sistema. */
    public function config(int $userId, int $orgId, string $tipo, ?int $tituloId = null): array
    {
        $q = fn () => LembreteConfig::withoutGlobalScope('organizacao')->where('organizacao_id', $orgId)->where('user_id', $userId)->where('tipo', $tipo);
        $c = ($tituloId ? $q()->where('titulo_id', $tituloId)->first() : null) ?? $q()->whereNull('titulo_id')->first();

        return $c ? ['ativo' => $c->ativo, 'dias' => array_map('intval', $c->dias), 'hora' => $c->hora, 'canais_google' => $c->canais_google ?? ['popup'],
            'origem' => $c->titulo_id ? 'obrigacao' : 'usuario'] : LembreteConfig::PADRAO + ['origem' => 'sistema'];
    }

    /**
     * Momentos de aviso de um vencimento.
     *
     * @return list<array{dias: int, em: CarbonImmutable}>
     */
    public function momentos(CarbonImmutable $vencimento, array $cfg): array
    {
        [$h, $m] = array_map('intval', explode(':', $cfg['hora']));
        $dias = $cfg['dias'];
        rsort($dias);

        return array_map(fn ($d) => ['dias' => $d, 'em' => $vencimento->setTimezone(config('app.timezone'))->startOfDay()->subDays($d)->setTime($h, $m)], $dias);
    }

    /** Valida e grava a configuração (padrão do usuário quando $tituloId é nulo). */
    public function salvar(int $userId, int $orgId, string $tipo, ?int $tituloId, array $d): LembreteConfig
    {
        $dias = array_values(array_unique(array_map('intval', $d['dias'] ?? [])));
        $erro = match (true) {
            ! array_key_exists($tipo, LembreteConfig::TIPOS) => 'Tipo inválido.',
            array_diff($dias, array_keys(LembreteConfig::diasPara($tipo))) !== [] => $tipo === 'receber'
                ? 'Escolha entre 15, 10, 5 e 1 dia antes, o dia do vencimento e 3, 7 ou 15 dias depois.'
                : 'Escolha entre 15, 10, 5 e 1 dia antes e o dia do vencimento.',
            count(array_filter($dias, fn ($d) => $d >= 0)) > 5 => 'No máximo 5 lembretes até o vencimento.',
            ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($d['hora'] ?? '')) => 'Horário inválido.',
            array_diff($d['canais_google'] ?? [], array_keys(LembreteConfig::CANAIS)) !== [] => 'Canal inválido.',
            default => null,
        };
        if ($erro) {
            throw ValidationException::withMessages(['dias' => $erro]);
        }
        rsort($dias);

        return LembreteConfig::withoutGlobalScope('organizacao')->updateOrCreate(
            ['organizacao_id' => $orgId, 'user_id' => $userId, 'tipo' => $tipo, 'titulo_id' => $tituloId],
            ['ativo' => (bool) ($d['ativo'] ?? true), 'dias' => $dias, 'hora' => $d['hora'], 'canais_google' => array_values($d['canais_google'] ?? [])],
        );
    }

    /** Quem recebe os avisos: admin e financeiro com vínculo vigente na empresa. */
    public function destinatarios(int $orgId): Collection
    {
        return User::whereIn('id', Vinculo::vigentes()->where('organizacao_id', $orgId)->whereIn('papel', [Papel::Admin, Papel::Financeiro])->pluck('user_id'))->get();
    }

    /** Gera os avisos internos devidos até $agora. Retorna quantos foram criados (repetições não contam). */
    public function notificar(?CarbonImmutable $agora = null): int
    {
        $agora ??= CarbonImmutable::now();
        $hoje = $agora->setTimezone(config('app.timezone'))->startOfDay();
        $n = 0;
        foreach (Organizacao::where('tipo', 'empresa')->pluck('id') as $orgId) {
            // Janela: de 15 dias atrás (régua de cobrança pós-vencimento) a 15 dias à frente.
            $itens = $this->compromissos($orgId, $hoje->subDays(max(array_map('abs', array_keys(LembreteConfig::DIAS_DEPOIS)))), $hoje->addDays(max(array_keys(LembreteConfig::DIAS))));
            if ($itens->isEmpty()) {
                continue;
            }
            foreach ($this->destinatarios($orgId) as $u) {
                foreach ($itens as $i) {
                    $cfg = $this->config($u->id, $orgId, $i['tipo'], $i['titulo_id']);
                    if (! $cfg['ativo']) {
                        continue;
                    }
                    foreach ($this->momentos($i['vencimento'], $cfg) as $mo) {
                        if ($mo['em']->gt($agora) || $mo['em']->lt($agora->subHours(self::JANELA_HORAS))) {
                            continue;
                        }
                        $chave = sprintf('venc:%s%d:%s:%d', $i['alvo'] === 'fatura' ? 'f' : 'p', $i['id'], $i['vencimento']->format('Ymd'), $mo['dias']);
                        $quando = match (true) {
                            $mo['dias'] < 0 => 'Cobrar: atrasado há '.abs($mo['dias']).' dias',
                            $mo['dias'] === 0 => 'Vence hoje', $mo['dias'] === 1 => 'Vence amanhã', default => "Vence em {$mo['dias']} dias"
                        };
                        $verbo = $i['tipo'] === 'receber' ? 'A receber' : 'A pagar';
                        $n += (int) Notificacao::avisar($u->id, $orgId, 'vencimento', $chave, "{$quando}: {$i['descricao']}",
                            $verbo.' '.brl($i['saldo']).($i['saldo'] < $i['valor'] ? ' (saldo de '.brl($i['valor']).')' : '')
                            .($i['rotulo_parcela'] ? ' · parcela '.$i['rotulo_parcela'] : '').' · vencimento '.$i['vencimento']->format('d/m/Y').'.', $i['url']);
                    }
                }
            }
        }

        return $n;
    }
}
