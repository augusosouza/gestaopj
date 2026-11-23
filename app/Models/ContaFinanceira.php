<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContaFinanceira extends Model
{
    use DaOrganizacao;

    protected $table = 'contas_financeiras';

    public const TIPOS = ['corrente' => 'Conta corrente', 'poupanca' => 'Poupança', 'caixa' => 'Caixa', 'investimento' => 'Investimento'];

    public const FREQUENCIAS = ['semanal' => 'Semanal', 'quinzenal' => 'Quinzenal', 'mensal' => 'Mensal'];

    public const DIAS_SEMANA = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];

    protected $fillable = ['organizacao_id', 'nome', 'tipo', 'banco', 'identificacao', 'saldo_inicial_centavos', 'saldo_inicial_em', 'ativa',
        'lembrete_ativo', 'lembrete_frequencia', 'lembrete_dia', 'lembrete_hora', 'lembrete_antecedencia', 'lembrete_repetir_dias',
        'lembrete_responsavel_id', 'extrato_ate', 'extrato_importado_em', 'lembrete_dispensado_ate', 'lembrete_dispensa_motivo', 'saldo_extrato_centavos', 'saldo_extrato_em'];

    protected function casts(): array
    {
        return ['saldo_inicial_em' => 'date', 'ativa' => 'boolean', 'lembrete_ativo' => 'boolean', 'extrato_ate' => 'date',
            'extrato_importado_em' => 'datetime', 'lembrete_dispensado_ate' => 'date', 'saldo_extrato_em' => 'date'];
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lembrete_responsavel_id');
    }

    public function importacoes(): HasMany
    {
        return $this->hasMany(ExtratoImportacao::class, 'conta_id')->latest('id');
    }

    /** Descrição legível da regra, ex.: "toda segunda-feira às 09:00". */
    public function regraLembrete(): string
    {
        return match ($this->lembrete_frequencia) {
            'mensal' => "todo dia {$this->lembrete_dia} do mês às {$this->lembrete_hora}",
            'quinzenal' => 'a cada 15 dias, '.(self::DIAS_SEMANA[$this->lembrete_dia] ?? '').", às {$this->lembrete_hora}",
            default => 'toda '.(self::DIAS_SEMANA[$this->lembrete_dia] ?? '')." às {$this->lembrete_hora}",
        };
    }

    public function movimentos(): HasMany
    {
        return $this->hasMany(Movimento::class, 'conta_id');
    }

    public function extrato(): HasMany
    {
        return $this->hasMany(ExtratoLinha::class, 'conta_id');
    }

    /**
     * Saldo em uma data (padrão: hoje). Baixa soma ou subtrai conforme o tipo do título;
     * o estorno anula a baixa na data do estorno, preservando os saldos passados.
     */
    public function saldo(?\DateTimeInterface $em = null): int
    {
        $em = ($em ?? now())->format('Y-m-d');
        $saldo = $this->saldo_inicial_centavos;
        $movs = Movimento::with('parcela.titulo', 'estornoDe.parcela.titulo')
            ->where('conta_id', $this->id)->whereDate('data', '<=', $em)->get();
        foreach ($movs as $m) {
            $saldo += $m->efeitoNaConta();
        }
        // Transferências entre contas da empresa: mexem no saldo, não em receitas/despesas.
        $saldo += (int) Transferencia::where('conta_destino_id', $this->id)->whereDate('data', '<=', $em)->sum('valor_centavos');
        $saldo -= (int) Transferencia::where('conta_origem_id', $this->id)->whereDate('data', '<=', $em)->sum('valor_centavos');

        return $saldo;
    }
}
