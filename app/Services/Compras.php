<?php

namespace App\Services;

use App\Models\Cartao;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\FaturaCartao;
use App\Models\Parcela;
use App\Models\Titulo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compras e cartões (FIN-04/05) sobre o mesmo núcleo de títulos, parcelas e baixas.
 *
 *  - Compra = origem de UM título a pagar. Entrada (se houver) é a parcela 1.
 *  - Compra no cartão: cada parcela vai para a fatura do mês certo e vence com ela.
 *    Não existe "despesa da fatura": pagar a fatura baixa as parcelas que a compõem.
 *  - Encargos da fatura (juros, IOF, multa) viram um título próprio, já pago.
 */
final class Compras
{
    public function __construct(private Financeiro $fin) {}

    /**
     * @param  array{descricao: string, fornecedor: ?string, fornecedor_documento: ?string, data_compra: string, valor_bruto: int,
     *               desconto: int, juros: int, entrada: int, categoria_id: ?int, modalidade: string, conta_id: ?int, cartao_id: ?int,
     *               parcelas: int, primeiro_vencimento: ?string, quitar_primeira: bool, nota_tipo: ?string, nota_numero: ?string,
     *               nota_chave: ?string, garantia_ate: ?string, observacao: ?string, itens: list<array{descricao: string, quantidade: string, unitario: int}>}  $d
     */
    public function registrar(int $orgId, array $d): Compra
    {
        $itens = array_values(array_filter($d['itens'] ?? [], fn ($i) => trim((string) ($i['descricao'] ?? '')) !== ''));
        $itensCalc = array_map(function ($i) {
            $qtd = (float) str_replace(',', '.', (string) ($i['quantidade'] ?: '1'));

            return $i + ['qtd' => $qtd, 'total' => (int) round($qtd * $i['unitario'])];
        }, $itens);
        // Com itens, o valor bruto é a soma deles (não há dois números para a mesma coisa).
        $bruto = $itensCalc ? array_sum(array_column($itensCalc, 'total')) : $d['valor_bruto'];
        $total = $bruto - $d['desconto'] + $d['juros'];
        $cartao = $d['modalidade'] === 'cartao' ? Cartao::where('ativo', true)->find($d['cartao_id']) : null;
        $conta = $d['conta_id'] ? ContaFinanceira::where('ativa', true)->find($d['conta_id']) : null;
        $n = (int) $d['parcelas'];

        $erro = match (true) {
            $bruto <= 0 => 'Informe o valor da compra ou os itens.',
            $d['desconto'] < 0 || $d['juros'] < 0 || $d['entrada'] < 0 => 'Valores não podem ser negativos.',
            $total <= 0 => 'O desconto não pode ser maior que o valor da compra.',
            $n < 1 || $n > 120 => 'Use de 1 a 120 parcelas.',
            $d['modalidade'] === 'cartao' && ! $cartao => 'Escolha um cartão ativo.',
            $d['modalidade'] === 'cartao' && $d['entrada'] > 0 => 'Compra no cartão não tem entrada: ela entra inteira nas faturas.',
            $d['entrada'] >= $total => 'A entrada precisa ser menor que o total. Para compra à vista, use 1 parcela sem entrada.',
            $d['quitar_primeira'] && $d['modalidade'] === 'cartao' => 'Compra no cartão é paga pela fatura.',
            $d['quitar_primeira'] && ! $conta => 'Escolha a conta de onde saiu o pagamento.',
            $d['quitar_primeira'] && CarbonImmutable::parse($d['data_compra'])->gt(now()) => 'Pagamento já feito precisa de data de compra até hoje.',
            $d['modalidade'] !== 'cartao' && ! $d['primeiro_vencimento'] => 'Informe o primeiro vencimento.',
            default => null,
        };
        if ($erro) {
            throw ValidationException::withMessages(['valor_bruto' => $erro]);
        }
        $this->fin->exigirMesAberto($orgId, $d['data_compra'], 'data_compra');

        return DB::transaction(function () use ($orgId, $d, $itensCalc, $bruto, $total, $cartao, $conta, $n) {
            $compra = Compra::create([
                'organizacao_id' => $orgId, 'descricao' => $d['descricao'], 'fornecedor' => $d['fornecedor'],
                'fornecedor_documento' => $d['fornecedor_documento'], 'data_compra' => $d['data_compra'], 'valor_bruto_centavos' => $bruto,
                'desconto_centavos' => $d['desconto'], 'juros_centavos' => $d['juros'], 'total_centavos' => $total, 'entrada_centavos' => $d['entrada'],
                'categoria_id' => $d['categoria_id'], 'modalidade' => $d['modalidade'], 'conta_id' => $conta?->id, 'cartao_id' => $cartao?->id,
                'nota_tipo' => $d['nota_tipo'], 'nota_numero' => $d['nota_numero'], 'nota_chave' => $d['nota_chave'],
                'garantia_ate' => $d['garantia_ate'], 'observacao' => $d['observacao'], 'criado_por' => auth()->id(),
            ]);
            foreach ($itensCalc as $i) {
                CompraItem::create(['organizacao_id' => $orgId, 'compra_id' => $compra->id, 'descricao' => $i['descricao'],
                    'quantidade' => $i['qtd'], 'valor_unitario_centavos' => $i['unitario'], 'total_centavos' => $i['total']]);
            }

            $titulo = Titulo::create([
                'organizacao_id' => $orgId, 'tipo' => 'pagar', 'descricao' => 'Compra: '.$d['descricao'], 'categoria_id' => $d['categoria_id'],
                'contraparte' => $d['fornecedor'], 'competencia' => CarbonImmutable::parse($d['data_compra'])->startOfMonth()->toDateString(),
                'valor_total_centavos' => $total, 'documento' => $d['nota_numero'], 'forma' => $this->formaDe($d['modalidade']),
                'origem_type' => $compra->getMorphClass(), 'origem_id' => $compra->id, 'criado_por' => auth()->id(),
            ]);

            $numero = 0;
            if ($d['entrada'] > 0) {
                Parcela::create(['organizacao_id' => $orgId, 'titulo_id' => $titulo->id, 'numero' => ++$numero,
                    'vencimento' => $d['data_compra'], 'valor_centavos' => $d['entrada']]);
            }
            $restante = $total - $d['entrada'];
            $base = intdiv($restante, $n);
            for ($i = 0; $i < $n; $i++) {
                $valor = $i === $n - 1 ? $restante - $base * ($n - 1) : $base;
                $fatura = $cartao ? $this->faturaPara($cartao, CarbonImmutable::parse($d['data_compra']), $i) : null;
                Parcela::create([
                    'organizacao_id' => $orgId, 'titulo_id' => $titulo->id, 'numero' => ++$numero, 'valor_centavos' => $valor,
                    'vencimento' => $fatura ? $fatura->vencimento->toDateString() : CarbonImmutable::parse($d['primeiro_vencimento'])->addMonthsNoOverflow($i)->toDateString(),
                    'fatura_cartao_id' => $fatura?->id,
                ]);
            }
            Evento::registrar('financeiro.compra_registrada', $compra, ['total' => $total, 'parcelas' => $n, 'modalidade' => $d['modalidade']]);

            if ($d['quitar_primeira']) {
                $p = $titulo->parcelas()->first();
                $this->fin->baixar($p, ['conta_id' => $conta->id, 'data' => $d['data_compra'], 'valor' => $p->valor_centavos,
                    'observacao' => $d['entrada'] > 0 ? 'Entrada paga no ato da compra' : 'Pago no ato da compra']);
            }

            return $compra;
        });
    }

    /** Cancela a compra (e o título) se nada foi pago. Com pagamentos, é preciso estornar antes: nada some do histórico. */
    public function cancelar(Compra $compra, string $motivo): void
    {
        $t = $compra->titulo()->with('parcelas.movimentos.estornoDe')->first();
        if ($compra->cancelada()) {
            throw ValidationException::withMessages(['motivo' => 'Compra já cancelada.']);
        }
        if ($t && $t->liquidado() > 0) {
            throw ValidationException::withMessages(['motivo' => 'Há pagamentos registrados nesta compra. Estorne-os (ou a fatura) antes de cancelar.']);
        }
        DB::transaction(function () use ($compra, $t, $motivo) {
            $compra->update(['cancelada_em' => now(), 'motivo_cancelamento' => $motivo]);
            $t?->update(['cancelado_em' => now(), 'motivo_cancelamento' => 'Compra cancelada: '.$motivo]);
            Evento::registrar('financeiro.compra_cancelada', $compra, ['motivo' => $motivo]);
        });
    }

    /**
     * Fatura em que cai a parcela $deslocamento (0 = primeira) de uma compra feita em $data.
     * Compra no dia do fechamento ou depois entra na fatura seguinte ("melhor dia de compra").
     */
    public function faturaPara(Cartao $cartao, CarbonImmutable $data, int $deslocamento = 0): FaturaCartao
    {
        $mesFech = $data->startOfMonth();
        if ($data->day >= $cartao->dia_fechamento) {
            $mesFech = $mesFech->addMonthNoOverflow();
        }
        $mesFech = $mesFech->addMonthsNoOverflow($deslocamento);
        $fechamento = $mesFech->setDay(min($cartao->dia_fechamento, $mesFech->daysInMonth));
        $mesVenc = $cartao->dia_vencimento > $cartao->dia_fechamento ? $mesFech : $mesFech->addMonthNoOverflow();
        $vencimento = $mesVenc->setDay(min($cartao->dia_vencimento, $mesVenc->daysInMonth));

        // whereDate: a coluna "date" pode voltar com hora em alguns bancos; comparar só a data evita fatura duplicada.
        return FaturaCartao::where('cartao_id', $cartao->id)->whereDate('referencia', $mesVenc->toDateString())->lockForUpdate()->first()
            ?? FaturaCartao::create(['organizacao_id' => $cartao->organizacao_id, 'cartao_id' => $cartao->id, 'referencia' => $mesVenc->toDateString(),
                'fechamento' => $fechamento->toDateString(), 'vencimento' => $vencimento->toDateString()]);
    }

    /**
     * Paga a fatura inteira: baixa cada parcela em aberto pela conta escolhida.
     * Encargos informados viram um título "Juros e encargos financeiros" já pago — nunca somados à compra.
     */
    public function pagarFatura(FaturaCartao $fatura, int $contaId, string $data, int $encargos = 0, ?string $chave = null): void
    {
        DB::transaction(function () use ($fatura, $contaId, $data, $encargos, $chave) {
            $fatura = FaturaCartao::lockForUpdate()->with('cartao', 'parcelas.movimentos.estornoDe', 'parcelas.titulo')->findOrFail($fatura->id);
            $abertas = $fatura->parcelas->filter(fn (Parcela $p) => $p->saldo() > 0);
            if ($abertas->isEmpty()) {
                // Inclui o duplo envio do formulário: a segunda tentativa não lança encargos de novo.
                throw ValidationException::withMessages(['conta_id' => $fatura->paga_em ? 'Esta fatura já foi paga em '.$fatura->paga_em->format('d/m/Y').'.' : 'Esta fatura não tem saldo em aberto.']);
            }
            if ($fatura->fechamento->gt(now()->startOfDay())) {
                throw ValidationException::withMessages(['conta_id' => 'A fatura ainda não fechou (fecha em '.$fatura->fechamento->format('d/m/Y').').']);
            }
            if ($encargos < 0) {
                throw ValidationException::withMessages(['encargos' => 'Encargos não podem ser negativos.']);
            }
            foreach ($abertas as $p) {
                $this->fin->baixar($p, ['conta_id' => $contaId, 'data' => $data, 'valor' => $p->saldo(), 'via_fatura' => true,
                    'chave' => $chave ? substr(hash('sha256', $chave.'|'.$p->id), 0, 64) : null,
                    'observacao' => 'Pagamento da fatura '.$fatura->cartao->nome.' '.$fatura->rotulo()]);
            }
            if ($encargos > 0) {
                $t = $this->fin->criarTitulo($fatura->organizacao_id, [
                    'tipo' => 'pagar', 'descricao' => 'Encargos da fatura '.$fatura->cartao->nome.' '.$fatura->rotulo(),
                    'categoria_id' => $this->fin->categoriaPadrao($fatura->organizacao_id, 'encargos')?->id,
                    'contraparte' => $fatura->cartao->instituicao ?: $fatura->cartao->nome,
                    'competencia' => CarbonImmutable::parse($data)->startOfMonth()->toDateString(), 'documento' => 'Fatura #'.$fatura->id, 'forma' => null,
                ], $encargos, 1, $data);
                $this->fin->baixar($t->parcelas()->first(), ['conta_id' => $contaId, 'data' => $data, 'valor' => $encargos, 'observacao' => 'Encargos pagos com a fatura']);
                $fatura->encargos_titulo_id = $t->id;
            }
            $fatura->fill(['paga_em' => $data, 'paga_por' => auth()->id()])->save();
            Evento::registrar('financeiro.fatura_cartao_paga', $fatura, ['valor' => (int) $abertas->sum(fn ($p) => $p->saldo()), 'encargos' => $encargos]);
        });
    }

    private function formaDe(string $modalidade): ?string
    {
        return match ($modalidade) {
            'pix' => 'pix', 'boleto', 'carne' => 'boleto', 'cartao' => 'cartao_credito',
            'transferencia' => 'transferencia', 'dinheiro' => 'dinheiro', default => 'outro',
        };
    }
}
