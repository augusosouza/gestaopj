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
            'nota.danfse_gerado' => 'DANFSe gerado (Receita)',
            'fechamento.exportado' => 'Fechamento exportado',
            'plataforma.configuracao' => 'Configuração da plataforma alterada',
            'plataforma.saude_verificada' => 'Saúde do sistema verificada',
            'contabil.documento_enviado' => 'Documento enviado',
            'financeiro.titulo_criado' => 'Lançamento criado',
            'financeiro.titulo_cancelado' => 'Lançamento cancelado',
            'financeiro.baixa' => 'Baixa registrada',
            'financeiro.estorno' => 'Baixa estornada',
            'financeiro.conciliado' => 'Conciliado com o extrato',
            'financeiro.desconciliado' => 'Conciliação desfeita',
            'financeiro.extrato_importado' => 'Extrato importado',
            'financeiro.lembrete_configurado' => 'Lembrete bancário configurado',
            'financeiro.lembrete_dispensado' => 'Lembrete bancário dispensado',
            'financeiro.compra_registrada' => 'Compra registrada',
            'financeiro.compra_cancelada' => 'Compra cancelada',
            'financeiro.cartao_cadastrado' => 'Cartão cadastrado',
            'financeiro.cartao_atualizado' => 'Cartão atualizado',
            'financeiro.fatura_cartao_paga' => 'Fatura do cartão paga',
            'financeiro.transferencia' => 'Transferência entre contas',
            'financeiro.transferencia_estornada' => 'Transferência estornada',
            'financeiro.exportado' => 'Relatório exportado',
            'sugestao.dispensada' => 'Sugestão dispensada',
            'sugestao.adiada' => 'Sugestão adiada',
            'contabil.documento_baixado' => 'Documento baixado',
            'contabil.enviada' => 'Empresa concluiu o envio',
            'contabil.solicitacao_criada' => 'Solicitação do contador',
            'contabil.solicitacao_respondida' => 'Solicitação respondida',
            'contabil.solicitacao_aceita' => 'Solicitação resolvida',
            'contabil.solicitacao_devolvida' => 'Solicitação devolvida',
            'contabil.solicitacao_cancelada' => 'Solicitação cancelada',
            'contabil.etapa' => 'Etapa alterada',
            'contabil.fechada' => 'Competência fechada',
            'contabil.reaberta' => 'Competência reaberta',
            'contabil.versao_baixada' => 'Pacote do fechamento baixado',
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
