<?php

namespace App\Services;

use App\Enums\Papel;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\ExtratoLinha;
use App\Models\Notificacao;
use App\Models\Vinculo;
use Carbon\CarbonImmutable;

/**
 * FIN-ALERT-01 — a conta só está "em dia" quando o PERÍODO COBERTO pelos extratos importados
 * chega até a data exigida pela regra configurada. Enviar um arquivo antigo não atualiza nada.
 *
 * Exigência: no prazo da regra (ex.: toda segunda às 09:00), o extrato deve cobrir até o dia anterior.
 * Dois alertas separados: extrato desatualizado (faltam dados) e conciliação pendente (dados sem conferência).
 */
final class LembretesBancarios
{
    /**
     * @return array{estado: string, rotulo: string, exigido_ate: ?CarbonImmutable, proximo_prazo: ?CarbonImmutable, a_conciliar: int, antigas: int}
     */
    public function situacao(ContaFinanceira $c, ?CarbonImmutable $agora = null): array
    {
        $agora ??= CarbonImmutable::now();
        $prazo = $this->ultimoPrazo($c, $agora);
        $exigido = $prazo?->subDay()->startOfDay();
        // A conta não pode ser cobrada por período anterior à sua existência no sistema.
        if ($exigido && $exigido->lt(CarbonImmutable::instance($c->saldo_inicial_em))) {
            $exigido = null;
        }
        $aConciliar = ExtratoLinha::withoutGlobalScope('organizacao')->where('conta_id', $c->id)->pendentes();
        $n = (clone $aConciliar)->count();
        $antigas = (clone $aConciliar)->whereDate('data', '<', $agora->subDays(7))->count();

        [$estado, $rotulo] = match (true) {
            ! $c->lembrete_ativo => ['sem_lembrete', 'Sem lembrete'],
            $c->lembrete_dispensado_ate && $c->lembrete_dispensado_ate->gte($agora->startOfDay()) => ['dispensado', 'Dispensado até '.$c->lembrete_dispensado_ate->format('d/m/Y')],
            $exigido && (! $c->extrato_ate || $c->extrato_ate->lt($exigido)) => ['desatualizado', 'Atualização pendente'],
            default => ['em_dia', 'Em dia'],
        };

        return ['estado' => $estado, 'rotulo' => $rotulo, 'exigido_ate' => $exigido,
            'proximo_prazo' => $c->lembrete_ativo ? $this->proximoPrazo($c, $agora) : null, 'a_conciliar' => $n, 'antigas' => $antigas];
    }

    /** Último prazo da regra que já passou (dia e horário configurados). */
    public function ultimoPrazo(ContaFinanceira $c, CarbonImmutable $agora): ?CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $c->lembrete_hora ?: '09:00'));
        for ($i = 0; $i <= 62; $i++) {
            $p = $agora->subDays($i)->setTime($h, $m);
            if ($p->lte($agora) && $this->ehDiaDePrazo($c, $p)) {
                return $p;
            }
        }

        return null;
    }

    public function proximoPrazo(ContaFinanceira $c, CarbonImmutable $agora): ?CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $c->lembrete_hora ?: '09:00'));
        for ($i = 0; $i <= 62; $i++) {
            $p = $agora->addDays($i)->setTime($h, $m);
            if ($p->gt($agora) && $this->ehDiaDePrazo($c, $p)) {
                return $p;
            }
        }

        return null;
    }

    private function ehDiaDePrazo(ContaFinanceira $c, CarbonImmutable $dia): bool
    {
        return match ($c->lembrete_frequencia) {
            'mensal' => $dia->day === min((int) $c->lembrete_dia, $dia->daysInMonth),
            // Quinzenal: o dia da semana escolhido, em semanas alternadas a partir do cadastro da conta.
            'quinzenal' => $dia->dayOfWeekIso === (int) $c->lembrete_dia
                && intdiv((int) CarbonImmutable::instance($c->created_at ?? $dia)->startOfWeek()->diffInDays($dia->startOfWeek()), 7) % 2 === 0,
            default => $dia->dayOfWeekIso === (int) $c->lembrete_dia,
        };
    }

    /** Destinatários: o responsável (se ainda tiver acesso) ou administradores e financeiro da empresa. */
    private function destinatarios(ContaFinanceira $c): array
    {
        $vigentes = Vinculo::vigentes()->where('organizacao_id', $c->organizacao_id)->whereIn('papel', [Papel::Admin, Papel::Financeiro]);
        if ($c->lembrete_responsavel_id && (clone $vigentes)->where('user_id', $c->lembrete_responsavel_id)->exists()) {
            return [$c->lembrete_responsavel_id];
        }

        return $vigentes->pluck('user_id')->unique()->values()->all();
    }

    /**
     * Gera as notificações devidas agora (rodado a cada hora pelo agendador; idempotente).
     *
     * @return int notificações criadas
     */
    public function notificar(?CarbonImmutable $agora = null): int
    {
        $agora ??= CarbonImmutable::now();
        $criadas = 0;
        $contas = ContaFinanceira::withoutGlobalScope('organizacao')->where('ativa', true)->where('lembrete_ativo', true)->get();

        foreach ($contas as $c) {
            $s = $this->situacao($c, $agora);
            $url = route('financeiro.conta', $c);
            $ate = $c->extrato_ate ? 'até '.$c->extrato_ate->format('d/m/Y') : 'sem nenhum extrato importado';

            if ($s['estado'] === 'desatualizado') {
                // Repete a cada N dias enquanto continuar atrasado.
                $bloco = intdiv((int) $s['exigido_ate']->diffInDays($agora->startOfDay()), max(1, (int) $c->lembrete_repetir_dias));
                foreach ($this->destinatarios($c) as $uid) {
                    $criadas += (int) Notificacao::avisar($uid, $c->organizacao_id, 'extrato_pendente',
                        "extrato:{$c->id}:{$s['exigido_ate']->toDateString()}:{$bloco}", 'Extrato bancário pendente',
                        "O extrato da conta {$c->nome} está atualizado {$ate}. Importe o período até {$s['exigido_ate']->format('d/m/Y')} para manter o controle e a conciliação em dia.", $url);
                }
            } elseif ($s['estado'] === 'em_dia' && $c->lembrete_antecedencia > 0 && $s['proximo_prazo']
                && $agora->diffInDays($s['proximo_prazo'], false) <= $c->lembrete_antecedencia) {
                $exigirAte = $s['proximo_prazo']->subDay();
                if (! $c->extrato_ate || $c->extrato_ate->lt($exigirAte->startOfDay())) {
                    foreach ($this->destinatarios($c) as $uid) {
                        $criadas += (int) Notificacao::avisar($uid, $c->organizacao_id, 'extrato_aviso',
                            "extrato-aviso:{$c->id}:{$exigirAte->toDateString()}", 'Atualização de extrato em breve',
                            "A conta {$c->nome} deve ter o extrato atualizado até {$exigirAte->format('d/m/Y')} no prazo de {$s['proximo_prazo']->format('d/m/Y H:i')}.", $url);
                    }
                }
            }

            if ($s['antigas'] > 0) {
                foreach ($this->destinatarios($c) as $uid) {
                    $criadas += (int) Notificacao::avisar($uid, $c->organizacao_id, 'conciliacao_pendente',
                        "conciliacao:{$c->id}:{$agora->format('o-W')}", 'Conciliação pendente',
                        "A conta {$c->nome} tem {$s['a_conciliar']} lançamento(s) do extrato sem conciliar, {$s['antigas']} com mais de 7 dias.",
                        route('financeiro.conciliacao', $c));
                }
            }
        }

        return $criadas;
    }

    /** Dispensa (ou adiamento) registrada em auditoria. */
    public function dispensar(ContaFinanceira $c, CarbonImmutable $ate, string $motivo): void
    {
        $c->update(['lembrete_dispensado_ate' => $ate->toDateString(), 'lembrete_dispensa_motivo' => $motivo]);
        Evento::registrar('financeiro.lembrete_dispensado', $c, ['ate' => $ate->format('d/m/Y'), 'motivo' => $motivo]);
    }

    /** Após importar, a cobertura só avança (um arquivo antigo não "atualiza" a conta). */
    public function registrarCobertura(ContaFinanceira $c, ?string $fim): void
    {
        $novo = $fim && (! $c->extrato_ate || CarbonImmutable::parse($fim)->gt($c->extrato_ate)) ? $fim : $c->extrato_ate?->toDateString();
        $c->update(['extrato_ate' => $novo, 'extrato_importado_em' => now()]);
    }
}
