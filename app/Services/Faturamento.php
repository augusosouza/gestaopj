<?php

namespace App\Services;

use App\Enums\StatusCobranca;
use App\Enums\StatusFatura;
use App\Models\Alocacao;
use App\Models\Cobranca;
use App\Models\Contrato;
use App\Models\Evento;
use App\Models\Fatura;
use App\Models\NotaFiscal;
use App\Services\Nfse\RegistroNotas;
use App\Support\Dinheiro;
use App\Support\Documento;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Operações do ciclo mensal: gerar competência, vincular notas, receber e pagar. */
final class Faturamento
{
    public function __construct(
        private RegistroNotas $notas,
        private ConferenciaNota $conferencia,
    ) {}

    /**
     * Gera faturas (a receber) e cobranças (a pagar) da competência.
     * Idempotente: rodar de novo não duplica nada.
     *
     * @return array{faturas: int, cobrancas: int}
     */
    public function gerarCompetencia(CarbonImmutable $competencia): array
    {
        $competencia = $competencia->startOfMonth();
        $criadas = ['faturas' => 0, 'cobrancas' => 0];

        DB::transaction(function () use ($competencia, &$criadas) {
            foreach (Contrato::vigenteEm($competencia)->get() as $contrato) {
                // whereDate em vez de firstOrCreate: compara só a data, independente
                // de como o banco armazena a coluna (DATE no MariaDB, texto no SQLite).
                $existe = Fatura::where('contrato_id', $contrato->id)->whereDate('competencia', $competencia)->exists();
                if (! $existe) {
                    Fatura::create([
                        'organizacao_id' => $contrato->organizacao_id,
                        'contrato_id' => $contrato->id,
                        'competencia' => $competencia,
                        'valor_centavos' => $contrato->valor_mensal_centavos,
                        'vencimento' => $this->vencimento($competencia, $contrato->dia_vencimento),
                        'status' => StatusFatura::Aberta,
                    ]);
                    $criadas['faturas']++;
                }

                foreach (Alocacao::where('contrato_id', $contrato->id)->vigenteEm($competencia)->get() as $aloc) {
                    if (Cobranca::where('alocacao_id', $aloc->id)->whereDate('competencia', $competencia)->exists()) {
                        continue;
                    }
                    Cobranca::create([
                        'organizacao_id' => $contrato->organizacao_id,
                        'alocacao_id' => $aloc->id,
                        'competencia' => $competencia,
                        'prestador_id' => $aloc->prestador_id,
                        'contrato_id' => $contrato->id,
                        'valor_centavos' => $aloc->valor_mensal_centavos,
                        'status' => StatusCobranca::AguardandoNota,
                    ]);
                    $criadas['cobrancas']++;
                }
            }
        });

        return $criadas;
    }

    /** Vencimento no mês seguinte à competência (serviço prestado, cobrado depois). */
    public function vencimento(CarbonImmutable $competencia, int $dia): string
    {
        $mes = $competencia->addMonthNoOverflow()->startOfMonth();

        return $mes->setDay(min($dia, $mes->daysInMonth))->toDateString();
    }

    /** Vincula o XML da NFS-e emitida pela empresa à fatura, com conferência estrita. */
    public function vincularNotaEmitida(Fatura $fatura, UploadedFile $xml): NotaFiscal
    {
        if ($fatura->status !== StatusFatura::Aberta) {
            throw ValidationException::withMessages(['xml' => 'Esta fatura já tem nota vinculada.']);
        }
        $fatura->loadMissing('contrato.empresa', 'contrato.cliente');

        return DB::transaction(function () use ($fatura, $xml) {
            $nota = $this->notas->deXml($fatura->organizacao_id, $xml, 'emitida');

            $erros = [];
            if ($nota->emitente_documento !== $fatura->contrato->empresa->cnpj) {
                $erros[] = 'Emitente da nota ('.Documento::formatar($nota->emitente_documento).') não é a empresa do contrato.';
            }
            if ($nota->tomador_documento !== $fatura->contrato->cliente->documento) {
                $erros[] = 'Tomador da nota ('.Documento::formatar($nota->tomador_documento).') não é o cliente do contrato.';
            }
            if ($nota->valor_servicos_centavos !== $fatura->valor_centavos) {
                $erros[] = 'Valor da nota ('.Dinheiro::formatar($nota->valor_servicos_centavos).') difere da fatura ('.Dinheiro::formatar($fatura->valor_centavos).').';
            }
            if ($nota->competencia?->format('Y-m') !== $fatura->competencia->format('Y-m')) {
                $erros[] = 'Competência da nota ('.($nota->competencia?->format('m/Y') ?? '—').') difere da fatura ('.$fatura->competencia->format('m/Y').').';
            }
            if ($erros) {
                // rollback desfaz o registro da nota; remove o arquivo salvo
                if ($nota->xml_path) {
                    Storage::disk('local')->delete($nota->xml_path);
                }
                throw ValidationException::withMessages(['xml' => $erros]);
            }

            $fatura->update(['nota_fiscal_id' => $nota->id, 'status' => StatusFatura::Emitida]);
            Evento::registrar('fatura.nota_vinculada', $fatura, ['chave' => $nota->chave_acesso]);

            return $nota;
        });
    }

    public function registrarRecebimento(Fatura $fatura, int $valorCentavos, string $data): void
    {
        if ($fatura->status !== StatusFatura::Emitida) {
            throw ValidationException::withMessages(['valor' => 'Só é possível registrar recebimento de fatura com nota emitida.']);
        }
        $fatura->update([
            'status' => StatusFatura::Recebida,
            'valor_recebido_centavos' => $valorCentavos,
            'recebida_em' => $data,
        ]);
        Evento::registrar('fatura.recebida', $fatura, ['valor' => $valorCentavos, 'data' => $data]);
    }

    /** Prestador envia XML ou chave; a conferência roda na hora. */
    public function enviarNotaPrestador(Cobranca $cobranca, ?UploadedFile $xml, ?string $chave): NotaFiscal
    {
        if (! $cobranca->status->aceitaNota()) {
            throw ValidationException::withMessages(['xml' => 'Esta cobrança não aceita envio de nota no status atual.']);
        }
        if (! $cobranca->prestador->tipo->emiteNfse()) {
            throw ValidationException::withMessages(['xml' => 'Prestador pessoa física segue o fluxo de documentação próprio.']);
        }

        return DB::transaction(function () use ($cobranca, $xml, $chave) {
            // Reenvio após rejeição: desvincula a nota anterior.
            if ($cobranca->nota_fiscal_id) {
                $anterior = $cobranca->notaFiscal;
                $cobranca->update(['nota_fiscal_id' => null]);
                if ($anterior) {
                    if ($anterior->xml_path) {
                        Storage::disk('local')->delete($anterior->xml_path);
                    }
                    $anterior->delete();
                }
            }

            $org = $cobranca->organizacao_id;
            $nota = $xml ? $this->notas->deXml($org, $xml, 'recebida') : $this->notas->deChave($org, (string) $chave, 'recebida');

            $cobranca->update([
                'nota_fiscal_id' => $nota->id,
                'status' => StatusCobranca::EmConferencia,
                'conferencia' => $this->conferencia->conferir($cobranca, $nota),
                'motivo_rejeicao' => null,
            ]);
            Evento::registrar('cobranca.nota_enviada', $cobranca, ['chave' => $nota->chave_acesso, 'origem' => $nota->origem]);

            return $nota;
        });
    }

    /** Recalcula a conferência (ex.: após anexar o XML ou a contabilidade confirmar o perfil). */
    public function reconferir(Cobranca $cobranca): void
    {
        if ($cobranca->notaFiscal) {
            $cobranca->update(['conferencia' => $this->conferencia->conferir($cobranca, $cobranca->notaFiscal)]);
        }
    }

    public function aprovar(Cobranca $cobranca, ?string $justificativa): void
    {
        if ($cobranca->status !== StatusCobranca::EmConferencia) {
            throw ValidationException::withMessages(['justificativa' => 'Só cobranças em conferência podem ser aprovadas.']);
        }
        $this->reconferir($cobranca);
        if (! $cobranca->conferenciaOk() && blank($justificativa)) {
            throw ValidationException::withMessages(['justificativa' => 'A conferência tem pendências: informe a justificativa para aprovar com ressalva.']);
        }
        $cobranca->forceFill([
            'status' => StatusCobranca::Aprovada,
            'aprovada_por' => auth()->id(),
            'aprovada_em' => now(),
        ])->save();
        Evento::registrar('cobranca.aprovada', $cobranca, array_filter(['justificativa' => $justificativa]));
    }

    public function rejeitar(Cobranca $cobranca, string $motivo): void
    {
        if ($cobranca->status !== StatusCobranca::EmConferencia) {
            throw ValidationException::withMessages(['motivo' => 'Só cobranças em conferência podem ser rejeitadas.']);
        }
        $cobranca->update(['status' => StatusCobranca::Rejeitada, 'motivo_rejeicao' => $motivo]);
        Evento::registrar('cobranca.rejeitada', $cobranca, ['motivo' => $motivo]);
    }

    public function registrarPagamento(Cobranca $cobranca, int $valorCentavos, string $data): void
    {
        if ($cobranca->status !== StatusCobranca::Aprovada) {
            throw ValidationException::withMessages(['valor' => 'Só cobranças aprovadas podem ser pagas.']);
        }
        $cobranca->update([
            'status' => StatusCobranca::Paga,
            'valor_pago_centavos' => $valorCentavos,
            'paga_em' => $data,
        ]);
        Evento::registrar('cobranca.paga', $cobranca, ['valor' => $valorCentavos, 'data' => $data]);
    }
}
