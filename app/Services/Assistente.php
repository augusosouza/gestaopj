<?php

namespace App\Services;

use App\Enums\Papel;
use App\Models\CategoriaFinanceira;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\ExtratoLinha;
use App\Models\FaturaCartao;
use App\Models\Movimento;
use App\Models\Titulo;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Assistente financeiro sem tokens (FIN-12).
 *
 * Um interpretador de regras reconhece a intenção e o período; a resposta vem
 * dos MESMOS serviços usados nas telas (Financeiro, PosicaoFinanceira, Recebimentos,
 * Sugestoes). O texto do usuário nunca vira SQL: só escolhe uma consulta pronta.
 * As consultas passam pelo escopo da empresa ativa, então respeitam as permissões.
 */
final class Assistente
{
    /** Perguntas sugeridas na tela, na ordem de exibição. */
    public const SUGERIDAS = [
        'Quanto tenho disponível?', 'Quanto vou receber nos próximos 30 dias?', 'Quanto preciso pagar nos próximos 30 dias?',
        'O que vence amanhã?', 'Quais clientes estão atrasados?', 'Quanto gastei com software este mês?',
        'Quais compras estão parceladas?', 'Quanto vem na fatura do cartão?', 'O que falta conciliar?',
        'Como estará meu caixa?', 'Quais despesas ultrapassaram o orçamento?', 'Quais extratos estão desatualizados?', 'O que precisa da minha atenção?',
    ];

    public function __construct(private Financeiro $fin, private PosicaoFinanceira $posicao, private Recebimentos $receb, private Sugestoes $sugestoes) {}

    /**
     * @return array{intencao: string, pergunta: string, titulo: string, destaque: ?string, texto: string, colunas: list<string>, linhas: list<list<string>>, origem: string, links: list<array{0: string, 1: string}>}
     */
    public function responder(string $pergunta): array
    {
        $q = $this->normalizar($pergunta);
        $r = match (true) {
            $q === '' => $this->ajuda($pergunta, false),
            $this->tem($q, ['registrar compra', 'lancar compra', 'nova compra', 'cadastrar compra', 'quero registrar']) => $this->prepararCompra($pergunta, $q),
            $this->tem($q, ['atencao', 'pendencia', 'resolver', 'sugest', 'prioridade']) => $this->atencao(),
            $this->tem($q, ['concilia']) => $this->conciliar(),
            $this->tem($q, ['fatura', 'cartao']) => $this->fatura(),
            $this->tem($q, ['orcament', 'ultrapass', 'limite de gasto']) => $this->orcamentos(),
            $this->tem($q, ['extrato', 'desatualizad']) => $this->extratos(),
            $this->tem($q, ['parcelad', 'parcelament']) => $this->parceladas(),
            $this->tem($q, ['atrasad', 'inadimpl', 'devendo', 'em atraso']) => $this->atrasados(),
            $this->tem($q, ['gastei', 'gasto', 'gastos', 'despesa com', 'despesas com', 'paguei com']) => $this->gastos($q),
            $this->tem($q, ['vence', 'vencimento', 'vencem']) => $this->vencimentos($q),
            $this->tem($q, ['como estara', 'caixa', 'projec', 'previsao', 'vai faltar', 'falta dinheiro']) => $this->caixa($q),
            $this->tem($q, ['receber', 'recebo', 'entrar']) => $this->aReceber($q),
            $this->tem($q, ['pagar', 'devo', 'pago', 'contas a pagar']) => $this->aPagar($q),
            $this->tem($q, ['disponivel', 'saldo', 'quanto tenho', 'dinheiro em conta', 'tenho em caixa']) => $this->disponivel(),
            default => $this->ajuda($pergunta, true),
        };

        return $r + ['pergunta' => $pergunta, 'destaque' => null, 'colunas' => [], 'linhas' => [], 'links' => []];
    }

    // ---------------- Intenções ----------------

    private function disponivel(): array
    {
        $contas = ContaFinanceira::where('ativa', true)->orderBy('nome')->get();
        $total = (int) $contas->sum(fn ($c) => $c->saldo());

        return ['intencao' => 'disponivel', 'titulo' => 'Saldo registrado nas contas', 'destaque' => brl($total),
            'texto' => 'É o saldo registrado no Augi (saldo inicial + recebimentos − pagamentos ± transferências). '
                .'Para saber quanto pode gastar sem comprometer os vencimentos, veja "Livre para decidir" no Meu Caixa.',
            'colunas' => ['Conta', 'Saldo registrado', 'Extrato até'],
            'linhas' => $contas->map(fn ($c) => [$c->nome, brl($c->saldo()), $c->extrato_ate?->format('d/m/Y') ?? 'sem extrato'])->all(),
            'origem' => 'Soma de '.$contas->count().' conta(s) ativa(s), calculada como em Contas e bancos.',
            'links' => [['Abrir Meu Caixa', route('financeiro.caixa')], ['Contas e bancos', route('financeiro.contas')]]];
    }

    private function aReceber(string $q): array
    {
        $dias = $this->dias($q, 30);
        $ev = $this->posicao->compromissos(CarbonImmutable::today()->addDays($dias))->where('tipo', 'receber');
        $venc = $this->receb->vencidas();

        return ['intencao' => 'receber', 'titulo' => "A receber nos próximos {$dias} dias", 'destaque' => brl((int) $ev->sum('valor')),
            'texto' => $venc->isNotEmpty() ? 'Além disso, há '.brl((int) $venc->sum('saldo')).' vencidos e não recebidos (fora desta soma).' : 'Não há recebimentos vencidos.',
            'colunas' => ['Vencimento', 'Cliente', 'Descrição', 'Valor'],
            'linhas' => $ev->take(15)->map(fn ($e) => [$e['vencimento']->format('d/m'), $e['contraparte'] ?? '—', $e['descricao'], brl($e['valor'])])->values()->all(),
            'origem' => $ev->count().' parcela(s) em aberto com vencimento até '.CarbonImmutable::today()->addDays($dias)->format('d/m/Y').'.',
            'links' => [['Contas a receber', route('financeiro.index', ['tipo' => 'receber', 'situacao' => 'aberto', 'periodo' => 'todos'])], ['Agenda', route('financeiro.agenda')]]];
    }

    private function aPagar(string $q): array
    {
        $dias = $this->dias($q, 30);
        $ev = $this->posicao->compromissos(CarbonImmutable::today()->addDays($dias))->where('tipo', 'pagar');
        $atrasado = (int) $ev->where('atrasado', true)->sum('valor');

        return ['intencao' => 'pagar', 'titulo' => "A pagar nos próximos {$dias} dias", 'destaque' => brl((int) $ev->sum('valor')),
            'texto' => $atrasado ? 'Inclui '.brl($atrasado).' já vencidos.' : 'Nenhum pagamento vencido.',
            'colunas' => ['Vencimento', 'Favorecido', 'Descrição', 'Valor'],
            'linhas' => $ev->take(15)->map(fn ($e) => [$e['vencimento']->format('d/m').($e['atrasado'] ? ' (vencido)' : ''), $e['contraparte'] ?? '—', $e['descricao'], brl($e['valor'])])->values()->all(),
            'origem' => $ev->count().' compromisso(s); faturas de cartão contam uma vez, no vencimento da fatura.',
            'links' => [['Contas a pagar', route('financeiro.index', ['tipo' => 'pagar', 'situacao' => 'aberto', 'periodo' => 'todos'])], ['Agenda', route('financeiro.agenda')]]];
    }

    private function vencimentos(string $q): array
    {
        $hoje = CarbonImmutable::today();
        [$de, $ate, $rotulo] = match (true) {
            $this->tem($q, ['amanha']) => [$hoje->addDay(), $hoje->addDay(), 'amanhã'],
            $this->tem($q, ['hoje']) => [$hoje, $hoje, 'hoje'],
            $this->tem($q, ['semana']) => [$hoje, $hoje->endOfWeek(), 'nesta semana'],
            $this->tem($q, ['mes']) => [$hoje, $hoje->endOfMonth(), 'neste mês'],
            default => [$hoje, $hoje->addDays($this->dias($q, 7)), 'nos próximos '.$this->dias($q, 7).' dias'],
        };
        $ev = $this->posicao->compromissos($ate)->filter(fn ($e) => ! $e['atrasado'] && $e['vencimento']->gte($de));

        return ['intencao' => 'vencimentos', 'titulo' => 'O que vence '.$rotulo, 'destaque' => $ev->count().' compromisso(s)',
            'texto' => 'Entradas: '.brl((int) $ev->where('tipo', 'receber')->sum('valor')).' · Saídas: '.brl((int) $ev->where('tipo', 'pagar')->sum('valor')).'.',
            'colunas' => ['Data', 'Tipo', 'Descrição', 'Valor'],
            'linhas' => $ev->map(fn ($e) => [$e['vencimento']->format('d/m'), $e['tipo'] === 'receber' ? 'Receber' : 'Pagar', $e['descricao'], brl($e['valor'])])->values()->all(),
            'origem' => 'Parcelas em aberto entre '.$de->format('d/m').' e '.$ate->format('d/m').'.',
            'links' => [['Abrir agenda', route('financeiro.agenda')]]];
    }

    private function atrasados(): array
    {
        $clientes = $this->receb->porCliente();

        return ['intencao' => 'atrasados', 'titulo' => 'Clientes com pagamento em atraso', 'destaque' => brl((int) $clientes->sum('total')),
            'texto' => $clientes->isEmpty() ? 'Nenhum cliente em atraso.' : $clientes->count().' cliente(s) com parcelas vencidas.',
            'colunas' => ['Cliente', 'Vencido', 'Maior atraso', 'Parcelas'],
            'linhas' => $clientes->map(fn ($c) => [$c['cliente'].($c['recorrente'] ? ' (atrasa com frequência)' : ''), brl($c['total']), $c['maior_atraso'].' dia(s)', (string) $c['qtd']])->all(),
            'origem' => 'Parcelas a receber com vencimento anterior a hoje e saldo em aberto.',
            'links' => [['Abrir inadimplência', route('financeiro.inadimplencia')]]];
    }

    private function gastos(string $q): array
    {
        [$de, $ate, $rotulo] = $this->periodo($q);
        $cats = CategoriaFinanceira::where('tipo', 'despesa')->get();
        $cat = $cats->first(fn ($c) => collect(preg_split('/[\s,]+/', $this->normalizar($c->nome)))
            ->filter(fn ($w) => mb_strlen($w) >= 4)->contains(fn ($w) => str_contains($q, $w)));
        $tituloIds = Titulo::where('tipo', 'pagar')->whereNull('cancelado_em')->when($cat, fn ($w) => $w->where('categoria_id', $cat->id))->pluck('id');
        // Caixa: dinheiro que saiu no período (baixas não estornadas).
        $baixas = Movimento::with('parcela.titulo', 'estorno')->where('tipo', 'baixa')->whereDate('data', '>=', $de->toDateString())->whereDate('data', '<=', $ate->toDateString())
            ->whereHas('parcela', fn ($w) => $w->whereIn('titulo_id', $tituloIds))->get()->reject->estornado();
        // Competência: despesas lançadas para os meses do período.
        $competencia = (int) Titulo::whereIn('id', $tituloIds)->whereDate('competencia', '>=', $de->startOfMonth()->toDateString())->whereDate('competencia', '<=', $ate->toDateString())->sum('valor_total_centavos');
        $nome = $cat?->nome ?? 'todas as categorias';

        return ['intencao' => 'gastos', 'titulo' => "Gastos com {$nome} — {$rotulo}", 'destaque' => brl((int) $baixas->sum('valor_centavos')),
            'texto' => 'Valor pago no período (caixa). Por competência, as despesas lançadas somam '.brl($competencia).'.'
                .(! $cat ? ' Não reconheci uma categoria na pergunta; mostrei o total. Categorias: '.$cats->pluck('nome')->implode(', ').'.' : ''),
            'colunas' => ['Data', 'Descrição', 'Valor pago'],
            'linhas' => $baixas->sortByDesc('data')->take(15)->map(fn ($m) => [$m->data->format('d/m'), $m->parcela->titulo->descricao, brl($m->valor_centavos)])->values()->all(),
            'origem' => $baixas->count().' pagamento(s) entre '.$de->format('d/m/Y').' e '.$ate->format('d/m/Y').'.',
            'links' => [['Despesas por categoria', route('financeiro.painel', ['aba' => 'despesas'])]]];
    }

    private function orcamentos(): array
    {
        $mes = CarbonImmutable::today()->startOfMonth();
        $l = app(Planejamento::class)->acompanhamento($mes);
        $fora = $l->where('estado', '!=', 'ok');

        return ['intencao' => 'orcamento', 'titulo' => 'Orçamentos de '.$mes->translatedFormat('F/Y'),
            'destaque' => $l->isEmpty() ? 'nenhum orçamento' : $l->where('estado', 'excedido')->count().' ultrapassado(s)',
            'texto' => $l->isEmpty() ? 'Ainda não há orçamentos neste mês.' : ($fora->isEmpty() ? 'Todos abaixo de '.Planejamento::ALERTA_PCT.'% do limite.' : $fora->count().' acima de '.Planejamento::ALERTA_PCT.'% do limite.'),
            'colunas' => ['Orçamento', 'Orçado', 'Comprometido', 'Uso'],
            'linhas' => $l->map(fn ($o) => [$o['rotulo'], brl($o['orcado']), brl($o['comprometido']), $o['pct'].'%'.($o['estado'] === 'excedido' ? ' (ultrapassado)' : '')])->all(),
            'origem' => 'Comprometido = despesas lançadas no mês (pagas ou não), como na tela de Planejamento.',
            'links' => [['Abrir planejamento', route('planejamento.index')]]];
    }

    private function extratos(): array
    {
        $l = app(LembretesBancarios::class);
        $contas = ContaFinanceira::where('ativa', true)->orderBy('nome')->get()->map(fn ($c) => ['c' => $c, 's' => $l->situacao($c)]);
        $atras = $contas->where('s.estado', 'desatualizado');

        return ['intencao' => 'extratos', 'titulo' => 'Extratos bancários', 'destaque' => $atras->count().' desatualizado(s)',
            'texto' => $atras->isEmpty() ? 'Todas as contas com lembrete estão com o extrato em dia.' : 'Importe o extrato do período que falta para conferir os saldos.',
            'colunas' => ['Conta', 'Extrato coberto até', 'Situação'],
            'linhas' => $contas->map(fn ($x) => [$x['c']->nome, $x['c']->extrato_ate?->format('d/m/Y') ?? 'nenhum',
                ['em_dia' => 'Em dia', 'desatualizado' => 'Desatualizado', 'dispensado' => 'Lembrete dispensado', 'sem_lembrete' => 'Sem lembrete'][$x['s']['estado']] ?? $x['s']['estado']])->values()->all(),
            'origem' => 'Período coberto pelos extratos importados (não a data do envio), como nos lembretes bancários.',
            'links' => [['Contas e bancos', route('financeiro.contas')]]];
    }

    private function parceladas(): array
    {
        $compras = Compra::with('titulo.parcelas.movimentos.estornoDe')->whereNull('cancelada_em')->get()
            ->filter(fn ($c) => $c->titulo && $c->titulo->parcelas->count() > 1 && $c->titulo->saldo() > 0)->values();

        return ['intencao' => 'parceladas', 'titulo' => 'Compras parceladas com parcelas a pagar', 'destaque' => brl((int) $compras->sum(fn ($c) => $c->titulo->saldo())),
            'texto' => $compras->isEmpty() ? 'Nenhuma compra parcelada em aberto.' : $compras->count().' compra(s) ainda em pagamento.',
            'colunas' => ['Compra', 'Pago', 'Restante', 'Parcelas restantes'],
            'linhas' => $compras->map(fn ($c) => [$c->descricao, brl($c->titulo->liquidado()), brl($c->titulo->saldo()),
                (string) $c->titulo->parcelas->filter(fn ($p) => $p->saldo() > 0)->count()])->all(),
            'origem' => 'Compras não canceladas com mais de uma parcela e saldo em aberto.',
            'links' => [['Ver parcelamentos', route('compras.index', ['aba' => 'parcelamentos'])]]];
    }

    private function fatura(): array
    {
        $faturas = FaturaCartao::with('cartao', 'parcelas.movimentos.estornoDe')->whereNull('paga_em')
            ->whereDate('vencimento', '>=', CarbonImmutable::today()->subDays(60))->orderBy('vencimento')->get()
            ->filter(fn ($f) => $f->saldo() > 0)->groupBy('cartao_id')->map->first()->values();

        return ['intencao' => 'fatura', 'titulo' => 'Próxima fatura de cada cartão', 'destaque' => brl((int) $faturas->sum(fn ($f) => $f->saldo())),
            'texto' => $faturas->isEmpty() ? 'Nenhuma fatura em aberto.' : 'Valores das compras já lançadas. Encargos só aparecem quando a fatura é paga.',
            'colunas' => ['Cartão', 'Fatura', 'Vencimento', 'Valor', 'Situação'],
            'linhas' => $faturas->map(fn ($f) => [$f->cartao->rotulo(), $f->rotulo(), $f->vencimento->format('d/m/Y'), brl($f->saldo()), ucfirst($f->situacao())])->all(),
            'origem' => 'Parcelas de compras no cartão ligadas à fatura.',
            'links' => [['Abrir cartões', route('cartoes.index')]]];
    }

    private function conciliar(): array
    {
        $porConta = ExtratoLinha::pendentes()->with('conta')->get()->groupBy('conta_id');

        return ['intencao' => 'conciliar', 'titulo' => 'Extrato aguardando conferência', 'destaque' => $porConta->flatten()->count().' lançamento(s)',
            'texto' => $porConta->isEmpty() ? 'Tudo conciliado.' : 'Linhas importadas do banco ainda sem baixa ou transferência correspondente.',
            'colunas' => ['Conta', 'Pendentes', 'Mais antiga'],
            'linhas' => $porConta->map(fn ($ls) => [$ls->first()->conta->nome, (string) $ls->count(), $ls->min('data')->format('d/m/Y')])->values()->all(),
            'origem' => 'Linhas de extrato não conciliadas e não marcadas como "sem correspondência".',
            'links' => $porConta->map(fn ($ls) => ['Conciliar '.$ls->first()->conta->nome, route('financeiro.conciliacao', $ls->first()->conta_id)])->values()->all()];
    }

    private function caixa(string $q): array
    {
        $dias = $this->dias($q, 30);
        $p = $this->posicao->projetar($dias);
        $texto = $p['negativo']
            ? 'Atenção: o saldo fica negativo em '.$p['negativo']['data']->format('d/m/Y').' (faltam '.brl($p['negativo']['deficit']).').'
            : 'O saldo não fica negativo no período. Menor saldo previsto: '.brl($p['minimo']['valor']).' em '.$p['minimo']['data']->format('d/m').'.';

        return ['intencao' => 'caixa', 'titulo' => "Caixa nos próximos {$dias} dias", 'destaque' => brl($p['saldo_final']).' ao fim do período',
            'texto' => $texto.' Livre para decidir hoje: '.brl($p['livre']).'.',
            'colunas' => ['Indicador', 'Valor'],
            'linhas' => [['Saldo registrado hoje', brl($p['saldo_atual'])], ['Entradas previstas', brl($p['a_receber'])], ['Saídas previstas', brl($p['a_pagar'])],
                ['Menor saldo no período', brl($p['minimo']['valor']).' ('.$p['minimo']['data']->format('d/m').')'], ['Recebimentos vencidos (fora da conta)', brl($p['vencidos_receber'])]],
            'origem' => 'Projeção dia a dia pelas datas de vencimento; é uma estimativa, não dinheiro disponível.',
            'links' => [['Abrir Meu Caixa', route('financeiro.caixa', ['dias' => $dias])]]];
    }

    private function atencao(): array
    {
        $s = $this->sugestoes->gerar();

        return ['intencao' => 'atencao', 'titulo' => 'O que precisa da sua atenção', 'destaque' => $s->count().' item(ns)',
            'texto' => $s->isEmpty() ? 'Nada pendente agora.' : 'Ordenado do mais urgente para o informativo.',
            'colunas' => ['Situação', 'Detalhe'],
            'linhas' => $s->take(15)->map(fn ($x) => [$x['titulo'], $x['motivo']])->all(),
            'origem' => 'Motor de sugestões: regras fixas sobre os dados da empresa.',
            'links' => [['Abrir sugestões', route('inteligencia.sugestoes')]]];
    }

    /** Não grava: só abre o formulário preenchido para revisão e confirmação. */
    private function prepararCompra(string $original, string $q): array
    {
        $podeGravar = ctx()->tem(Papel::Admin, Papel::Financeiro);
        $valor = null;
        if (preg_match('/(?:r\$\s*)?(\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d+(?:,\d{1,2})?)/i', $original, $m)) {
            try {
                // "2.400" na fala é milhar, não decimal.
                $valor = Dinheiro::paraCentavos(str_contains($m[1], ',') ? $m[1] : str_replace('.', '', $m[1]));
            } catch (\InvalidArgumentException) {
            }
        }
        $extenso = ['duas' => 2, 'dois' => 2, 'tres' => 3, 'quatro' => 4, 'cinco' => 5, 'seis' => 6, 'sete' => 7, 'oito' => 8,
            'nove' => 9, 'dez' => 10, 'onze' => 11, 'doze' => 12, 'dezoito' => 18, 'vinte e quatro' => 24];
        $parcelas = 1;
        if (preg_match('/(\d{1,3})\s*(?:x|vezes|parcelas?)\b/', $q, $pm)) {
            $parcelas = (int) $pm[1];
        } elseif (preg_match('/\b('.implode('|', array_keys($extenso)).')\s+(?:vezes|parcelas?)\b/', $q, $pm)) {
            $parcelas = $extenso[$pm[1]];
        }
        $parcelas = max(1, min(120, $parcelas));
        $params = array_filter(['valor' => $valor ? number_format($valor / 100, 2, ',', '.') : null, 'parcelas' => $parcelas,
            'modalidade' => $this->tem($q, ['cartao']) ? 'cartao' : null]);

        return ['intencao' => 'compra', 'titulo' => 'Registrar compra',
            'destaque' => ($valor ? brl($valor) : 'valor não identificado').' em '.$parcelas.'x',
            'texto' => $podeGravar ? 'Preparei o formulário com esses dados. Nada foi gravado: revise e confirme na próxima tela.'
                : 'Seu acesso é de consulta: não é possível registrar compras.',
            'origem' => 'Valor e número de parcelas lidos da sua frase.',
            'links' => $podeGravar ? [['Revisar e registrar', route('compras.create', $params)]] : []];
    }

    private function ajuda(string $pergunta, bool $naoEntendi): array
    {
        return ['intencao' => 'ajuda', 'titulo' => $naoEntendi ? 'Ainda não sei responder isso' : 'O que você quer saber?',
            'texto' => ($naoEntendi ? 'O assistente responde perguntas financeiras com base em regras, sem IA generativa. ' : '')
                .'Experimente uma das perguntas abaixo, ou use palavras como saldo, receber, pagar, vence, atrasados, gastei com, parceladas, fatura, conciliar, caixa.',
            'origem' => 'Assistente por regras.',
            'links' => collect(self::SUGERIDAS)->take(6)->map(fn ($s) => [$s, route('inteligencia.assistente', ['q' => $s])])->all()];
    }

    // ---------------- Interpretação ----------------

    private function normalizar(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($s))));
    }

    private function tem(string $q, array $termos): bool
    {
        foreach ($termos as $t) {
            if (str_contains($q, $t)) {
                return true;
            }
        }

        return false;
    }

    /** "próximos 15 dias", "60 dias", "semana", "mês"; padrão informado. Limite 365. */
    private function dias(string $q, int $padrao): int
    {
        if (preg_match('/(\d{1,3})\s*dias?/', $q, $m)) {
            return max(1, min(365, (int) $m[1]));
        }

        return match (true) {
            $this->tem($q, ['semana']) => 7, $this->tem($q, ['quinzena']) => 15,
            $this->tem($q, ['trimestre', '3 meses']) => 90, $this->tem($q, ['mes']) => 30, default => $padrao,
        };
    }

    /** Período passado para gastos: este mês (padrão), mês passado, este ano, últimos N dias. */
    private function periodo(string $q): array
    {
        $hoje = CarbonImmutable::today();

        return match (true) {
            $this->tem($q, ['mes passado', 'ultimo mes']) => [$hoje->subMonthNoOverflow()->startOfMonth(), $hoje->subMonthNoOverflow()->endOfMonth(), 'mês passado'],
            $this->tem($q, ['ano']) => [$hoje->startOfYear(), $hoje, 'este ano'],
            (bool) preg_match('/(\d{1,3})\s*dias?/', $q, $m) => [$hoje->subDays((int) $m[1]), $hoje, "últimos {$m[1]} dias"],
            default => [$hoje->startOfMonth(), $hoje, 'este mês'],
        };
    }
}
