<?php

namespace App\Services;

use App\Models\Parcela;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Inadimplência e comportamento de pagamento dos clientes (FIN-06). */
final class Recebimentos
{
    public const FAIXAS = ['1-7' => [1, 7], '8-15' => [8, 15], '16-30' => [16, 30], '31-60' => [31, 60], '60+' => [61, PHP_INT_MAX]];

    /** Parcelas a receber vencidas com saldo, com dias de atraso e faixa. */
    public function vencidas(): Collection
    {
        $hoje = CarbonImmutable::today();

        return Parcela::with(['titulo' => fn ($q) => $q->withCount('parcelas'), 'movimentos.estornoDe'])
            ->whereHas('titulo', fn ($q) => $q->where('tipo', 'receber')->whereNull('cancelado_em'))
            ->whereDate('vencimento', '<', $hoje)->get()
            ->filter(fn (Parcela $p) => $p->saldo() > 0)
            ->map(function (Parcela $p) use ($hoje) {
                $dias = (int) CarbonImmutable::instance($p->vencimento)->diffInDays($hoje);

                return ['parcela' => $p, 'dias' => $dias, 'faixa' => self::faixaDe($dias), 'saldo' => $p->saldo(),
                    'parcial' => $p->abatido() > 0, 'cliente' => $p->titulo->contraparte ?: 'Cliente não informado'];
            })->sortByDesc('dias')->values();
    }

    public static function faixaDe(int $dias): string
    {
        foreach (self::FAIXAS as $k => [$de, $ate]) {
            if ($dias >= $de && $dias <= $ate) {
                return $k;
            }
        }

        return '1-7';
    }

    /** Agrupado por cliente: total vencido, maior atraso, quantidade e se atrasa com frequência. */
    public function porCliente(?Collection $vencidas = null): Collection
    {
        $vencidas ??= $this->vencidas();
        $recorrentes = $this->atrasosRecorrentes()->keyBy('chave');

        return $vencidas->groupBy('cliente')->map(fn ($itens, $cliente) => [
            'cliente' => $cliente, 'chave' => Str::slug($cliente), 'total' => (int) $itens->sum('saldo'),
            'maior_atraso' => (int) $itens->max('dias'), 'qtd' => $itens->count(), 'itens' => $itens->values(),
            'recorrente' => $recorrentes->has(Str::slug($cliente)),
        ])->sortByDesc('total')->values();
    }

    /**
     * Clientes cujos três últimos vencimentos (já passados) foram pagos com atraso ou seguem em aberto.
     *
     * @return Collection<int, array{cliente: string, chave: string, atrasos: int}>
     */
    public function atrasosRecorrentes(int $ultimos = 3): Collection
    {
        $hoje = CarbonImmutable::today();

        return Parcela::with('titulo', 'movimentos.estornoDe')
            ->whereHas('titulo', fn ($q) => $q->where('tipo', 'receber')->whereNull('cancelado_em')->whereNotNull('contraparte')->where('contraparte', '!=', ''))
            ->whereDate('vencimento', '<', $hoje)->orderByDesc('vencimento')->get()
            ->groupBy(fn (Parcela $p) => $p->titulo->contraparte)
            ->map(function ($ps, $cliente) use ($ultimos) {
                $ult = $ps->take($ultimos);
                $atrasos = $ult->filter(function (Parcela $p) {
                    if ($p->saldo() > 0) {
                        return true;
                    }
                    $ultimaBaixa = $p->movimentos->where('tipo', 'baixa')->reject->estornado()->max(fn ($m) => $m->data->toDateString());

                    return $ultimaBaixa && $ultimaBaixa > $p->vencimento->toDateString();
                })->count();

                return ['cliente' => $cliente, 'chave' => Str::slug($cliente), 'atrasos' => $atrasos, 'avaliados' => $ult->count()];
            })
            ->filter(fn ($c) => $c['avaliados'] >= $ultimos && $c['atrasos'] >= $ultimos)->values();
    }
}
