<?php

namespace App\Services;

use App\Enums\StatusCobranca;
use App\Enums\StatusFatura;
use App\Models\Cobranca;
use App\Models\Fatura;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Responde às quatro perguntas do fechamento mensal:
 * quanto faturei, quanto recebi, quanto devo aos prestadores, quais notas estão pendentes.
 */
final class Fechamento
{
    public function resumo(CarbonImmutable $competencia): array
    {
        $faturas = Fatura::with('contrato.cliente', 'notaFiscal')
            ->whereDate('competencia', $competencia->toDateString())
            ->where('status', '!=', StatusFatura::Cancelada->value)
            ->get();
        $cobrancas = Cobranca::with('prestador', 'contrato', 'notaFiscal')
            ->whereDate('competencia', $competencia->toDateString())
            ->get();

        $aPagar = $cobrancas->where('status', '!=', StatusCobranca::Paga);

        return [
            'faturado' => (int) $faturas->sum('valor_centavos'),
            'recebido' => (int) $faturas->sum('valor_recebido_centavos'),
            'a_receber' => (int) $faturas->where('status', '!=', StatusFatura::Recebida)->sum('valor_centavos'),
            'devido_prestadores' => (int) $aPagar->sum('valor_centavos'),
            'devido_aprovado' => (int) $aPagar->where('status', StatusCobranca::Aprovada)->sum('valor_centavos'),
            'pago_prestadores' => (int) $cobrancas->sum('valor_pago_centavos'),
            'custo_total' => (int) $cobrancas->sum('valor_centavos'),
            'pendencias' => $this->pendencias($faturas, $cobrancas),
            'contratos' => $this->porContrato($faturas, $cobrancas),
            'faturas' => $faturas,
            'cobrancas' => $cobrancas,
        ];
    }

    private function pendencias(Collection $faturas, Collection $cobrancas): Collection
    {
        $p = collect();
        foreach ($faturas->where('status', StatusFatura::Aberta) as $f) {
            $p->push(['tipo' => 'Nota a emitir', 'quem' => $f->contrato->cliente->razao_social, 'ref' => $f->contrato->numero, 'valor' => $f->valor_centavos, 'url' => route('faturas.show', $f)]);
        }
        $rotulos = [
            StatusCobranca::AguardandoNota->value => 'Nota do prestador não enviada',
            StatusCobranca::EmConferencia->value => 'Nota aguardando conferência',
            StatusCobranca::Rejeitada->value => 'Nota rejeitada, aguardando reenvio',
        ];
        foreach ($cobrancas as $c) {
            $posAprovacao = in_array($c->status, [StatusCobranca::Aprovada, StatusCobranca::Paga], true);
            if ($posAprovacao && $c->naoVerificada()) {
                $p->push(['tipo' => 'XML da nota pendente', 'quem' => $c->prestador->nome, 'ref' => $c->contrato->numero, 'valor' => $c->valor_centavos, 'url' => route('cobrancas.show', $c)]);
            }
            if ($posAprovacao && $c->invalidaFiscalmente()) {
                $p->push(['tipo' => 'Nota fiscalmente inconsistente', 'quem' => $c->prestador->nome, 'ref' => $c->contrato->numero, 'valor' => $c->valor_centavos, 'url' => route('cobrancas.show', $c)]);
            }
            if (isset($rotulos[$c->status->value])) {
                $p->push(['tipo' => $rotulos[$c->status->value], 'quem' => $c->prestador->nome, 'ref' => $c->contrato->numero, 'valor' => $c->valor_centavos, 'url' => route('cobrancas.show', $c)]);
            }
        }

        return $p;
    }

    private function porContrato(Collection $faturas, Collection $cobrancas): Collection
    {
        return $faturas->map(function (Fatura $f) use ($cobrancas) {
            $custo = (int) $cobrancas->where('contrato_id', $f->contrato_id)->sum('valor_centavos');

            return [
                'contrato' => $f->contrato,
                'faturado' => $f->valor_centavos,
                'custo' => $custo,
                'margem' => $f->valor_centavos - $custo,
                'margem_pct' => $f->valor_centavos > 0 ? round(($f->valor_centavos - $custo) * 100 / $f->valor_centavos, 1) : null,
            ];
        })->values();
    }

    /** ZIP para a contabilidade: CSVs do mês + XMLs emitidos e recebidos. */
    public function exportar(CarbonImmutable $competencia): string
    {
        $r = $this->resumo($competencia);
        $arquivo = tempnam(sys_get_temp_dir(), 'fech').'.zip';
        $zip = new ZipArchive;
        $zip->open($arquivo, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $valor = fn (?int $c) => $c === null ? '' : number_format($c / 100, 2, ',', '');

        $linhas = [['contrato', 'cliente', 'documento_cliente', 'valor', 'vencimento', 'status', 'chave_nfse', 'valor_recebido', 'recebida_em']];
        foreach ($r['faturas'] as $f) {
            $linhas[] = [$f->contrato->numero, $f->contrato->cliente->razao_social, $f->contrato->cliente->documento, $valor($f->valor_centavos), $f->vencimento->format('d/m/Y'), $f->status->rotulo(), $f->notaFiscal?->chave_acesso, $valor($f->valor_recebido_centavos), $f->recebida_em?->format('d/m/Y')];
            if ($f->notaFiscal?->xml_path && Storage::disk('local')->exists($f->notaFiscal->xml_path)) {
                $zip->addFromString('xml/emitidas/'.$f->notaFiscal->chave_acesso.'.xml', Storage::disk('local')->get($f->notaFiscal->xml_path));
            }
        }
        $zip->addFromString('faturas_receber.csv', $this->csv($linhas));

        $linhas = [['contrato', 'prestador', 'documento_prestador', 'valor', 'status', 'chave_nfse', 'origem_nota', 'valor_pago', 'paga_em']];
        foreach ($r['cobrancas'] as $c) {
            $linhas[] = [$c->contrato->numero, $c->prestador->nome, $c->prestador->documento, $valor($c->valor_centavos), $c->status->rotulo(), $c->notaFiscal?->chave_acesso, $c->notaFiscal?->origem, $valor($c->valor_pago_centavos), $c->paga_em?->format('d/m/Y')];
            if ($c->notaFiscal?->xml_path && Storage::disk('local')->exists($c->notaFiscal->xml_path)) {
                $zip->addFromString('xml/recebidas/'.$c->notaFiscal->chave_acesso.'.xml', Storage::disk('local')->get($c->notaFiscal->xml_path));
            }
        }
        $zip->addFromString('cobrancas_pagar.csv', $this->csv($linhas));

        $zip->addFromString('resumo.csv', $this->csv([
            ['competencia', 'faturado', 'recebido', 'custo_prestadores', 'pago_prestadores', 'devido_prestadores', 'pendencias'],
            [$competencia->format('m/Y'), $valor($r['faturado']), $valor($r['recebido']), $valor($r['custo_total']), $valor($r['pago_prestadores']), $valor($r['devido_prestadores']), $r['pendencias']->count()],
        ]));
        $zip->close();

        return $arquivo;
    }

    private function csv(array $linhas): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF"); // BOM para o Excel abrir acentos corretamente
        foreach ($linhas as $l) {
            fputcsv($h, $l, ';', '"', '');
        }
        rewind($h);

        return stream_get_contents($h);
    }
}
