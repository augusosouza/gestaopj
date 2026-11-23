<?php

namespace App\Services;

use App\Enums\EtapaContabil;
use App\Enums\Papel;
use App\Enums\StatusSolicitacao;
use App\Models\CompetenciaContabil;
use App\Models\DocumentoContabil;
use App\Models\Evento;
use App\Models\Solicitacao;
use App\Models\VersaoFechamento;
use App\Support\Contexto;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Fluxo entre empresa e escritório contábil em uma competência:
 * coleta → conferência → revisão → fechada (com versão guardada) → reabertura justificada.
 */
final class AreaContabil
{
    public function __construct(private Fechamento $fechamento, private Contexto $ctx) {}

    /** Busca a competência da empresa do contexto; cria ao primeiro uso. */
    public function competencia(CarbonImmutable $mes, bool $criar = true): CompetenciaContabil
    {
        $mes = $mes->startOfMonth();
        $cc = CompetenciaContabil::whereDate('competencia', $mes)->first();
        if ($cc || ! $criar) {
            return $cc ?? new CompetenciaContabil(['competencia' => $mes, 'etapa' => EtapaContabil::Coleta]);
        }

        return CompetenciaContabil::create([
            'organizacao_id' => $this->ctx->organizacaoId(), 'competencia' => $mes, 'etapa' => EtapaContabil::Coleta,
        ]);
    }

    // ---------------- Lado da empresa ----------------

    public function enviarDocumento(CompetenciaContabil $cc, UploadedFile $arquivo, string $categoria, ?string $descricao, ?Solicitacao $sol = null): DocumentoContabil
    {
        $this->exigirAberta($cc, 'arquivo');
        $conteudo = $arquivo->get();
        $ext = Str::lower($arquivo->getClientOriginalExtension() ?: 'bin');
        $caminho = sprintf('contabil/%d/%s/%s.%s', $cc->organizacao_id, $cc->mes(), Str::uuid(), $ext);
        Storage::disk('local')->put($caminho, $conteudo);

        $doc = DocumentoContabil::create([
            'organizacao_id' => $cc->organizacao_id, 'competencia_contabil_id' => $cc->id, 'solicitacao_id' => $sol?->id,
            'categoria' => $categoria, 'descricao' => $descricao,
            'nome_original' => Str::limit(basename($arquivo->getClientOriginalName()), 180, ''),
            'arquivo_path' => $caminho, 'tamanho' => strlen($conteudo), 'sha256' => hash('sha256', $conteudo),
            'enviado_por' => auth()->id(),
        ]);
        Evento::registrar('contabil.documento_enviado', $cc, ['arquivo' => $doc->nome_original, 'categoria' => $doc->categoria->rotulo()]);

        return $doc;
    }

    /** Empresa avisa que terminou de enviar o mês. */
    public function marcarEnviada(CompetenciaContabil $cc): void
    {
        $this->exigirAberta($cc, 'competencia');
        $cc->forceFill([
            'enviada_em' => now(), 'enviada_por' => auth()->id(),
            'etapa' => $cc->etapa === EtapaContabil::Coleta ? EtapaContabil::Conferencia : $cc->etapa,
        ])->save();
        Evento::registrar('contabil.enviada', $cc);
    }

    public function responder(Solicitacao $sol, ?string $resposta, ?UploadedFile $arquivo): void
    {
        $cc = $sol->competenciaContabil;
        $this->exigirAberta($cc, 'resposta');
        if ($sol->status !== StatusSolicitacao::Aberta) {
            throw ValidationException::withMessages(['resposta' => 'Esta solicitação não está aguardando resposta.']);
        }
        if (blank($resposta) && ! $arquivo) {
            throw ValidationException::withMessages(['resposta' => 'Escreva uma resposta ou anexe um arquivo.']);
        }
        DB::transaction(function () use ($sol, $cc, $resposta, $arquivo) {
            if ($arquivo) {
                $this->enviarDocumento($cc, $arquivo, 'outro', 'Resposta: '.$sol->titulo, $sol);
            }
            $sol->update([
                'status' => StatusSolicitacao::Respondida, 'resposta' => $resposta,
                'respondida_por' => auth()->id(), 'respondida_em' => now(),
            ]);
            Evento::registrar('contabil.solicitacao_respondida', $sol);
        });
    }

    // ---------------- Lado do contador ----------------

    public function solicitar(CompetenciaContabil $cc, string $titulo, ?string $descricao, ?string $prazo): Solicitacao
    {
        $this->exigirAberta($cc, 'titulo');
        $sol = Solicitacao::create([
            'organizacao_id' => $cc->organizacao_id, 'competencia_contabil_id' => $cc->id,
            'titulo' => $titulo, 'descricao' => $descricao, 'prazo' => $prazo,
            'status' => StatusSolicitacao::Aberta, 'criada_por' => auth()->id(),
        ]);
        // Pedir algo devolve a competência à coleta, se ainda estava em conferência.
        if ($cc->etapa === EtapaContabil::Conferencia) {
            $cc->update(['etapa' => EtapaContabil::Coleta]);
        }
        Evento::registrar('contabil.solicitacao_criada', $sol, ['titulo' => $titulo]);

        return $sol;
    }

    public function aceitar(Solicitacao $sol): void
    {
        $this->exigirAberta($sol->competenciaContabil, 'solicitacao');
        if ($sol->status !== StatusSolicitacao::Respondida) {
            throw ValidationException::withMessages(['solicitacao' => 'Só respostas recebidas podem ser aceitas.']);
        }
        $sol->update(['status' => StatusSolicitacao::Aceita, 'resolvida_por' => auth()->id(), 'resolvida_em' => now()]);
        Evento::registrar('contabil.solicitacao_aceita', $sol);
    }

    /** Resposta insuficiente: volta para a empresa com o motivo. */
    public function devolver(Solicitacao $sol, string $motivo): void
    {
        $this->exigirAberta($sol->competenciaContabil, 'solicitacao');
        if ($sol->status !== StatusSolicitacao::Respondida) {
            throw ValidationException::withMessages(['solicitacao' => 'Só respostas recebidas podem ser devolvidas.']);
        }
        $sol->update([
            'status' => StatusSolicitacao::Aberta,
            'descricao' => trim(($sol->descricao ? $sol->descricao."\n\n" : '').'Devolvida pelo contador: '.$motivo),
        ]);
        Evento::registrar('contabil.solicitacao_devolvida', $sol, ['motivo' => $motivo]);
    }

    public function cancelar(Solicitacao $sol): void
    {
        $this->exigirAberta($sol->competenciaContabil, 'solicitacao');
        $sol->update(['status' => StatusSolicitacao::Cancelada, 'resolvida_por' => auth()->id(), 'resolvida_em' => now()]);
        Evento::registrar('contabil.solicitacao_cancelada', $sol);
    }

    /** Analista e revisor movem entre coleta, conferência e revisão. Fechar é outra ação. */
    public function mudarEtapa(CompetenciaContabil $cc, EtapaContabil $etapa): void
    {
        $this->exigirAberta($cc, 'etapa');
        if ($etapa === EtapaContabil::Fechada) {
            throw ValidationException::withMessages(['etapa' => 'Use “Fechar competência”.']);
        }
        $de = $cc->etapa;
        $cc->update(['etapa' => $etapa]);
        Evento::registrar('contabil.etapa', $cc, ['de' => $de->rotulo(), 'para' => $etapa->rotulo()]);
    }

    /**
     * Fecha e grava um retrato imutável: planilhas, XMLs, documentos, solicitações e manifesto com hashes.
     * Exige revisão e nenhuma solicitação pendente.
     */
    public function fechar(CompetenciaContabil $cc, ?string $observacao): VersaoFechamento
    {
        $this->exigirRevisor();
        if ($cc->etapa !== EtapaContabil::Revisao) {
            throw ValidationException::withMessages(['fechar' => 'A competência precisa estar em revisão para ser fechada.']);
        }
        $cc->load('solicitacoes', 'documentos');
        if (($n = $cc->pendentes()) > 0) {
            throw ValidationException::withMessages(['fechar' => "Há {$n} solicitação(ões) pendente(s). Resolva ou cancele antes de fechar."]);
        }

        return DB::transaction(function () use ($cc, $observacao) {
            // Trava a linha: dois revisores fechando ao mesmo tempo não geram a mesma versão.
            $cc = CompetenciaContabil::lockForUpdate()->find($cc->id)->load('solicitacoes', 'documentos');
            if ($cc->fechada()) {
                throw ValidationException::withMessages(['fechar' => 'Esta competência acabou de ser fechada por outra pessoa. Recarregue a página.']);
            }
            $versao = $cc->versao_atual + 1;
            $resumo = $this->fechamento->resumo($cc->competencia->toImmutable());
            $manifesto = [];
            $tmp = $this->fechamento->exportar($cc->competencia->toImmutable(), function (ZipArchive $zip) use ($cc, $versao, $observacao, &$manifesto) {
                foreach ($cc->documentos as $d) {
                    if (Storage::disk('local')->exists($d->arquivo_path)) {
                        $nome = sprintf('documentos/%s/%d-%s', $d->categoria->value, $d->id, $d->nome_original);
                        $zip->addFromString($nome, Storage::disk('local')->get($d->arquivo_path));
                        $manifesto[] = "{$d->sha256}  {$nome}";
                    }
                }
                $linhas = ["\u{FEFF}titulo;status;criada_em;resposta"];
                foreach ($cc->solicitacoes as $s) {
                    $linhas[] = implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
                        [$s->titulo, $s->status->rotulo(), $s->created_at->format('d/m/Y'), $s->resposta]));
                }
                $zip->addFromString('solicitacoes.csv', implode("\n", $linhas));
                $zip->addFromString('LEIA-ME.txt', implode("\n", [
                    config('app.name').' — fechamento contábil',
                    'Empresa: '.$this->ctx->empresa()?->razao_social,
                    'Competência: '.$cc->competencia->format('m/Y'),
                    "Versão: {$versao}",
                    'Fechado por: '.auth()->user()?->name.' em '.now()->format('d/m/Y H:i'),
                    $observacao ? "Observação: {$observacao}" : '',
                    '', 'SHA-256 dos documentos enviados:', ...$manifesto,
                ]));
            });

            $conteudo = file_get_contents($tmp);
            @unlink($tmp);
            $caminho = sprintf('contabil/%d/%s/fechamento-v%d.zip', $cc->organizacao_id, $cc->mes(), $versao);
            Storage::disk('local')->put($caminho, $conteudo);

            $v = VersaoFechamento::create([
                'organizacao_id' => $cc->organizacao_id, 'competencia_contabil_id' => $cc->id, 'versao' => $versao,
                'arquivo_path' => $caminho, 'sha256' => hash('sha256', $conteudo), 'observacao' => $observacao,
                'fechado_por' => auth()->id(),
                'resumo' => [
                    'faturado' => $resumo['faturado'], 'recebido' => $resumo['recebido'],
                    'custo_prestadores' => $resumo['custo_total'], 'pago_prestadores' => $resumo['pago_prestadores'],
                    'documentos' => $cc->documentos->count(), 'notas' => $resumo['faturas']->whereNotNull('nota_fiscal_id')->count()
                        + $resumo['cobrancas']->whereNotNull('nota_fiscal_id')->count(),
                ],
            ]);
            $cc->update(['etapa' => EtapaContabil::Fechada, 'versao_atual' => $versao]);
            Evento::registrar('contabil.fechada', $cc, ['versao' => $versao]);

            return $v;
        });
    }

    /** Reabrir exige revisor e justificativa; as versões anteriores continuam guardadas. */
    public function reabrir(CompetenciaContabil $cc, string $justificativa): void
    {
        $this->exigirRevisor();
        if (! $cc->fechada()) {
            throw ValidationException::withMessages(['justificativa' => 'A competência não está fechada.']);
        }
        $cc->update(['etapa' => EtapaContabil::Revisao]);
        Evento::registrar('contabil.reaberta', $cc, ['justificativa' => $justificativa, 'versao' => $cc->versao_atual]);
    }

    private function exigirAberta(CompetenciaContabil $cc, string $campo): void
    {
        if ($cc->fechada()) {
            throw ValidationException::withMessages([$campo => 'Competência fechada. Peça ao escritório para reabrir antes de alterar.']);
        }
    }

    private function exigirRevisor(): void
    {
        if (! $this->ctx->tem(Papel::Revisor)) {
            throw ValidationException::withMessages(['fechar' => 'Só o revisor do escritório pode fechar ou reabrir a competência.']);
        }
    }
}
