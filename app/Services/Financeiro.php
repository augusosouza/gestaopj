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
use App\Models\Transferencia;
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
            CategoriaFinanceira::create(['organizacao_id' => $orgId, 'tipo' => $tipo, 'nome' => $nome, 'chave' => $chave,
                'grupo_dre' => CategoriaFinanceira::DRE_PADRAO[$nome] ?? null]);
        }
    }

    /** Categoria padrão pela chave. Empresas antigas ganham a categoria (ou a chave na de mesmo nome) quando ela for usada. */
    public function categoriaPadrao(int $orgId, string $chave): ?CategoriaFinanceira
    {
        $this->garantirPadroes($orgId);
        $q = fn () => CategoriaFinanceira::withoutGlobalScope('organizacao')->where('organizacao_id', $orgId);
        if ($c = $q()->where('chave', $chave)->first()) {
            return $c;
        }
        $padrao = collect(CategoriaFinanceira::PADRAO)->first(fn ($p) => $p[2] === $chave);
        if (! $padrao) {
            return null;
        }
        $c = $q()->where('tipo', $padrao[0])->where('nome', $padrao[1])->first();
        $c ? $c->update(['chave' => $chave]) : $c = CategoriaFinanceira::create(['organizacao_id' => $orgId, 'tipo' => $padrao[0], 'nome' => $padrao[1], 'chave' => $chave, 'grupo_dre' => CategoriaFinanceira::DRE_PADRAO[$padrao[1]] ?? null]);

        return $c;
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

    /**
     * Renegocia o vencimento de uma parcela em aberto. Fica na auditoria (de, para, motivo);
     * lembretes internos e eventos do Google Agenda são recalculados a partir da nova data.
     */
    public function alterarVencimento(Parcela $p, string $novaData, string $motivo): void
    {
        $p->loadMissing('titulo', 'movimentos.estornoDe');
        $nova = CarbonImmutable::parse($novaData)->toDateString();
        $erro = match (true) {
            $p->titulo->cancelado() => 'Lançamento cancelado.',
            $p->fatura_cartao_id !== null => 'Parcela de cartão vence com a fatura; não pode ter vencimento próprio.',
            $p->saldo() === 0 => 'Parcela já quitada.',
            $nova === $p->vencimento->toDateString() => 'A nova data é igual à atual.',
            default => null,
        };
        if ($erro) {
            throw ValidationException::withMessages(['vencimento' => $erro]);
        }
        $de = $p->vencimento->toDateString();
        $p->update(['vencimento' => $nova]);
        Evento::registrar('financeiro.vencimento_alterado', $p->titulo, ['parcela' => $p->numero, 'de' => CarbonImmutable::parse($de)->format('d/m/Y'),
            'para' => CarbonImmutable::parse($nova)->format('d/m/Y'), 'motivo' => $motivo]);
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
     * @param  array{conta_id: int, data: string, valor: int, desconto?: int, retencao?: int, acrescimo?: int, observacao?: ?string, chave?: ?string, via_fatura?: bool}  $d
     */
    public function baixar(Parcela $parcela, array $d): Movimento
    {
        $d += ['desconto' => 0, 'retencao' => 0, 'acrescimo' => 0, 'tarifa' => 0, 'observacao' => null, 'chave' => null, 'via_fatura' => false];

        // Duplo envio do mesmo formulário: devolve a baixa já registrada, sem criar outra.
        if ($d['chave'] && ($ja = Movimento::where('chave_idempotencia', $d['chave'])->first())) {
            return $ja;
        }

        return DB::transaction(function () use ($parcela, $d) {
            // Trava a parcela: duas baixas simultâneas não ultrapassam o saldo.
            $parcela = Parcela::lockForUpdate()->with('titulo', 'movimentos.estornoDe')->findOrFail($parcela->id);
            if ($d['chave'] && ($ja = $parcela->movimentos()->where('chave_idempotencia', $d['chave'])->first())) {
                return $ja;
            }
            $t = $parcela->titulo;
            $this->conferirBaixa($parcela, $d);
            $tarifa = $d['tarifa'] > 0 ? $this->baixarTarifaDoRecebimento($parcela, $d) : null;
            $m = Movimento::create([
                'organizacao_id' => $parcela->organizacao_id, 'parcela_id' => $parcela->id, 'conta_id' => $d['conta_id'],
                'tipo' => 'baixa', 'data' => $d['data'], 'valor_centavos' => $d['valor'], 'desconto_centavos' => $d['desconto'],
                'retencao_centavos' => $d['retencao'], 'acrescimo_centavos' => $d['acrescimo'], 'observacao' => $d['observacao'],
                'chave_idempotencia' => $d['chave'], 'criado_por' => auth()->id(), 'tarifa_movimento_id' => $tarifa?->id,
                'tarifa_separada' => $tarifa !== null && isset($d['tarifa_data']),
            ]);
            Evento::registrar('financeiro.baixa', $t, ['parcela' => $parcela->numero, 'valor' => $d['valor'], 'data' => $d['data']]
                + ($tarifa ? ['tarifa' => $tarifa->valor_centavos, 'movimento_tarifa' => $tarifa->id] : []));
            $this->refletirNaOrigem($t);

            return $m;
        });
    }

    private function baixarTarifaDoRecebimento(Parcela $parcela, array $dados): Movimento
    {
        $data = $dados['tarifa_data'] ?? $dados['data'];
        $categoria = $this->categoriaPadrao($parcela->organizacao_id, 'tarifas');
        $titulo = $this->criarTitulo($parcela->organizacao_id, [
            'tipo' => 'pagar', 'descricao' => 'Tarifa de recebimento · título #'.$parcela->titulo_id,
            'categoria_id' => $categoria?->id,
            'competencia' => CarbonImmutable::parse($data)->startOfMonth()->toDateString(),
            'documento' => 'Título #'.$parcela->titulo_id,
        ], $dados['tarifa'], 1, $data);

        return $this->baixar($titulo->parcelas()->firstOrFail(), [
            'conta_id' => $dados['conta_id'], 'data' => $data, 'valor' => $dados['tarifa'],
        ]);
    }

    /** Confere os dados da baixa sem gravar movimentações. */
    public function conferirBaixa(Parcela $parcela, array $d): array
    {
        $d += ['desconto' => 0, 'retencao' => 0, 'acrescimo' => 0, 'via_fatura' => false, 'tarifa' => 0];
        $parcela->loadMissing('titulo', 'movimentos.estornoDe');
        $t = $parcela->titulo;
        $conta = ContaFinanceira::where('ativa', true)->find($d['conta_id']);

        $erro = match (true) {
            $d['tarifa'] < 0 || ($d['tarifa'] > 0 && (! $t->receber() || $d['tarifa'] >= $d['valor'])) => 'A tarifa deve ser menor que o recebimento bruto.',
            $t->cancelado() => 'Título cancelado.',
            // Compra no cartão sai do caixa quando a fatura é paga: pagar a parcela avulsa duplicaria a saída.
            $parcela->fatura_cartao_id && ! $d['via_fatura'] => 'Parcela de cartão de crédito: registre o pagamento pela fatura do cartão.',
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

        if ($d['tarifa'] > 0) {
            $dataTarifa = $d['tarifa_data'] ?? $d['data'];
            if (CarbonImmutable::parse($dataTarifa)->gt(now())) {
                throw ValidationException::withMessages(['tarifa_data' => 'A data da tarifa não pode ser futura.']);
            }
            $this->exigirMesAberto($parcela->organizacao_id, $dataTarifa, 'tarifa_data');
        }
        $abatimento = $d['valor'] + $d['desconto'] + $d['retencao'] - $d['acrescimo'];
        $saldo = $parcela->saldo();
        if ($abatimento > $saldo) {
            throw ValidationException::withMessages(['valor' => 'A baixa ('.brl($abatimento).') é maior que o saldo da parcela ('.brl($saldo).').']);
        }
        if ($abatimento <= 0) {
            throw ValidationException::withMessages(['valor' => 'O acréscimo não pode ser maior que o valor pago.']);
        }

        if (isset($d['saldo_conferido']) && $saldo !== $d['saldo_conferido']) {
            throw ValidationException::withMessages(['valor' => 'O saldo mudou. Refaça a conferência da baixa.']);
        }

        return ['conta' => $conta, 'saldo' => $saldo, 'restante' => $saldo - $abatimento];
    }

    public function estornar(Movimento $m, string $motivo): Movimento
    {
        return $this->estornarBaixa($m, $motivo);
    }

    private function estornarBaixa(Movimento $m, string $motivo, bool $juntoComRecebimento = false): Movimento
    {
        return DB::transaction(function () use ($m, $motivo, $juntoComRecebimento) {
            $m = Movimento::lockForUpdate()->with('estorno', 'linhaExtrato', 'parcela.titulo', 'tarifa.parcela.titulo', 'recebimentoDaTarifa')->findOrFail($m->id);
            $erro = match (true) {
                $m->recebimentoDaTarifa && ! $juntoComRecebimento => 'Estorne a tarifa pelo recebimento original.',
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

            if ($m->tarifa) {
                $this->estornarBaixa($m->tarifa, $motivo, true);
                $this->cancelarTitulo($m->tarifa->parcela->titulo, $motivo);
            }
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
            $m = Movimento::lockForUpdate()->with('estorno', 'linhaExtrato', 'parcela.titulo', 'tarifa', 'recebimentoDaTarifa')->findOrFail($m->id);
            $erro = match (true) {
                $linha->conciliada() => 'Esta linha do extrato já está conciliada.',
                $m->linhaExtrato !== null => 'Este movimento já está conciliado com outra linha.',
                $m->tipo !== 'baixa' || $m->estornado() => 'Só baixas ativas podem ser conciliadas.',
                $m->conta_id !== $linha->conta_id => 'O movimento é de outra conta.',
                $m->tarifaEmCreditoLiquido() => 'Esta tarifa será conciliada junto com o recebimento bruto.',
                $m->valorConciliavel() !== $linha->valor_centavos => 'Valor ou sentido diferentes: extrato '.brl($linha->valor_centavos).', movimento '.brl($m->valorConciliavel()).'.',
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
        if (! $linha->conciliada()) {
            return;
        }
        $dados = array_filter(['movimento' => $linha->movimento_id, 'transferencia' => $linha->transferencia_id]);
        $linha->update(['movimento_id' => null, 'transferencia_id' => null, 'conciliado_por' => null, 'conciliado_em' => null]);
        Evento::registrar('financeiro.desconciliado', $linha, $dados);
    }

    /** Liga a linha do extrato a uma transferência entre contas (mesma conta, mesmo valor e sentido). */
    public function conciliarTransferencia(ExtratoLinha $linha, Transferencia $t): void
    {
        DB::transaction(function () use ($linha, $t) {
            $linha = ExtratoLinha::lockForUpdate()->findOrFail($linha->id);
            $jaNaConta = ExtratoLinha::where('transferencia_id', $t->id)->where('conta_id', $linha->conta_id)->exists();
            $erro = match (true) {
                $linha->conciliada() => 'Esta linha do extrato já está conciliada.',
                $jaNaConta => 'Esta transferência já está conciliada com outra linha desta conta.',
                $t->efeitoEm($linha->conta_id) === 0 => 'A transferência não envolve esta conta.',
                $t->efeitoEm($linha->conta_id) !== $linha->valor_centavos => 'Valor ou sentido diferentes: extrato '.brl($linha->valor_centavos).', transferência '.brl($t->efeitoEm($linha->conta_id)).'.',
                default => null,
            };
            if ($erro) {
                throw ValidationException::withMessages(['transferencia_id' => $erro]);
            }
            $linha->update(['transferencia_id' => $t->id, 'conciliado_por' => auth()->id(), 'conciliado_em' => now(), 'ignorada' => false]);
            Evento::registrar('financeiro.conciliado', $linha, ['transferencia' => $t->id]);
        });
    }

    /** Transferências da conta com o mesmo valor e sentido da linha, até 5 dias de distância. */
    public function sugestoesTransferencia(ExtratoLinha $linha): Collection
    {
        $d = CarbonImmutable::instance($linha->data);

        return Transferencia::with('origem', 'destino')
            ->where(fn ($q) => $q->where('conta_origem_id', $linha->conta_id)->orWhere('conta_destino_id', $linha->conta_id))
            ->whereDate('data', '>=', $d->subDays(5)->toDateString())->whereDate('data', '<=', $d->addDays(5)->toDateString())
            ->where('valor_centavos', abs($linha->valor_centavos))->get()
            ->filter(fn (Transferencia $t) => $t->efeitoEm($linha->conta_id) === $linha->valor_centavos
                && ! ExtratoLinha::where('transferencia_id', $t->id)->where('conta_id', $linha->conta_id)->exists())
            ->values();
    }

    // ---------------- Transferências entre contas ----------------

    /**
     * Transferência entre contas da empresa. Não é receita nem despesa.
     * A tarifa, se houver, vira uma despesa própria (categoria Tarifas bancárias), já paga pela conta de origem.
     */
    public function transferir(int $orgId, int $origemId, int $destinoId, string $data, int $valor, ?string $descricao, int $tarifa = 0, ?string $chave = null): Transferencia
    {
        if ($chave && ($ja = Transferencia::where('chave_idempotencia', $chave)->first())) {
            return $ja;
        }
        $origem = ContaFinanceira::where('ativa', true)->find($origemId);
        $destino = ContaFinanceira::where('ativa', true)->find($destinoId);
        $erro = match (true) {
            ! $origem || ! $destino || $origem->organizacao_id !== $orgId || $destino->organizacao_id !== $orgId => 'Escolha duas contas ativas da empresa.',
            $origem->id === $destino->id => 'A conta de origem e a de destino precisam ser diferentes.',
            $valor <= 0 => 'Informe um valor positivo.',
            $tarifa < 0 => 'A tarifa não pode ser negativa.',
            CarbonImmutable::parse($data)->gt(now()) => 'A data da transferência não pode ser futura.',
            default => null,
        };
        if ($erro) {
            throw ValidationException::withMessages(['valor' => $erro]);
        }
        $this->exigirMesAberto($orgId, $data, 'data');

        return DB::transaction(function () use ($orgId, $origem, $destino, $data, $valor, $descricao, $tarifa, $chave) {
            $t = Transferencia::create([
                'organizacao_id' => $orgId, 'conta_origem_id' => $origem->id, 'conta_destino_id' => $destino->id, 'data' => $data,
                'valor_centavos' => $valor, 'descricao' => $descricao, 'chave_idempotencia' => $chave, 'criado_por' => auth()->id(),
            ]);
            if ($tarifa > 0) {
                $tt = $this->criarTitulo($orgId, ['tipo' => 'pagar', 'descricao' => 'Tarifa de transferência — '.$origem->nome,
                    'categoria_id' => $this->categoriaPadrao($orgId, 'tarifas')?->id, 'contraparte' => $origem->banco ?: $origem->nome,
                    'competencia' => CarbonImmutable::parse($data)->startOfMonth()->toDateString(), 'documento' => 'Transf. #'.$t->id], $tarifa, 1, $data);
                $this->baixar($tt->parcelas()->first(), ['conta_id' => $origem->id, 'data' => $data, 'valor' => $tarifa, 'observacao' => 'Tarifa da transferência #'.$t->id]);
            }
            Evento::registrar('financeiro.transferencia', $t, ['de' => $origem->nome, 'para' => $destino->nome, 'valor' => $valor, 'tarifa' => $tarifa]);

            return $t;
        });
    }

    public function estornarTransferencia(Transferencia $t, string $motivo): Transferencia
    {
        return DB::transaction(function () use ($t, $motivo) {
            $t = Transferencia::lockForUpdate()->with('estorno')->findOrFail($t->id);
            $erro = match (true) {
                $t->estorno_de_id !== null => 'Este registro já é um estorno.',
                $t->estorno !== null => 'Esta transferência já foi estornada.',
                ExtratoLinha::where('transferencia_id', $t->id)->exists() => 'Transferência conciliada com o extrato. Desfaça a conciliação antes de estornar.',
                default => null,
            };
            if ($erro) {
                throw ValidationException::withMessages(['motivo' => $erro]);
            }
            $hoje = now()->toDateString();
            $this->exigirMesAberto($t->organizacao_id, $hoje, 'motivo');
            $e = Transferencia::create([
                'organizacao_id' => $t->organizacao_id, 'conta_origem_id' => $t->conta_destino_id, 'conta_destino_id' => $t->conta_origem_id,
                'data' => $hoje, 'valor_centavos' => $t->valor_centavos, 'descricao' => 'Estorno: '.$motivo, 'estorno_de_id' => $t->id, 'criado_por' => auth()->id(),
            ]);
            Evento::registrar('financeiro.transferencia_estornada', $t, ['motivo' => $motivo]);

            return $e;
        });
    }

    /** Baixas ativas da mesma conta, mesmo valor e sentido, até 5 dias de distância. */
    public function sugestoes(ExtratoLinha $linha): Collection
    {
        $d = CarbonImmutable::instance($linha->data);

        return Movimento::with('parcela.titulo', 'estorno', 'linhaExtrato', 'tarifa', 'recebimentoDaTarifa')
            ->where('conta_id', $linha->conta_id)->where('tipo', 'baixa')
            ->whereDate('data', '>=', $d->subDays(5)->toDateString())->whereDate('data', '<=', $d->addDays(5)->toDateString())
            ->where(fn ($q) => $q->where('valor_centavos', abs($linha->valor_centavos))->orWhereNotNull('tarifa_movimento_id'))->get()
            ->filter(fn (Movimento $m) => ! $m->estornado() && ! $m->linhaExtrato
                && ! $m->tarifaEmCreditoLiquido() && $m->valorConciliavel() === $linha->valor_centavos)
            ->values();
    }

    // ---------------- Análises do painel ----------------

    /**
     * Saldo projetado por semana: saldo atual + parcelas em aberto pelo vencimento
     * (receber soma, pagar subtrai). Vencidas entram na primeira semana.
     *
     * @return list<array{inicio: CarbonImmutable, fim: CarbonImmutable, entradas: int, saidas: int, saldo: int}>
     */
    public function fluxoCaixa(int $semanas = 12): array
    {
        $saldo = (int) ContaFinanceira::where('ativa', true)->get()->sum(fn ($c) => $c->saldo());
        $abertas = Parcela::with('titulo', 'movimentos.estornoDe')->whereHas('titulo', fn ($q) => $q->whereNull('cancelado_em'))->get()
            ->filter(fn (Parcela $p) => $p->saldo() > 0);
        $inicio = CarbonImmutable::now()->startOfWeek();
        $out = [];
        for ($i = 0; $i < $semanas; $i++) {
            $de = $inicio->addWeeks($i);
            $ate = $de->endOfWeek();
            $daSemana = $abertas->filter(fn (Parcela $p) => $i === 0 ? $p->vencimento->lte($ate) : $p->vencimento->between($de, $ate));
            $entradas = (int) $daSemana->filter(fn ($p) => $p->titulo->receber())->sum(fn ($p) => $p->saldo());
            $saidas = (int) $daSemana->reject(fn ($p) => $p->titulo->receber())->sum(fn ($p) => $p->saldo());
            $saldo += $entradas - $saidas;
            $out[] = ['inicio' => $de, 'fim' => $ate, 'entradas' => $entradas, 'saidas' => $saidas, 'saldo' => $saldo];
        }

        return $out;
    }

    /** Despesas da competência por categoria (previsto e pago), da maior para a menor. */
    public function despesasPorCategoria(CarbonImmutable $competencia): Collection
    {
        return Titulo::with('categoria', 'parcelas.movimentos.estornoDe')->where('tipo', 'pagar')->whereNull('cancelado_em')
            ->whereDate('competencia', $competencia->startOfMonth())->get()
            ->groupBy(fn (Titulo $t) => $t->categoria?->nome ?? 'Sem categoria')
            ->map(fn ($ts, $nome) => ['nome' => $nome, 'valor' => (int) $ts->sum('valor_total_centavos'), 'pago' => (int) $ts->sum(fn ($t) => $t->pagoEmDinheiro()), 'qtd' => $ts->count()])
            ->sortByDesc('valor')->values();
    }

    /** Maiores destinos das despesas da competência (fornecedores e prestadores). */
    public function maioresFavorecidos(CarbonImmutable $competencia, int $limite = 8): Collection
    {
        return Titulo::where('tipo', 'pagar')->whereNull('cancelado_em')->whereDate('competencia', $competencia->startOfMonth())->get()
            ->groupBy(fn (Titulo $t) => $t->contraparte ?: 'Não informado')
            ->map(fn ($ts, $nome) => ['nome' => $nome, 'valor' => (int) $ts->sum('valor_total_centavos'), 'qtd' => $ts->count()])
            ->sortByDesc('valor')->take($limite)->values();
    }

    /** Pendências acionáveis: extratos, conciliação e vencidos. */
    public function acoesNecessarias(): array
    {
        $l = app(LembretesBancarios::class);
        $acoes = [];
        foreach (ContaFinanceira::where('ativa', true)->get() as $c) {
            $s = $l->situacao($c);
            if ($s['estado'] === 'desatualizado') {
                $acoes[] = ['tipo' => 'bad', 'titulo' => "Extrato desatualizado — {$c->nome}",
                    'texto' => 'Coberto '.($c->extrato_ate ? 'até '.$c->extrato_ate->format('d/m/Y') : 'por nenhum extrato').'; exigido até '.$s['exigido_ate']->format('d/m/Y').'.',
                    'acao' => 'Importar extrato', 'url' => route('financeiro.conciliacao', $c).'#importar'];
            }
            if ($s['a_conciliar'] > 0) {
                $acoes[] = ['tipo' => 'warn', 'titulo' => "Conciliação pendente — {$c->nome}", 'texto' => "{$s['a_conciliar']} lançamento(s) do extrato sem conferência.",
                    'acao' => 'Conciliar', 'url' => route('financeiro.conciliacao', $c)];
            }
        }
        $p = $this->painel(CarbonImmutable::now());
        if ($p['vencido_receber'] > 0) {
            $acoes[] = ['tipo' => 'warn', 'titulo' => 'Recebimentos vencidos', 'texto' => brl($p['vencido_receber']).' a receber com vencimento passado.',
                'acao' => 'Ver', 'url' => route('financeiro.index', ['tipo' => 'receber', 'situacao' => 'vencido', 'periodo' => 'todos'])];
        }
        if ($p['vencido_pagar'] > 0) {
            $acoes[] = ['tipo' => 'bad', 'titulo' => 'Pagamentos vencidos', 'texto' => brl($p['vencido_pagar']).' a pagar com vencimento passado.',
                'acao' => 'Ver', 'url' => route('financeiro.index', ['tipo' => 'pagar', 'situacao' => 'vencido', 'periodo' => 'todos'])];
        }

        return $acoes;
    }

    // ---------------- Painel ----------------

    /** Previsto x realizado da competência, vencidos e saldo em contas. */
    public function painel(CarbonImmutable $competencia, bool $detalharDespesas = false): array
    {
        $titulos = Titulo::with('parcelas.movimentos.estornoDe')->whereNull('cancelado_em')
            ->when($detalharDespesas, fn ($q) => $q->with('parcelas.movimentos.estorno'))
            ->whereDate('competencia', $competencia->startOfMonth())->get();
        $todos = Titulo::with('parcelas.movimentos.estornoDe')->whereNull('cancelado_em')->get();
        $soma = fn ($tipo, $f) => (int) $titulos->where('tipo', $tipo)->sum($f);
        $vencidas = $todos->flatMap(fn (Titulo $t) => $t->parcelas->filter->vencida()->map(fn ($p) => [$t, $p]));

        $despesasPagas = ($detalharDespesas ? $titulos->where('tipo', 'pagar') : collect())
            ->map(fn (Titulo $titulo) => ['titulo' => $titulo, 'valor' => $titulo->pagoEmDinheiro()])
            ->filter(fn (array $despesa) => $despesa['valor'] > 0);
        $maiorDespesa = $despesasPagas->sortByDesc('valor')->first();
        $totalPago = (int) $despesasPagas->sum('valor');

        return [
            'despesa_concentrada' => $maiorDespesa && $maiorDespesa['valor'] >= 100000 && $totalPago <= $maiorDespesa['valor'] * 4
                ? $maiorDespesa + ['percentual' => round($maiorDespesa['valor'] * 100 / $totalPago)]
                : null,
            'receitas_previstas' => $soma('receber', fn ($t) => $t->valor_total_centavos),
            'receitas_realizadas' => $soma('receber', fn ($t) => $t->liquidado()),
            'despesas_previstas' => $soma('pagar', fn ($t) => $t->valor_total_centavos),
            'despesas_realizadas' => $soma('pagar', fn ($t) => $t->liquidado()),
            'vencido_receber' => (int) $vencidas->filter(fn ($x) => $x[0]->receber())->sum(fn ($x) => $x[1]->saldo()),
            'vencido_pagar' => (int) $vencidas->reject(fn ($x) => $x[0]->receber())->sum(fn ($x) => $x[1]->saldo()),
            'saldo_contas' => (int) ContaFinanceira::where('ativa', true)->get()->sum(fn ($c) => $c->saldo()),
            'nao_conciliadas' => ExtratoLinha::pendentes()->count(),
        ];
    }
}
