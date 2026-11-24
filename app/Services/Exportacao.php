<?php

namespace App\Services;

use App\Http\Controllers\FinanceiroController;
use App\Models\Compra;
use App\Models\Evento;
use App\Models\Organizacao;
use App\Models\Parcela;
use App\Models\Titulo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Exportação de relatórios financeiros (XLSX ou CSV) com o Spatie Simple Excel.
 *
 * Regra: o arquivo sai dos MESMOS serviços e filtros das telas — os números do
 * Excel batem com os da tela. Valores em reais (2 casas); no CSV, vírgula decimal
 * e ponto e vírgula como separador (padrão do Excel em português).
 */
final class Exportacao
{
    public const RELATORIOS = ['lancamentos' => 'Lançamentos', 'compras' => 'Compras', 'inadimplencia' => 'Inadimplência', 'caixa' => 'Meu Caixa'];

    public function __construct(private PosicaoFinanceira $posicao, private Recebimentos $receb) {}

    /** @return array{0: string, 1: string} caminho temporário e nome do download */
    public function gerar(string $relatorio, string $formato, Request $request): array
    {
        $formato = $formato === 'csv' ? 'csv' : 'xlsx';
        [$cabecalho, $linhas] = match ($relatorio) {
            'lancamentos' => $this->lancamentos($request),
            'compras' => $this->compras(),
            'inadimplencia' => $this->inadimplencia(),
            'caixa' => $this->caixa($request),
        };
        $dir = self::pasta();
        is_dir($dir) || mkdir($dir, 0750, true);
        $caminho = $dir.'/'.Str::uuid().'.'.$formato;
        $w = $formato === 'csv' ? SimpleExcelWriter::create($caminho, 'csv', delimiter: ';', shouldAddBom: true) : SimpleExcelWriter::create($caminho, 'xlsx');
        $w->addHeader($cabecalho);
        $n = 0;
        foreach ($linhas as $l) {
            $w->addRow(array_map(fn ($v) => $this->celula($v, $formato), $l));
            $n++;
        }
        $w->close();

        $org = ctx()->empresa() ?? Organizacao::findOrFail(ctx()->organizacaoId());
        Evento::registrar('financeiro.exportado', $org, ['relatorio' => self::RELATORIOS[$relatorio], 'formato' => $formato, 'linhas' => $n]);

        return [$caminho, Str::slug(self::RELATORIOS[$relatorio]).'-'.now()->format('Y-m-d').'.'.$formato];
    }

    /**
     * Exporta um relatório da central (mesmas colunas e linhas da tela). Dinheiro vai como número no XLSX.
     *
     * @return array{0: string, 1: string}
     */
    public function gerarRelatorio(array $r, string $slug, string $formato): array
    {
        $formato = $formato === 'csv' ? 'csv' : 'xlsx';
        $dir = self::pasta();
        is_dir($dir) || mkdir($dir, 0750, true);
        $caminho = $dir.'/'.Str::uuid().'.'.$formato;
        $w = $formato === 'csv' ? SimpleExcelWriter::create($caminho, 'csv', delimiter: ';', shouldAddBom: true) : SimpleExcelWriter::create($caminho, 'xlsx');
        $w->addHeader(array_column($r['colunas'], 0));
        $conv = fn (array $l) => array_map(fn ($v, $c) => $c[1] === 'dinheiro' && is_int($v) ? $this->celula(['R', $v], $formato) : $this->celula($v, $formato), $l, $r['colunas']);
        foreach ($r['linhas'] as $l) {
            $w->addRow($conv(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $l)));
        }
        if ($r['total']) {
            $w->addRow($conv($r['total']));
        }
        $w->close();
        $org = ctx()->empresa() ?? Organizacao::findOrFail(ctx()->organizacaoId());
        Evento::registrar('financeiro.exportado', $org, ['relatorio' => $r['titulo'], 'formato' => $formato, 'linhas' => count($r['linhas'])]);

        return [$caminho, $slug.'-'.now()->format('Y-m-d').'.'.$formato];
    }

    /** Pasta privada dos temporários (configurável para isolar execuções paralelas de teste). */
    public static function pasta(): string
    {
        return config('gestao.pasta_exportacoes') ?: storage_path('app/private/exportacoes');
    }

    /** Dinheiro vem como ['R', centavos]; no XLSX vira número, no CSV texto com vírgula. */
    private function celula(mixed $v, string $formato): mixed
    {
        if (is_array($v) && ($v[0] ?? null) === 'R') {
            return $formato === 'xlsx' ? round($v[1] / 100, 2) : number_format($v[1] / 100, 2, ',', '');
        }

        return $formato === 'csv' && is_string($v) && preg_match('/^[\s\p{C}]*[=+@-]/u', $v) === 1 ? "'".$v : $v;
    }

    private function r(?int $c): array
    {
        return ['R', (int) $c];
    }

    private function lancamentos(Request $request): array
    {
        $situacoes = ['aberto' => 'Em aberto', 'parcial' => 'Parcial', 'liquidado' => 'Liquidado', 'cancelado' => 'Cancelado'];
        $linhas = FinanceiroController::filtrar($request, 20000)->map(fn (Titulo $t) => [
            $t->competencia->format('m/Y'), $t->receber() ? 'A receber' : 'A pagar', $t->descricao, $t->contraparte ?? '', $t->categoria?->nome ?? '',
            $t->documento ?? '', $this->r($t->valor_total_centavos), $this->r($t->pagoEmDinheiro()), $this->r($t->descontos()), $this->r($t->retencoes()),
            $this->r($t->acrescimos()), $this->r($t->cancelado() ? 0 : $t->saldo()), $t->vencido() ? 'Vencido' : $situacoes[$t->situacao()],
            $t->parcelas->first(fn ($p) => $p->saldo() > 0)?->vencimento->format('d/m/Y') ?? '',
        ]);

        return [['Competência', 'Tipo', 'Descrição', 'Contraparte', 'Categoria', 'Documento', 'Valor original', 'Pago/recebido em dinheiro',
            'Descontos', 'Retenções', 'Acréscimos', 'Em aberto', 'Situação', 'Próximo vencimento'], $linhas];
    }

    private function compras(): array
    {
        $linhas = Compra::with('titulo.parcelas.movimentos.estornoDe', 'categoria', 'cartao')->orderBy('data_compra')->orderBy('id')->get()
            ->map(fn (Compra $c) => [
                $c->data_compra->format('d/m/Y'), $c->descricao, $c->fornecedor ?? '', $c->fornecedor_documento ?? '', $c->categoria?->nome ?? '',
                Compra::MODALIDADES[$c->modalidade], $c->cartao?->rotulo() ?? '', $c->titulo?->parcelas->count() ?? 0,
                $this->r($c->valor_bruto_centavos), $this->r($c->desconto_centavos), $this->r($c->juros_centavos), $this->r($c->total_centavos),
                $this->r($c->titulo?->liquidado() ?? 0), $this->r($c->cancelada() ? 0 : ($c->titulo?->saldo() ?? 0)),
                trim(($c->nota_tipo ?? '').' '.($c->nota_numero ?? '')), $c->garantia_ate?->format('d/m/Y') ?? '',
                $c->cancelada() ? 'Cancelada' : (($c->titulo?->saldo() ?? 0) === 0 ? 'Quitada' : 'Em pagamento'),
            ]);

        return [['Data', 'Descrição', 'Fornecedor', 'CNPJ/CPF', 'Categoria', 'Forma', 'Cartão', 'Parcelas', 'Valor bruto', 'Desconto', 'Juros',
            'Total', 'Pago', 'Restante', 'Documento', 'Garantia até', 'Situação'], $linhas];
    }

    private function inadimplencia(): array
    {
        $linhas = $this->receb->vencidas()->map(function ($v) {
            /** @var Parcela $p */
            $p = $v['parcela'];

            return [$v['cliente'], $p->titulo->descricao, $p->numero.'/'.$p->titulo->parcelas_count, $p->vencimento->format('d/m/Y'), $v['dias'],
                $v['faixa'] === '60+' ? 'Mais de 60' : $v['faixa'].' dias', $this->r($p->valor_centavos), $this->r($v['saldo']), $v['parcial'] ? 'Sim' : 'Não'];
        });

        return [['Cliente', 'Lançamento', 'Parcela', 'Vencimento', 'Dias de atraso', 'Faixa', 'Valor original', 'Em aberto', 'Pagamento parcial'], $linhas];
    }

    private function caixa(Request $request): array
    {
        $dias = in_array($request->integer('dias'), [7, 15, 30, 60, 90], true) ? $request->integer('dias') : 30;
        $contas = array_values(array_filter(array_map('intval', (array) $request->query('contas', []))));
        $p = $this->posicao->projetar($dias, $contas ?: null, $request->boolean('vencidos'));
        $saldo = $p['saldo_atual'];
        $linhas = [['Hoje', '', 'Saldo registrado', '', '', '', '', $this->r($saldo), '']];
        foreach ($p['eventos'] as $e) {
            $saldo += $e['tipo'] === 'receber' ? $e['valor'] : -$e['valor'];
            $linhas[] = [$e['data']->format('d/m/Y'), $e['vencimento']->format('d/m/Y'), $e['descricao'], $e['contraparte'] ?? '',
                $e['tipo'] === 'receber' ? 'Entrada' : 'Saída', $e['tipo'] === 'receber' ? $this->r($e['valor']) : '', $e['tipo'] === 'pagar' ? $this->r($e['valor']) : '',
                $this->r($saldo), $e['atrasado'] ? 'Sim' : 'Não'];
        }

        return [['Data no caixa', 'Vencimento', 'Descrição', 'Contraparte', 'Tipo', 'Entrada', 'Saída', 'Saldo após', 'Vencido'], $linhas];
    }
}
