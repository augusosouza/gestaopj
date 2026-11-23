<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NotaFiscal extends Model
{
    use DaOrganizacao;

    protected $table = 'notas_fiscais';

    protected $fillable = ['organizacao_id',
        'direcao', 'chave_acesso', 'numero', 'emitente_documento', 'emitente_nome',
        'tomador_documento', 'tomador_nome', 'competencia', 'emitida_em', 'codigo_servico',
        'descricao_servico', 'valor_servicos_centavos', 'valor_liquido_centavos', 'ambiente',
        'origem', 'xml_path', 'xml_sha256', 'danfse_path', 'danfse_versao', 'danfse_gerado_em', 'enviada_por',
    ];

    protected function casts(): array
    {
        return ['competencia' => 'date', 'emitida_em' => 'datetime', 'danfse_gerado_em' => 'datetime'];
    }

    public function fatura(): HasOne
    {
        return $this->hasOne(Fatura::class);
    }

    public function cobranca(): HasOne
    {
        return $this->hasOne(Cobranca::class);
    }

    public function enviadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviada_por');
    }

    public function temXml(): bool
    {
        return $this->xml_path !== null;
    }
}
