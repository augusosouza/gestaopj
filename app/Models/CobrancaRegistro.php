<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contato, promessa ou renegociação com o cliente. Imutável: o histórico de cobrança não se reescreve. */
class CobrancaRegistro extends Model
{
    use DaOrganizacao;

    protected $table = 'cobranca_registros';

    public const TIPOS = ['contato' => 'Contato', 'promessa' => 'Promessa de pagamento', 'renegociacao' => 'Renegociação'];

    public const CANAIS = ['telefone' => 'Telefone', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'presencial' => 'Presencial', 'outro' => 'Outro'];

    protected $fillable = ['organizacao_id', 'titulo_id', 'parcela_id', 'tipo', 'canal', 'data', 'mensagem', 'resposta', 'promessa_data',
        'promessa_valor_centavos', 'detalhes', 'responsavel_id', 'criado_por'];

    protected function casts(): array
    {
        return ['data' => 'datetime', 'promessa_data' => 'date', 'detalhes' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('O histórico de cobrança não é editado. Registre um novo contato.'));
        static::deleting(fn () => throw new \LogicException('O histórico de cobrança não é apagado.'));
    }

    public function titulo(): BelongsTo
    {
        return $this->belongsTo(Titulo::class);
    }

    public function parcela(): BelongsTo
    {
        return $this->belongsTo(Parcela::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /**
     * Situação da promessa, calculada pelas baixas reais (nunca pela promessa em si):
     * cumprida = recebido desde o registro ≥ valor prometido; quebrada = prazo passou sem isso.
     */
    public function situacaoPromessa(): ?string
    {
        if ($this->tipo !== 'promessa') {
            return null;
        }
        $alvo = $this->parcela_id ? Parcela::whereKey($this->parcela_id) : Parcela::where('titulo_id', $this->titulo_id);
        $recebido = (int) Movimento::where('tipo', 'baixa')->whereIn('parcela_id', $alvo->pluck('id'))
            ->whereDate('data', '>=', $this->data->toDateString())->whereDate('data', '<=', $this->promessa_data->toDateString())
            ->with('estorno')->get()->reject->estornado()->sum('valor_centavos');

        return match (true) {
            $recebido >= (int) $this->promessa_valor_centavos => 'cumprida',
            $this->promessa_data->lt(CarbonImmutable::today()) => 'quebrada',
            default => 'aguardando',
        };
    }
}
