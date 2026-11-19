<?php

namespace App\Models;

use App\Support\Contexto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Evento extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organizacao_id', 'ip', 'user_id', 'acao', 'alvo_type', 'alvo_id', 'dados'];

    protected function casts(): array
    {
        return ['dados' => 'array'];
    }

    public function alvo(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Descrição legível da ação para o histórico. */
    public function rotulo(): string
    {
        return [
            'cobranca.nota_enviada' => 'Nota enviada',
            'cobranca.aprovada' => 'Aprovada para pagamento',
            'cobranca.rejeitada' => 'Nota rejeitada',
            'cobranca.paga' => 'Pagamento registrado',
            'cobranca.xml_anexado' => 'XML anexado',
            'fatura.nota_vinculada' => 'NFS-e vinculada',
            'fatura.recebida' => 'Recebimento registrado',
            'perfil_fiscal.criado' => 'Perfil fiscal registrado',
            'perfil_fiscal.confirmado' => 'Perfil fiscal confirmado',
            'nota.xml_baixado' => 'XML baixado',
            'fechamento.exportado' => 'Fechamento exportado',
            'plataforma.configuracao' => 'Configuração da plataforma alterada',
        ][$this->acao] ?? $this->acao;
    }

    public static function registrar(string $acao, Model $alvo, array $dados = []): self
    {
        return static::create([
            'organizacao_id' => $alvo->getAttribute('organizacao_id') ?? app(Contexto::class)->organizacaoId(),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'user_id' => auth()->id(),
            'acao' => $acao,
            'alvo_type' => $alvo->getMorphClass(),
            'alvo_id' => $alvo->getKey(),
            'dados' => $dados ?: null,
        ]);
    }
}
