<?php

namespace App\Services;

use App\Models\CobrancaRegistro;
use App\Models\Evento;
use App\Models\Parcela;
use App\Models\Titulo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestão de cobrança (Augi 3.0, etapa 7): contatos, promessas e renegociações.
 * Nada aqui movimenta dinheiro. Recebimento só existe com baixa (Financeiro::baixar).
 */
final class Cobrancas
{
    public function __construct(private Financeiro $fin) {}

    /** @param array{tipo: string, canal: ?string, data: string, mensagem: ?string, resposta: ?string, parcela_id: ?int, promessa_data: ?string, promessa_valor: ?int, responsavel_id: ?int} $d */
    public function registrar(Titulo $t, array $d): CobrancaRegistro
    {
        $p = $d['parcela_id'] ? Parcela::where('titulo_id', $t->id)->find($d['parcela_id']) : null;
        $erro = match (true) {
            ! $t->receber() => 'Histórico de cobrança é para contas a receber.',
            $t->cancelado() => 'Lançamento cancelado.',
            $d['parcela_id'] && ! $p => 'Parcela inválida.',
            ! in_array($d['tipo'], ['contato', 'promessa'], true) => 'Tipo inválido.',
            $d['tipo'] === 'promessa' && (! $d['promessa_data'] || ($d['promessa_valor'] ?? 0) <= 0) => 'Promessa precisa de data e valor.',
            $d['tipo'] === 'promessa' && CarbonImmutable::parse($d['promessa_data'])->lt(CarbonImmutable::parse($d['data'])->startOfDay()) => 'A data prometida não pode ser antes do contato.',
            $d['tipo'] === 'promessa' && $d['promessa_valor'] > ($p ? $p->saldo() : $t->load('parcelas.movimentos.estornoDe')->saldo()) => 'O valor prometido é maior que o saldo em aberto.',
            CarbonImmutable::parse($d['data'])->gt(now()) => 'O contato não pode estar no futuro.',
            default => null,
        };
        if ($erro) {
            throw ValidationException::withMessages(['tipo' => $erro]);
        }
        $r = CobrancaRegistro::create([
            'organizacao_id' => $t->organizacao_id, 'titulo_id' => $t->id, 'parcela_id' => $p?->id, 'tipo' => $d['tipo'], 'canal' => $d['canal'],
            'data' => $d['data'], 'mensagem' => $d['mensagem'], 'resposta' => $d['resposta'],
            'promessa_data' => $d['tipo'] === 'promessa' ? $d['promessa_data'] : null,
            'promessa_valor_centavos' => $d['tipo'] === 'promessa' ? $d['promessa_valor'] : null,
            'responsavel_id' => $d['responsavel_id'] ?? auth()->id(), 'criado_por' => auth()->id(),
        ]);
        Evento::registrar($d['tipo'] === 'promessa' ? 'cobranca.promessa' : 'cobranca.contato', $t,
            array_filter(['canal' => $d['canal'], 'promessa' => $d['tipo'] === 'promessa' ? brl($d['promessa_valor']).' até '.CarbonImmutable::parse($d['promessa_data'])->format('d/m/Y') : null]));

        return $r;
    }

    /**
     * Renegocia vencimentos de parcelas em aberto (mesmas parcelas, novas datas).
     * Cada mudança passa pela regra de vencimento do financeiro (auditada) e o acordo fica no histórico.
     * Juros/multa do acordo entram como acréscimo no momento do recebimento (sem inflar o título antes).
     *
     * @param  array<int, string>  $novasDatas  parcela_id => nova data (Y-m-d)
     */
    public function renegociar(Titulo $t, array $novasDatas, string $motivo, ?string $condicoes = null): CobrancaRegistro
    {
        if ($novasDatas === []) {
            throw ValidationException::withMessages(['parcelas' => 'Escolha ao menos uma parcela e a nova data.']);
        }

        return DB::transaction(function () use ($t, $novasDatas, $motivo, $condicoes) {
            $det = [];
            $valor = 0;
            foreach ($novasDatas as $pid => $data) {
                $p = Parcela::where('titulo_id', $t->id)->with('movimentos.estornoDe', 'titulo')->find($pid)
                    ?? throw ValidationException::withMessages(['parcelas' => 'Parcela inválida.']);
                $de = $p->vencimento->format('d/m/Y');
                $this->fin->alterarVencimento($p, $data, 'Renegociação: '.$motivo);
                $det[] = ['parcela' => $p->numero, 'de' => $de, 'para' => CarbonImmutable::parse($data)->format('d/m/Y'), 'saldo' => $p->saldo()];
                $valor += $p->saldo();
            }
            $r = CobrancaRegistro::create(['organizacao_id' => $t->organizacao_id, 'titulo_id' => $t->id, 'tipo' => 'renegociacao', 'data' => now(),
                'mensagem' => $motivo, 'resposta' => $condicoes, 'detalhes' => ['parcelas' => $det, 'valor' => $valor],
                'responsavel_id' => auth()->id(), 'criado_por' => auth()->id()]);
            Evento::registrar('cobranca.renegociacao', $t, ['parcelas' => count($det), 'valor' => $valor, 'motivo' => $motivo]);

            return $r;
        });
    }
}
