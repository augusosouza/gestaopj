<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContaFinanceira extends Model
{
    use DaOrganizacao;

    protected $table = 'contas_financeiras';

    public const TIPOS = ['corrente' => 'Conta corrente', 'poupanca' => 'Poupança', 'caixa' => 'Caixa', 'investimento' => 'Investimento'];

    protected $fillable = ['organizacao_id', 'nome', 'tipo', 'banco', 'identificacao', 'saldo_inicial_centavos', 'saldo_inicial_em', 'ativa'];

    protected function casts(): array
    {
        return ['saldo_inicial_em' => 'date', 'ativa' => 'boolean'];
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

        return $saldo;
    }
}
