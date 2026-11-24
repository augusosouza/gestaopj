<?php

namespace App\Services;

use App\Enums\Papel;
use App\Enums\StatusCobranca;
use App\Enums\StatusFatura;
use App\Models\Alocacao;
use App\Models\Cobranca;
use App\Models\Contrato;
use App\Models\Evento;
use App\Models\Fatura;
use App\Models\NotaFiscal;
use App\Services\Nfse\NfseXmlInvalido;
use App\Services\Nfse\NfseXmlParser;
use App\Services\Nfse\RegistroNotas;
use App\Support\Contexto;
use App\Support\Dinheiro;
use App\Support\Documento;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Operações do ciclo mensal: gerar competência, vincular notas, receber e pagar. */
final class Faturamento
{
    public function __construct(
        private RegistroNotas $notas,
        private ConferenciaNota $conferencia,
        private Financeiro $financeiro,
    ) {}

    /** Compara contratos vigentes com os registros já existentes na competência. Só consulta. */
    public function preverCompetencia(CarbonImmutable $competencia): Collection
    {
        $competencia = $competencia->startOfMonth();

        return Contrato::vigenteEm($competencia)->orderBy('id')->with([
            'cliente',
            'faturas' => fn ($q) => $q->whereDate('competencia', $competencia)->orderBy('id'),
            'alocacoes' => fn ($q) => $q->vigenteEm($competencia)->orderBy('id')->with('prestador'),
            'cobrancas' => fn ($q) => $q->whereDate('competencia', $competencia)->orderBy('id'),
        ])->get()->map(function (Contrato $contrato) use ($competencia) {
            $problema = match (true) {
                ! $contrato->cliente => 'Cliente indisponível na empresa.',
                $contrato->valor_mensal_centavos <= 0 => 'Valor mensal inválido.',
                $contrato->dia_vencimento < 1 || $contrato->dia_vencimento > 31 => 'Dia de vencimento inválido.',
                default => null,
            };
            $existentes = $contrato->cobrancas->keyBy('alocacao_id');
            $alocacoes = $contrato->alocacoes->map(fn (Alocacao $alocacao) => [
                'alocacao' => $alocacao,
                'existente' => $existentes->get($alocacao->id),
                'problema' => ! $alocacao->prestador || $alocacao->valor_mensal_centavos <= 0 ? 'Confira o prestador e o valor da alocação.' : null,
            ]);

            return [
                'contrato' => $contrato,
                'fatura' => $contrato->faturas->first(),
                'problema' => $problema,
                'vencimento' => $problema ? null : $this->vencimento($competencia, $contrato->dia_vencimento),
                'alocacoes' => $alocacoes,
            ];
        });
    }

    public function revisaoCompetencia(Collection $previa, CarbonImmutable $competencia): string
    {
        $itens = $previa->map(fn ($item) => [
            $item['contrato']->id, $item['contrato']->cliente_id, $item['contrato']->valor_mensal_centavos,
            $item['fatura']?->id, $item['vencimento'], $item['problema'],
            $item['alocacoes']->map(fn ($a) => [$a['alocacao']->id, $a['alocacao']->prestador_id,
                $a['alocacao']->valor_mensal_centavos, $a['existente']?->id, $a['problema']])->all(),
        ])->all();

        return hash_hmac('sha256', json_encode([auth()->id(), ctx()->organizacaoId(), $competencia->format('Y-m'), $itens], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** Gera faturas e cobranças usando a mesma seleção da prévia. */
    public function gerarCompetencia(CarbonImmutable $competencia, ?string $revisao = null): array
    {
        $competencia = $competencia->startOfMonth();

        return DB::transaction(function () use ($competencia, $revisao) {
            Contrato::vigenteEm($competencia)->orderBy('id')->lockForUpdate()->pluck('id');
            $previa = $this->preverCompetencia($competencia);
            if ($revisao !== null && ! hash_equals($this->revisaoCompetencia($previa, $competencia), $revisao)) {
                throw ValidationException::withMessages(['revisao' => 'A competência mudou. Confira novamente antes de gerar.']);
            }
            $criadas = ['faturas' => 0, 'cobrancas' => 0];
            foreach ($previa as $item) {
                $contrato = $item['contrato'];
                if ($item['problema']) {
                    continue;
                }
                if (! $item['fatura']) {
                    $fatura = Fatura::create([
                        'organizacao_id' => $contrato->organizacao_id,
                        'contrato_id' => $contrato->id,
                        'competencia' => $competencia,
                        'valor_centavos' => $contrato->valor_mensal_centavos,
                        'vencimento' => $item['vencimento'],
                        'status' => StatusFatura::Aberta,
                    ]);
                    $this->financeiro->tituloDeFatura($fatura);
                    Evento::registrar('fatura.gerada', $fatura);
                    $criadas['faturas']++;
                }
                foreach ($item['alocacoes'] as $itemAlocacao) {
                    if ($itemAlocacao['existente'] || $itemAlocacao['problema']) {
                        continue;
                    }
                    $aloc = $itemAlocacao['alocacao'];
                    $cob = Cobranca::create([
                        'organizacao_id' => $contrato->organizacao_id,
                        'alocacao_id' => $aloc->id,
                        'competencia' => $competencia,
                        'prestador_id' => $aloc->prestador_id,
                        'contrato_id' => $contrato->id,
                        'valor_centavos' => $aloc->valor_mensal_centavos,
                        'status' => StatusCobranca::AguardandoNota,
                    ]);
                    $this->financeiro->tituloDeCobranca($cob);
                    $criadas['cobrancas']++;
                }
            }
            if ($empresa = ctx()->empresa()) {
                Evento::registrar('competencia.gerada', $empresa, ['competencia' => $competencia->format('Y-m'), ...$criadas]);
            }

            return $criadas;
        }, 3);
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

    /**
     * Recebimento do cliente: vira baixa no título da fatura. Pode ser parcial;
     * a fatura só fica "Recebida" quando o saldo zera.
     */
    public function registrarRecebimento(Fatura $fatura, int $valorCentavos, string $data, ?int $contaId = null, ?string $chave = null): void
    {
        if (! in_array($fatura->status, [StatusFatura::Emitida], true)) {
            throw ValidationException::withMessages(['valor' => 'Só é possível registrar recebimento de fatura com nota emitida e saldo em aberto.']);
        }
        $titulo = $this->financeiro->tituloDeFatura($fatura);
        $this->financeiro->baixar($titulo->parcelas()->first(), [
            'conta_id' => $contaId ?? $this->financeiro->contaPrincipal($fatura->organizacao_id)->id,
            'data' => $data, 'valor' => $valorCentavos, 'chave' => $chave,
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

        // 1. Documento fiscalmente inválido: justificativa não resolve.
        if ($cobranca->invalidaFiscalmente()) {
            throw ValidationException::withMessages(['justificativa' => 'A nota não é válida para esta cobrança (não passou em: '
                .mb_strtolower(implode('; ', $cobranca->falhas('fiscal'))).'). Rejeite e peça uma nova nota.']);
        }
        // 2. Divergência comercial: justificativa + alçada de administrador.
        if ($cobranca->temDivergenciaComercial()) {
            if (! app(Contexto::class)->tem(Papel::Admin)) {
                throw ValidationException::withMessages(['justificativa' => 'Divergência comercial (não passou em: '
                    .mb_strtolower(implode('; ', $cobranca->falhas('comercial'))).'): só o administrador pode aprovar com ressalva.']);
            }
            if (blank($justificativa)) {
                throw ValidationException::withMessages(['justificativa' => 'Informe a justificativa para aprovar com divergência comercial.']);
            }
        }
        // 3. Não verificada (só chave): justificativa; a pendência fiscal continua visível.
        if ($cobranca->naoVerificada() && blank($justificativa)) {
            throw ValidationException::withMessages(['justificativa' => 'A nota ainda não foi verificada (sem XML): informe a justificativa.']);
        }

        $cobranca->forceFill([
            'status' => StatusCobranca::Aprovada,
            'aprovada_por' => auth()->id(),
            'aprovada_em' => now(),
        ])->save();
        Evento::registrar('cobranca.aprovada', $cobranca, array_filter(['justificativa' => $justificativa]));
    }

    /**
     * Completa uma nota informada só pela chave com o XML correspondente, em qualquer
     * etapa (inclusive já paga), e refaz a conferência.
     */
    public function anexarXml(Cobranca $cobranca, UploadedFile $xml): void
    {
        $nota = $cobranca->notaFiscal;
        if (! $nota || $nota->temXml()) {
            throw ValidationException::withMessages(['xml' => 'Esta cobrança não tem nota aguardando XML.']);
        }
        try {
            $dados = app(NfseXmlParser::class)->ler($xml->get());
        } catch (NfseXmlInvalido $e) {
            throw ValidationException::withMessages(['xml' => $e->getMessage()]);
        }
        if ($dados->chaveAcesso !== $nota->chave_acesso) {
            throw ValidationException::withMessages(['xml' => 'O XML é de outra nota (a chave não corresponde à informada).']);
        }
        $this->notas->deXml($cobranca->organizacao_id, $xml, 'recebida');
        $cobranca->load('notaFiscal');
        $this->reconferir($cobranca);
        Evento::registrar('cobranca.xml_anexado', $cobranca, ['chave' => $nota->chave_acesso]);
    }

    public function rejeitar(Cobranca $cobranca, string $motivo): void
    {
        if ($cobranca->status !== StatusCobranca::EmConferencia) {
            throw ValidationException::withMessages(['motivo' => 'Só cobranças em conferência podem ser rejeitadas.']);
        }
        $cobranca->update(['status' => StatusCobranca::Rejeitada, 'motivo_rejeicao' => $motivo]);
        Evento::registrar('cobranca.rejeitada', $cobranca, ['motivo' => $motivo]);
    }

    /**
     * Pagamento ao prestador: vira baixa no título da cobrança. A retenção (diferença entre
     * valor da nota e líquido) abate a parcela sem sair da conta. Pode ser parcial.
     */
    public function registrarPagamento(Cobranca $cobranca, int $valorCentavos, string $data, ?int $contaId = null, ?int $retencao = null, ?string $chave = null): void
    {
        if ($cobranca->status !== StatusCobranca::Aprovada) {
            throw ValidationException::withMessages(['valor' => 'Só cobranças aprovadas (com saldo em aberto) podem ser pagas.']);
        }
        if ($retencao === null) {
            $nota = $cobranca->notaFiscal;
            $retencao = ($nota && $nota->valor_liquido_centavos !== null && $nota->valor_servicos_centavos !== null)
                ? max(0, $nota->valor_servicos_centavos - $nota->valor_liquido_centavos) : 0;
        }
        $titulo = $this->financeiro->tituloDeCobranca($cobranca);
        $parcela = $titulo->parcelas()->first();
        // Retenção só entra na primeira baixa (depois disso já foi abatida).
        $jaRetido = $parcela->movimentos()->where('tipo', 'baixa')->whereDoesntHave('estorno')->sum('retencao_centavos');
        $this->financeiro->baixar($parcela, [
            'conta_id' => $contaId ?? $this->financeiro->contaPrincipal($cobranca->organizacao_id)->id,
            'data' => $data, 'valor' => $valorCentavos, 'retencao' => $jaRetido > 0 ? 0 : $retencao, 'chave' => $chave,
        ]);
        Evento::registrar('cobranca.paga', $cobranca, ['valor' => $valorCentavos, 'data' => $data]);
    }
}
