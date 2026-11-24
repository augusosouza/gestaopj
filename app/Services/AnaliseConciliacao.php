<?php

namespace App\Services;

use App\Models\ExtratoLinha;
use App\Models\Movimento;
use Carbon\CarbonImmutable;

final class AnaliseConciliacao
{
    public function analisar(ExtratoLinha $linha): array
    {
        $data = CarbonImmutable::instance($linha->data);
        $pares = ExtratoLinha::where('organizacao_id', $linha->organizacao_id)->where('conta_id', $linha->conta_id)
            ->whereKeyNot($linha->id)->where('valor_centavos', $linha->valor_centavos)
            ->whereRaw('LOWER(descricao) = LOWER(?)', [$linha->descricao])
            ->whereBetween('data', [$data->subDays(7)->toDateString(), $data->addDays(7)->toDateString()])
            ->orderByDesc('data')->orderByDesc('id')->limit(5)->get(['id', 'data']);

        $recebimento = $linha->movimento;
        $tarifa = $recebimento?->tarifa;
        $comparacao = null;
        if ($tarifa && $recebimento->valor_centavos > 0) {
            $historico = Movimento::with('tarifa')
                ->where('organizacao_id', $linha->organizacao_id)->where('conta_id', $linha->conta_id)
                ->where('tipo', 'baixa')->where('valor_centavos', '>', 0)
                ->where('tarifa_separada', $recebimento->tarifa_separada)
                ->whereNotNull('tarifa_movimento_id')->whereDoesntHave('estorno')
                ->where('data', '>=', $recebimento->data->copy()->subDays(90)->toDateString())
                ->where('data', '<', $recebimento->data->toDateString())
                ->orderByDesc('data')->orderByDesc('id')->limit(60)->get()
                ->filter(fn (Movimento $m) => $m->tarifa !== null);

            $taxas = $historico->map(fn (Movimento $m) => intdiv($m->tarifa->valor_centavos * 10000, $m->valor_centavos))->sort()->values();
            $amostra = $taxas->count();
            $mediana = $amostra >= 3 ? intdiv($taxas[intdiv($amostra - 1, 2)] + $taxas[intdiv($amostra, 2)], 2) : null;
            $atual = intdiv($tarifa->valor_centavos * 10000, $recebimento->valor_centavos);
            $comparacao = ['atual' => $atual, 'mediana' => $mediana, 'amostra' => $amostra];
        }

        return ['pares' => $pares, 'tarifa' => $comparacao];
    }
}
