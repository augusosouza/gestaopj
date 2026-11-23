<?php

namespace App\Services;

use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\ExtratoLinha;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Importa extrato bancário em OFX (padrão dos bancos brasileiros) ou CSV.
 * Reimportar o mesmo arquivo não duplica: cada linha tem um identificador
 * (FITID do OFX, ou hash de data+valor+descrição+ordem no arquivo).
 */
final class ImportadorExtrato
{
    /** @return array{novas: int, repetidas: int} */
    public function importar(ContaFinanceira $conta, string $conteudo, string $nomeArquivo): array
    {
        $conteudo = mb_check_encoding($conteudo, 'UTF-8') ? $conteudo : mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        $linhas = stripos($conteudo, '<STMTTRN>') !== false ? $this->ofx($conteudo) : $this->csv($conteudo);
        if ($linhas === []) {
            throw ValidationException::withMessages(['arquivo' => 'Nenhum lançamento encontrado. Use OFX ou CSV com colunas data;descrição;valor.']);
        }

        $novas = $repetidas = 0;
        $ocorrencias = [];
        foreach ($linhas as $l) {
            if ($l['id'] === null) {
                $base = $l['data'].'|'.$l['valor'].'|'.mb_strtolower($l['descricao']);
                $ocorrencias[$base] = ($ocorrencias[$base] ?? 0) + 1;
                $l['id'] = 'h:'.substr(hash('sha256', $base.'|'.$ocorrencias[$base]), 0, 40);
            }
            $linha = ExtratoLinha::firstOrCreate(
                ['conta_id' => $conta->id, 'identificador' => mb_substr($l['id'], 0, 80)],
                ['organizacao_id' => $conta->organizacao_id, 'data' => $l['data'], 'valor_centavos' => $l['valor'],
                    'descricao' => mb_substr($l['descricao'] ?: '(sem descrição)', 0, 255), 'arquivo' => mb_substr($nomeArquivo, 0, 255)],
            );
            $linha->wasRecentlyCreated ? $novas++ : $repetidas++;
        }
        Evento::registrar('financeiro.extrato_importado', $conta, ['arquivo' => $nomeArquivo, 'novas' => $novas, 'repetidas' => $repetidas]);

        return compact('novas', 'repetidas');
    }

    /** OFX 1.x (SGML, tags sem fechamento) e 2.x (XML). */
    private function ofx(string $s): array
    {
        preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>)/si', $s, $blocos);
        $tag = fn (string $b, string $t) => preg_match('/<'.$t.'>\s*([^<\r\n]*)/i', $b, $m) ? trim($m[1]) : null;
        $out = [];
        foreach ($blocos[1] as $b) {
            $data = $tag($b, 'DTPOSTED');
            $valor = $tag($b, 'TRNAMT');
            if (! $data || $valor === null || ! preg_match('/^(\d{4})(\d{2})(\d{2})/', $data, $d)) {
                continue;
            }
            $out[] = [
                'data' => "{$d[1]}-{$d[2]}-{$d[3]}",
                'valor' => $this->centavos(str_replace(',', '.', $valor)),
                'descricao' => trim(($tag($b, 'MEMO') ?? '').' '.($tag($b, 'NAME') ?? '')) ?: ($tag($b, 'TRNTYPE') ?? ''),
                'id' => ($fit = $tag($b, 'FITID')) ? 'f:'.$fit : null,
            ];
        }

        return $out;
    }

    /** CSV com data, descrição e valor (aceita ; ou , e cabeçalho opcional; valor em formato brasileiro ou com ponto). */
    private function csv(string $s): array
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        $linhas = preg_split('/\r\n|\n|\r/', trim($s));
        $sep = substr_count($linhas[0] ?? '', ';') >= 2 ? ';' : ',';
        $out = [];
        foreach ($linhas as $i => $raw) {
            $c = str_getcsv($raw, $sep, '"', '');
            if (count($c) < 3) {
                continue;
            }
            [$data, $desc, $valor] = [trim($c[0]), trim($c[1]), trim($c[2])];
            $dt = $this->data($data);
            if (! $dt) {
                if ($i === 0) {
                    continue; // cabeçalho
                }
                throw ValidationException::withMessages(['arquivo' => 'Linha '.($i + 1).': data inválida “'.$data.'”. Use DD/MM/AAAA.']);
            }
            $out[] = ['data' => $dt, 'valor' => $this->centavos($valor), 'descricao' => $desc, 'id' => null];
        }

        return $out;
    }

    private function data(string $v): ?string
    {
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $f) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$f, $v);
            } catch (\Throwable) {
                continue;
            }
            if ($d && $d->format($f) === $v) {
                return $d->toDateString();
            }
        }

        return null;
    }

    private function centavos(string $v): int
    {
        $v = trim(str_replace(['R$', ' '], '', $v));
        $neg = str_starts_with($v, '-') || (str_starts_with($v, '(') && str_ends_with($v, ')'));
        $v = trim($v, '-()+');
        try {
            $c = Dinheiro::paraCentavos($v);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['arquivo' => "Valor inválido no extrato: {$v}"]);
        }

        return $neg ? -$c : $c;
    }
}
