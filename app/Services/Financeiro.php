<?php

namespace App\Services;

use App\Enums\EtapaContabil;
use App\Enums\StatusCobranca;
use App\Enums\StatusFatura;
use App\Models\CategoriaFinanceira;
use App\Models\Cobranca;
use App\Models\CompetenciaContabil;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\ExtratoLinha;
use App\Models\Fatura;
use App\Models\Movimento;
use App\Models\Parcela;
use App\Models\Titulo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regras do financeiro (FIN-01). Toda alteração de dinheiro passa por aqui.
 *
 *  - Baixa: abate a parcela; nunca acima do saldo; bloqueada em mês fechado pelo contador.
 *  - Estorno: novo movimento que anula a baixa; a baixa original permanece.
 *  - Conciliação: liga uma linha do extrato a um movimento de mesmo valor e sentido.
 *  - Faturas e cobranças existentes viram títulos (sem duplicar) e refletem as baixas.
 */
final class Financeiro
{
    // ---------------- Cadastros padrão ----------------

    public function garantirPadroes(int $orgId): void
    {
        if (CategoriaFinanceira::withoutGlobalScope('organizacao')->where('organizacao_id', $orgId)->exists()) {
            return;
        }
        foreach (CategoriaFinanceira::PADRAO as [$tipo, $nome, $chave]) {
            CategoriaFinanceira::create(['organizacao_id' => $orgId, 'tipo' => $tipo, 'nome' => $nome, 'chave' => $chave]);
        }
    }

    public function categoriaPadrao(int $orgId, string $chave): ?CategoriaFinanceira
    {
        $this->garantirPadroes($orgId);

        return CategoriaFinanceira::withoutGlobalScope('organizacao')->where('organizacao_id', $orgId)->where('chave', $chave)->first();
    }

    /** Conta usada quando o fluxo antigo não informa conta: a primeira ativa, ou uma "Conta principal". */
    public function contaPrincipal(int $orgId): ContaFinanceira
    {
        return ContaFinanceira::withoutGlobalScope('organizacao')->where('organizacao_id', $orgId)->where('ativa', true)->orderBy('id')->first()
            ?? ContaFinanceira::create(['organizacao_id' => $orgId, 'nome' => 'Conta principal', 'tipo' => 'corrente',
                'saldo_inicial_centavos' => 0, 'saldo_inicial_em' => now()->startOfYear()->toDateString()]);
    }

    // ---------------- Títulos ----------------

    /**
     * Cria título manual com N parcelas mensais. Centavos da divisão vão para a última parcela.
     *
     * @param  array{tipo: string, descricao: string, categoria_id: ?int, contraparte: ?string, competencia: string, documento: ?string}  $d
     */
    public function criarTitulo(int $orgId, array $d, int $valorTotal, int $nParcelas, string $primeiroVencimento): Titulo
    {
        if ($valorTotal <= 0 || $nParcelas < 1 || $nParcelas > 120) {
            throw ValidationException::withMessages(['valor' => 'Informe um valor positivo e de 1 a 120 parcelas.']);
        }
        $this->exigirMesAberto($orgId, $d['competencia'], 'competencia');

        return DB::transaction(function () use ($orgId, $d, $valorTotal, $nParcelas, $primeiroVencimento) {
            $t = Titulo::create($d + ['organizacao_id' => $orgId, 'valor_total_centavos' => $valorTotal, 'criado_por' => auth()->id()]);
            $base = intdiv($valorTotal, $nParcelas);
            $venc = CarbonImmutable::parse($primeiroVencimento);
            for ($i = 1; $i <= $nParcelas; $i++) {
                Parcela::create([
                    'organizacao_id' => $orgId, 'titulo_id' => $t->id, 'numero' => $i,
                    'vencimento' => $venc->addMonthsNoOverflow($i - 1)->toDateString(),
                    'valor_centavos' => $i === $nParcelas ? $valorTotal - $base * ($nParcelas - 1) : $base,
                ]);
            }
            Evento::registrar('financeiro.titulo_criado', $t, ['valor' => $valorTotal, 'parcelas' => $nParcelas]);

            return $t;
        });
    }

    public function cancelarTitulo(Titulo $t, string $motivo): void
    {
        if ($t->origem_type) {
            throw ValidationException::withMessages(['motivo' => 'Títulos gerados por fatura ou cobrança são ajustados na própria fatura/cobrança.']);
        }
        $t->load('parcelas.movimentos.estornoDe');
        if ($t->liquidado() > 0) {
            throw ValidationException::withMessages(['motivo' => 'Há baixas ativas. Estorne-as antes de cancelar o título.']);
        }
        $t->update(['cancelado_em' => now(), 'motivo_cancelamento' => $motivo]);
        Evento::registrar('financeiro.titulo_cancelado', $t, ['motivo' => $motivo]);
    }

    /** Título de uma fatura (a receber). Idempotente. */
    public function tituloDeFatura(Fatura $f): Titulo
    {
        return $this->tituloDeOrigem($f, 'receber', $f->contrato->cliente->razao_social ?? null,
            'Fatura '.$f->contrato->numero.' · '.$f->competencia->format('m/Y'), 'servicos', $f->competencia, $f->valor_centavos, $f->vencimento);
    }

    /** Título de uma cobrança de prestador (a pagar). Vencimento padrão: dia configurado do mês seguinte. */
    public function tituloDeCobranca(Cobranca $c): Titulo
    {
        $mes = CarbonImmutable::instance($c->competencia)->addMonthNoOverflow()->startOfMonth();
        $venc = $mes->setDay(min((int) config('gestao.dia_pagamento_prestadores', 15), $mes->daysInMonth));

        return $this->tituloDeOrigem($c, 'pagar', $c->prestador->nome ?? null,
            'Prestador '.($c->prestador->nome ?? '').' · '.$c->competencia->format('m/Y'), 'prestadores', $c->competencia, $c->valor_centavos, $venc);
    }

    private function tituloDeOrigem($origem, string $tipo, ?string $contraparte, string $descricao, string $chaveCat, $competencia, int $valor, $vencimento): Titulo
    {
        $existente = Titulo::withoutGlobalScope('organizacao')->where('origem_type', $origem->getMorphClass())->where('origem_id', $origem->id)->first();
        if ($existente) {
            return $existente;
        }

        return DB::transaction(function () use ($origem, $tipo, $contraparte, $descricao, $chaveCat, $competencia, $valor, $vencimento) {
            $t = Titulo::create([
                'organizacao_id' => $origem->organizacao_id, 'tipo' => $tipo, 'descricao' => $descricao,
                'categoria_id' => $this->categoriaPadrao($origem->organizacao_id, $chaveCat)?->id,
                'contraparte' => $contraparte, 'competencia' => CarbonImmutable::instance($competencia)->toDateString(),
                'valor_total_centavos' => $valor, 'origem_type' => $origem->getMorphClass(), 'origem_id' => $origem->id,
            ]);
            Parcela::create(['organizacao_id' => $origem->organizacao_id, 'titulo_id' => $t->id, 'numero' => 1,
                'vencimento' => CarbonImmutable::instance($vencimento)->toDateString(), 'valor_centavos' => $valor]);

            return $t;
        });
    }

    /**
     * Compatibilidade: cria títulos para faturas/cobranças sem título e converte recebimentos/pagamentos
     * já registrados no modelo antigo em baixas. Idempotente — rodar de novo não duplica.
     *
     * @return array{titulos: int, baixas: int}
     */
    public function sincronizarLegado(): array
    {
        $n = ['titulos' => 0, 'baixas' => 0];
        $semTitulo = fn (string $classe) => $classe::withoutGlobalScope('organizacao')
            ->whereNotExists(fn ($q) => $q->from('titulos')->whereColumn('titulos.origem_id', (new $classe)->getTable().'.id')
                ->where('titulos.origem_type', (new $classe)->getMorphClass()))->get();

        foreach ($semTitulo(Fatura::class) as $f) {
            $t = $this->tituloDeFatura($f);
            $n['titulos']++;
            if ($f->valor_recebido_centavos && $f->recebida_em) {
                $this->baixaLegada($t, $f->valor_recebido_centavos, 0, $f->recebida_em, 'fatura-'.$f->id);
                $n['baixas']++;
            }
        }
        foreach ($semTitulo(Cobranca::class) as $c) {
            $t = $this->tituloDeCobranca($c);
            $n['titulos']++;
            if ($c->valor_pago_centavos && $c->paga_em) {
                $retencao = max(0, $c->valor_centavos - $c->valor_pago_centavos);
                $this->baixaLegada($t, $c->valor_pago_centavos, $retencao, $c->paga_em, 'cobranca-'.$c->id);
                $n['baixas']++;
            }
        }

        return $n;
    }

    private function baixaLegada(Titulo $t, int $valor, int $retencao, $data, string $chave): void
    {
        $p = $t->parcelas()->first();
        Movimento::create([
            'organizacao_id' => $t->organizacao_id, 'parcela_id' => $p->id, 'conta_id' => $this->contaPrincipal($t->organizacao_id)->id,
            'tipo' => 'baixa', 'data' => CarbonImmutable::instance($data)->toDateString(), 'valor_centavos' => $valor,
            'retencao_centavos' => min($retencao, $p->valor_centavos), 'observacao' => 'Migrado do registro anterior',
            'chave_idempotencia' => 'legado-'.$chave,
        ]);
    }

    // ---------------- Baixa e estorno ----------------

    /**
     * @param  array{conta_id: int, data: string, valor: int, desconto?: int, retencao?: int, acrescimo?: int, observacao?: ?string, chave?: ?string}  $d
     */
    public function baixar(Parcela $parcela, array $d): Movimento
    {
        $d += ['desconto' => 0, 'retencao' => 0, 'acrescimo' => 0, 'observacao' => null, 'chave' => null];

        // Duplo envio do mesmo formulário: devolve a baixa já registrada, sem criar outra.
        if ($d['chave'] && ($ja = Movimento::where('chave_idempotencia', $d['chave'])->first())) {
            return $ja;
        }

        return DB::transaction(function () use ($parcela, $d) {
            // Trava a parcela: duas baixas simultâneas não ultrapassam o saldo.
            $parcela = Parcela::lockForUpdate()->with('titulo', 'movimentos.estornoDe')->findOrFail($parcela->id);
            $t = $parcela->titulo;
            $conta = ContaFinanceira::where('ativa', true)->find($d['conta_id']);

            $erro = match (true) {
                $t->cancelado() => 'Título cancelado.',
                ! $conta || $conta->organizacao_id !== $parcela->organizacao_id => 'Conta inválida ou inativa.',
                $d['valor'] < 0 || $d['desconto'] < 0 || $d['retencao'] < 0 || $d['acrescimo'] < 0 => 'Valores não podem ser negativos.',
                $d['valor'] + $d['desconto'] + $d['retencao'] === 0 => 'Informe o valor da baixa.',
                CarbonImmutable::parse($d['data'])->gt(now()) => 'A data da baixa não pode ser futura.',
                default => null,
            };
            if ($erro) {
                throw ValidationException::withMessages(['valor' => $erro]);
            }
            $this->exigirOrigemLiberada($t);
            $this->exigirMesAberto($parcela->organizacao_id, $d['data'], 'data');

            $abatimento = $d['valor'] + $d['desconto'] + $d['retencao'] - $d['acrescimo'];
            $saldo = $parcela->saldo();
            if ($abatimento > $saldo) {
                throw ValidationException::withMessages(['valor' => 'A baixa ('.brl($abatimento).') é maior que o saldo da parcela ('.brl($saldo).').']);
            }
            if ($abatimento <= 0) {
                throw ValidationException::withMessages(['valor' => 'O acréscimo não pode ser maior que o valor pago.']);
            }

            $m = Movimento::create([
                'organizacao_id' => $parcela->organizacao_id, 'parcela_id' => $parcela->id, 'conta_id' => $conta->id,
                'tipo' => 'baixa', 'data' => $d['data'], 'valor_centavos' => $d['valor'], 'desconto_centavos' => $d['desconto'],
                'retencao_centavos' => $d['retencao'], 'acrescimo_centavos' => $d['acrescimo'], 'observacao' => $d['observacao'],
                'chave_idempotencia' => $d['chave'], 'criado_por' => auth()->id(),
            ]);
            Evento::registrar('financeiro.baixa', $t, ['parcela' => $parcela->numero, 'valor' => $d['valor'], 'data' => $d['data']]);
            $this->refletirNaOrigem($t);

            return $m;
        });
    }

    public function estornar(Movimento $m, string $motivo): Movimento
    {
        return DB::transaction(function () use ($m, $motivo) {
            $m = Movimento::lockForUpdate()->with('estorno', 'linhaExtrato', 'parcela.titulo')->findOrFail($m->id);
            $erro = match (true) {
                $m->tipo !== 'baixa' => 'Só baixas podem ser estornadas.',
                $m->estorno !== null => 'Esta baixa já foi estornada.',
                $m->linhaExtrato !== null => 'Baixa conciliada com o extrato. Desfaça a conciliação antes de estornar.',
                default => null,
            };
            if ($erro) {
                throw ValidationException::withMessages(['motivo' => $erro]);
            }
            $hoje = now()->toDateString();
            $this->exigirMesAberto($m->organizacao_id, $hoje, 'motivo');

            $e = Movimento::create([
                'organizacao_id' => $m->organizacao_id, 'parcela_id' => $m->parcela_id, 'conta_id' => $m->conta_id,
                'tipo' => 'estorno', 'data' => $hoje, 'valor_centavos' => $m->valor_centavos,
                'estorno_de_id' => $m->id, 'observacao' => $motivo, 'criado_por' => auth()->id(),
            ]);
            Evento::registrar('financeiro.estorno', $m->parcela->titulo, ['valor' => $m->valor_centavos, 'motivo' => $motivo]);
            $this->refletirNaOrigem($m->parcela->titulo);

            return $e;
        });
    }

    /** Fatura/cobrança passam a refletir as baixas (compatibilidade com telas e relatórios existentes). */
    private function refletirNaOrigem(Titulo $t): void
    {
        $t->load('parcelas.movimentos.estornoDe');
        $origem = $t->origem;
        if (! $origem) {
            return;
        }
        $dinheiro = 0;
        $ultima = null;
        foreach ($t->parcelas as $p) {
            foreach ($p->movimentos as $m) {
                if ($m->tipo === 'baixa' && ! $m->estornado()) {
                    $dinheiro += $m->valor_centavos;
                    $ultima = max($ultima, $m->data->toDateString());
                }
            }
        }
        $quitado = $t->saldo() === 0;
        if ($origem instanceof Fatura) {
            $origem->update([
                'valor_recebido_centavos' => $dinheiro ?: null, 'recebida_em' => $quitado ? $ultima : null,
                'status' => $quitado ? StatusFatura::Recebida : ($origem->nota_fiscal_id ? StatusFatura::Emitida : StatusFatura::Aberta),
            ]);
        } elseif ($origem instanceof Cobranca) {
            $origem->update([
                'valor_pago_centavos' => $dinheiro ?: null, 'paga_em' => $quitado ? $ultima : null,
                'status' => $quitado ? StatusCobranca::Paga : StatusCobranca::Aprovada,
            ]);
        }
    }

    /** Regras operacionais mantidas: fatura só recebe com NFS-e; prestador só recebe após aprovação. */
    private function exigirOrigemLiberada(Titulo $t): void
    {
        $o = $t->origem;
        if ($o instanceof Fatura && ! in_array($o->status, [StatusFatura::Emitida, StatusFatura::Recebida], true)) {
            throw ValidationException::withMessages(['valor' => 'Só é possível registrar recebimento de fatura com nota emitida.']);
        }
        if ($o instanceof Cobranca && ! in_array($o->status, [StatusCobranca::Aprovada, StatusCobranca::Paga], true)) {
            throw ValidationException::withMessages(['valor' => 'Só cobranças aprovadas podem ser pagas.']);
        }
    }

    /** Mês fechado pelo contador não aceita movimento nem título. */
    public function exigirMesAberto(int $orgId, string $data, string $campo): void
    {
        $mes = CarbonImmutable::parse($data)->startOfMonth();
        $fechado = CompetenciaContabil::withoutGlobalScope('organizacao')->where('organizacao_id', $orgId)
            ->whereDate('competencia', $mes)->where('etapa', EtapaContabil::Fechada)->exists();
        if ($fechado) {
            throw ValidationException::withMessages([$campo => 'O mês '.$mes->format('m/Y').' foi fechado pelo contador. Peça a reabertura para alterar.']);
        }
    }

    // ---------------- Conciliação ----------------

    public function conciliar(ExtratoLinha $linha, Movimento $m): void
    {
        DB::transaction(function () use ($linha, $m) {
            $linha = ExtratoLinha::lockForUpdate()->findOrFail($linha->id);
            $m->load('estorno', 'linhaExtrato', 'parcela.titulo');
            $erro = match (true) {
                $linha->movimento_id !== null => 'Esta linha do extrato já está conciliada.',
                $m->linhaExtrato !== null => 'Este movimento já está conciliado com outra linha.',
                $m->tipo !== 'baixa' || $m->estornado() => 'Só baixas ativas podem ser conciliadas.',
                $m->conta_id !== $linha->conta_id => 'O movimento é de outra conta.',
                $m->efeitoNaConta() !== $linha->valor_centavos => 'Valor ou sentido diferentes: extrato '.brl($linha->valor_centavos).', movimento '.brl($m->efeitoNaConta()).'.',
                default => null,
            };
            if ($erro) {
                throw ValidationException::withMessages(['movimento_id' => $erro]);
            }
            $linha->update(['movimento_id' => $m->id, 'conciliado_por' => auth()->id(), 'conciliado_em' => now(), 'ignorada' => false]);
            Evento::registrar('financeiro.conciliado', $linha, ['movimento' => $m->id]);
        });
    }

    public function desconciliar(ExtratoLinha $linha): void
    {
        if (! $linha->movimento_id) {
            return;
        }
        $mov = $linha->movimento_id;
        $linha->update(['movimento_id' => null, 'conciliado_por' => null, 'conciliado_em' => null]);
        Evento::registrar('financeiro.desconciliado', $linha, ['movimento' => $mov]);
    }

    /** Baixas ativas da mesma conta, mesmo valor e sentido, até 5 dias de distância. */
    public function sugestoes(ExtratoLinha $linha): Collection
    {
        $d = CarbonImmutable::instance($linha->data);

        return Movimento::with('parcela.titulo', 'estorno', 'linhaExtrato')
            ->where('conta_id', $linha->conta_id)->where('tipo', 'baixa')
            ->whereBetween('data', [$d->subDays(5)->toDateString(), $d->addDays(5)->toDateString()])
            ->where('valor_centavos', abs($linha->valor_centavos))->get()
            ->filter(fn (Movimento $m) => ! $m->estornado() && ! $m->linhaExtrato && $m->efeitoNaConta() === $linha->valor_centavos)
            ->values();
    }

    // ---------------- Painel ----------------

    /** Previsto x realizado da competência, vencidos e saldo em contas. */
    public function painel(CarbonImmutable $competencia): array
    {
        $titulos = Titulo::with('parcelas.movimentos.estornoDe')->whereNull('cancelado_em')
            ->whereDate('competencia', $competencia->startOfMonth())->get();
        $todos = Titulo::with('parcelas.movimentos.estornoDe')->whereNull('cancelado_em')->get();
        $soma = fn ($tipo, $f) => (int) $titulos->where('tipo', $tipo)->sum($f);
        $vencidas = $todos->flatMap(fn (Titulo $t) => $t->parcelas->filter->vencida()->map(fn ($p) => [$t, $p]));

        return [
            'receitas_previstas' => $soma('receber', fn ($t) => $t->valor_total_centavos),
            'receitas_realizadas' => $soma('receber', fn ($t) => $t->liquidado()),
            'despesas_previstas' => $soma('pagar', fn ($t) => $t->valor_total_centavos),
            'despesas_realizadas' => $soma('pagar', fn ($t) => $t->liquidado()),
            'vencido_receber' => (int) $vencidas->filter(fn ($x) => $x[0]->receber())->sum(fn ($x) => $x[1]->saldo()),
            'vencido_pagar' => (int) $vencidas->reject(fn ($x) => $x[0]->receber())->sum(fn ($x) => $x[1]->saldo()),
            'saldo_contas' => (int) ContaFinanceira::where('ativa', true)->get()->sum(fn ($c) => $c->saldo()),
            'nao_conciliadas' => ExtratoLinha::whereNull('movimento_id')->where('ignorada', false)->count(),
        ];
    }
}
