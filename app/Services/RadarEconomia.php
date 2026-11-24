<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RadarEconomia
{
    public function comparar(int $empresa, CarbonImmutable $base, CarbonImmutable $atual): array
    {
        $anterior = $this->mes($empresa, $base);
        $recente = $this->mes($empresa, $atual);
        $esperado = $observado = $grupos = 0;
        foreach ($recente['grupos'] as $chave => $grupo) {
            $referencia = $anterior['grupos']->get($chave);
            if (! $referencia || $referencia->quantidade < 3 || $grupo->quantidade < 3) {
                continue;
            }
            $esperado += (int) round(((int) $referencia->taxas / (int) $referencia->bruto) * (int) $grupo->bruto);
            $observado += (int) $grupo->taxas;
            $grupos++;
        }

        return [
            'base' => $anterior, 'atual' => $recente, 'grupos' => $grupos,
            'esperado' => $esperado, 'observado' => $observado,
            'diferenca' => $grupos ? $esperado - $observado : null,
        ];
    }

    private function mes(int $empresa, CarbonImmutable $mes): array
    {
        $periodo = [$mes->startOfMonth()->toDateString(), $mes->endOfMonth()->toDateString()];
        $movimentos = DB::table('movimentos as m')->where('m.organizacao_id', $empresa)
            ->where('m.tipo', 'baixa')->whereBetween('m.data', $periodo)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('movimentos as e')->whereColumn('e.estorno_de_id', 'm.id')
                ->whereColumn('e.organizacao_id', 'm.organizacao_id'));
        $tarifas = (clone $movimentos)->join('parcelas as p', 'p.id', '=', 'm.parcela_id')
            ->join('titulos as t', 't.id', '=', 'p.titulo_id')->join('categorias_financeiras as c', 'c.id', '=', 't.categoria_id')
            ->where('p.organizacao_id', $empresa)->where('t.organizacao_id', $empresa)->where('c.organizacao_id', $empresa)
            ->where('c.chave', 'tarifas')->where('t.tipo', 'pagar');
        $grupos = (clone $movimentos)->join('movimentos as f', 'f.id', '=', 'm.tarifa_movimento_id')
            ->where('f.organizacao_id', $empresa)->where('m.valor_centavos', '>', 0)
            ->selectRaw('m.conta_id, m.tarifa_separada, COUNT(*) AS quantidade, SUM(m.valor_centavos) AS bruto, SUM(f.valor_centavos) AS taxas')
            ->groupBy('m.conta_id', 'm.tarifa_separada')->get()->keyBy(fn ($g) => $g->conta_id.':'.$g->tarifa_separada);
        $evidencias = (clone $tarifas)->orderByDesc('m.valor_centavos')->orderByDesc('m.id')->limit(8)
            ->get(['m.data', 'm.valor_centavos', 't.id', 't.descricao']);

        return ['tarifas' => (int) $tarifas->sum('m.valor_centavos'), 'acrescimos' => (int) $movimentos->sum('m.acrescimo_centavos'),
            'grupos' => $grupos, 'evidencias' => $evidencias];
    }
}
