<?php

namespace App\Services\Nfse;

use Carbon\CarbonImmutable;

/** Campos da NFS-e (padrão nacional) usados no controle de faturamento. */
final class DadosNfse
{
    public function __construct(
        public readonly string $chaveAcesso,
        public readonly ?string $numero,
        public readonly string $emitenteDocumento,
        public readonly ?string $emitenteNome,
        public readonly ?string $tomadorDocumento,
        public readonly ?string $tomadorNome,
        public readonly ?CarbonImmutable $competencia,
        public readonly ?CarbonImmutable $emitidaEm,
        public readonly ?string $codigoServico,
        public readonly ?string $descricaoServico,
        public readonly ?int $valorServicosCentavos,
        public readonly ?int $valorLiquidoCentavos,
        public readonly ?string $ambiente,
    ) {}

    public function paraModelo(): array
    {
        return [
            'chave_acesso' => $this->chaveAcesso,
            'numero' => $this->numero,
            'emitente_documento' => $this->emitenteDocumento,
            'emitente_nome' => $this->emitenteNome,
            'tomador_documento' => $this->tomadorDocumento,
            'tomador_nome' => $this->tomadorNome,
            'competencia' => $this->competencia?->toDateString(),
            'emitida_em' => $this->emitidaEm,
            'codigo_servico' => $this->codigoServico,
            'descricao_servico' => $this->descricaoServico,
            'valor_servicos_centavos' => $this->valorServicosCentavos,
            'valor_liquido_centavos' => $this->valorLiquidoCentavos,
            'ambiente' => $this->ambiente,
        ];
    }
}
