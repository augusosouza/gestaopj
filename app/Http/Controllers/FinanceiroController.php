<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\ExtratoLinha;
use App\Models\Movimento;
use App\Models\Parcela;
use App\Models\Titulo;
use App\Models\Transferencia;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Financeiro;
use App\Services\ImportadorExtrato;
use App\Services\LembretesBancarios;
use App\Support\Competencia;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinanceiroController extends Controller
{
    public function __construct(private Financeiro $fin) {}

    // ---------------- Lançamentos ----------------

    public function index(Request $request): View
    {
        $this->fin->garantirPadroes(ctx()->organizacaoId());
        $competencia = Competencia::de($request->query('competencia'));
        $tipo = in_array($request->query('tipo'), ['receber', 'pagar'], true) ? $request->query('tipo') : null;
        $situacao = in_array($request->query('situacao'), ['aberto', 'parcial', 'liquidado', 'vencido', 'cancelado'], true) ? $request->query('situacao') : null;
        $todos = $request->query('periodo') === 'todos';
        $busca = trim((string) $request->query('q'));
        $semCategoria = $request->query('categoria') === 'nenhuma';
        $categoriaId = $semCategoria ? null : ($request->integer('categoria') ?: null);

        $titulos = Titulo::with('parcelas.movimentos.estornoDe', 'categoria')
            ->when(! $todos, fn ($q) => $q->whereDate('competencia', $competencia))
            ->when($tipo, fn ($q) => $q->where('tipo', $tipo))
            ->when($categoriaId, fn ($q) => $q->where('categoria_id', $categoriaId))
            ->when($semCategoria, fn ($q) => $q->whereNull('categoria_id')->whereNull('cancelado_em'))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('descricao', 'like', "%{$busca}%")
                ->orWhere('contraparte', 'like', "%{$busca}%")->orWhere('documento', 'like', "%{$busca}%")))
            ->orderBy('competencia')->orderBy('id')->limit(500)->get()
            ->filter(fn (Titulo $t) => match ($situacao) {
                null => true,
                'vencido' => $t->vencido(),
                default => $t->situacao() === $situacao,
            })->values();

        return view('financeiro.index', [
            'titulos' => $titulos, 'competencia' => $competencia, 'tipo' => $tipo, 'situacao' => $situacao,
            'todos' => $todos, 'busca' => $busca, 'categoriaId' => $categoriaId,
            'categorias' => CategoriaFinanceira::orderBy('tipo')->orderBy('nome')->get(),
        ]);
    }

    /** Painel financeiro: Resumo, Fluxo de caixa e Despesas (FIN-UI-01). */
    public function painel(Request $request): View
    {
        $this->fin->garantirPadroes(ctx()->organizacaoId());
        $competencia = Competencia::de($request->query('competencia'));
        $aba = in_array($request->query('aba'), ['resumo', 'fluxo', 'despesas'], true) ? $request->query('aba') : 'resumo';

        return view('financeiro.painel', [
            'aba' => $aba, 'competencia' => $competencia, 'p' => $this->fin->painel($competencia),
            'acoes' => $aba === 'resumo' ? $this->fin->acoesNecessarias() : [],
            'fluxo' => $aba === 'fluxo' ? $this->fin->fluxoCaixa(12) : [],
            'categorias' => $aba === 'despesas' ? $this->fin->despesasPorCategoria($competencia) : collect(),
            'favorecidos' => $aba === 'despesas' ? $this->fin->maioresFavorecidos($competencia) : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->fin->garantirPadroes(ctx()->organizacaoId());

        return view('financeiro.novo', [
            'tipo' => $request->query('tipo') === 'receber' ? 'receber' : 'pagar',
            'categorias' => CategoriaFinanceira::where('ativa', true)->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $org = ctx()->organizacaoId();
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['receber', 'pagar'])],
            'descricao' => ['required', 'string', 'max:255'],
            'categoria_id' => ['nullable', Rule::exists('categorias_financeiras', 'id')->where('organizacao_id', $org)->where('tipo', $request->input('tipo') === 'receber' ? 'receita' : 'despesa')],
            'contraparte' => ['nullable', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:60'],
            'competencia' => ['required', 'date_format:Y-m'],
            'valor' => ['required', 'string'],
            'parcelas' => ['required', 'integer', 'between:1,120'],
            'vencimento' => ['required', 'date'],
        ], ['categoria_id.exists' => 'Categoria inválida para este tipo de lançamento.']);

        $t = $this->fin->criarTitulo($org, [
            'tipo' => $d['tipo'], 'descricao' => $d['descricao'], 'categoria_id' => $d['categoria_id'] ?? null,
            'contraparte' => $d['contraparte'] ?? null, 'documento' => $d['documento'] ?? null,
            'competencia' => $d['competencia'].'-01',
        ], $this->centavos($d['valor'], 'valor'), (int) $d['parcelas'], $d['vencimento']);

        return redirect()->route('financeiro.titulo', $t)->with('ok', 'Lançamento criado.');
    }

    public function show(Request $request, Titulo $titulo): View
    {
        $aba = in_array($request->query('aba'), ['resumo', 'parcelas', 'pagamentos', 'historico'], true) ? $request->query('aba') : 'resumo';
        $titulo->load('origem', 'parcelas.fatura.cartao', 'parcelas.movimentos.estornoDe', 'parcelas.movimentos.estorno', 'parcelas.movimentos.conta',
            'parcelas.movimentos.criadoPor', 'parcelas.movimentos.linhaExtrato', 'categoria');

        $historico = Evento::with('user')->where('alvo_type', $titulo->getMorphClass())->where('alvo_id', $titulo->id)->latest('id')->get();

        return view('financeiro.titulo', [
            't' => $titulo, 'aba' => $aba, 'historico' => $historico,
            'contas' => ContaFinanceira::where('ativa', true)->orderBy('nome')->get(),
            'chave' => (string) Str::uuid(), // trava contra duplo envio do formulário de baixa
        ]);
    }

    public function baixar(Request $request, Parcela $parcela): RedirectResponse
    {
        $d = $request->validate([
            'conta_id' => ['required', 'integer'],
            'data' => ['required', 'date', 'before_or_equal:today'],
            'valor' => ['required', 'string'],
            'desconto' => ['nullable', 'string'], 'retencao' => ['nullable', 'string'], 'acrescimo' => ['nullable', 'string'],
            'observacao' => ['nullable', 'string', 'max:500'],
            'chave' => ['nullable', 'string', 'max:64'],
        ]);
        $this->fin->baixar($parcela, [
            'conta_id' => (int) $d['conta_id'], 'data' => $d['data'],
            'valor' => $this->centavos($d['valor'], 'valor', true),
            'desconto' => $this->centavos($d['desconto'] ?? '', 'desconto', true),
            'retencao' => $this->centavos($d['retencao'] ?? '', 'retencao', true),
            'acrescimo' => $this->centavos($d['acrescimo'] ?? '', 'acrescimo', true),
            'observacao' => $d['observacao'] ?? null, 'chave' => $d['chave'] ?? null,
        ]);

        return back()->with('ok', 'Baixa registrada.');
    }

    public function estornar(Request $request, Movimento $movimento): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);
        $this->fin->estornar($movimento, $d['motivo']);

        return back()->with('ok', 'Baixa estornada. O registro original foi mantido no histórico.');
    }

    public function cancelar(Request $request, Titulo $titulo): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);
        $this->fin->cancelarTitulo($titulo, $d['motivo']);

        return back()->with('ok', 'Lançamento cancelado.');
    }

    // ---------------- Contas ----------------

    public function contas(): View
    {
        $l = app(LembretesBancarios::class);
        $contas = ContaFinanceira::orderByDesc('ativa')->orderBy('nome')->get();

        return view('financeiro.contas', [
            'contas' => $contas, 'situacoes' => $contas->mapWithKeys(fn ($c) => [$c->id => $l->situacao($c)]),
            'pendentes' => ExtratoLinha::pendentes()->selectRaw('conta_id, count(*) n')->groupBy('conta_id')->pluck('n', 'conta_id'),
        ]);
    }

    public function salvarConta(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'tipo' => ['required', Rule::in(array_keys(ContaFinanceira::TIPOS))],
            'banco' => ['nullable', 'string', 'max:80'],
            'identificacao' => ['nullable', 'string', 'max:40'],
            'saldo_inicial' => ['nullable', 'string'],
            'saldo_inicial_em' => ['required', 'date'],
        ]);
        $neg = str_starts_with(trim((string) ($d['saldo_inicial'] ?? '')), '-');
        $saldo = $this->centavos(ltrim((string) ($d['saldo_inicial'] ?? ''), '- '), 'saldo_inicial', true);
        ContaFinanceira::create([
            'nome' => $d['nome'], 'tipo' => $d['tipo'], 'banco' => $d['banco'] ?? null, 'identificacao' => $d['identificacao'] ?? null,
            'saldo_inicial_centavos' => $neg ? -$saldo : $saldo, 'saldo_inicial_em' => $d['saldo_inicial_em'],
        ]);

        return back()->with('ok', 'Conta cadastrada.');
    }

    public function alternarConta(ContaFinanceira $conta): RedirectResponse
    {
        $conta->update(['ativa' => ! $conta->ativa]);

        return back()->with('ok', $conta->ativa ? 'Conta reativada.' : 'Conta desativada. O histórico continua disponível.');
    }

    // ---------------- Conta: detalhe, lembrete e dispensa ----------------

    public function conta(Request $request, ContaFinanceira $conta, LembretesBancarios $l): View
    {
        $aba = in_array($request->query('aba'), ['resumo', 'lembrete', 'importacoes'], true) ? $request->query('aba') : 'resumo';

        return view('financeiro.conta', [
            'conta' => $conta->load('responsavel'), 'aba' => $aba, 's' => $l->situacao($conta), 'saldo' => $conta->saldo(),
            'importacoes' => $conta->importacoes()->with('enviadoPor')->limit(50)->get(),
            'responsaveis' => User::whereIn('id', Vinculo::vigentes()->where('organizacao_id', ctx()->organizacaoId())
                ->whereIn('papel', [Papel::Admin, Papel::Financeiro])->pluck('user_id'))->orderBy('name')->get(),
        ]);
    }

    public function salvarLembrete(Request $request, ContaFinanceira $conta): RedirectResponse
    {
        $d = $request->validate([
            'lembrete_ativo' => ['nullable', 'boolean'],
            'lembrete_frequencia' => ['required', Rule::in(array_keys(ContaFinanceira::FREQUENCIAS))],
            'lembrete_dia' => ['required', 'integer', $request->input('lembrete_frequencia') === 'mensal' ? 'between:1,28' : 'between:1,7'],
            'lembrete_hora' => ['required', 'date_format:H:i'],
            'lembrete_antecedencia' => ['required', 'integer', 'between:0,10'],
            'lembrete_repetir_dias' => ['required', 'integer', 'between:1,14'],
            'lembrete_responsavel_id' => ['nullable', Rule::exists('vinculos', 'user_id')->where('organizacao_id', ctx()->organizacaoId())],
        ], ['lembrete_dia.between' => 'Para frequência mensal use um dia de 1 a 28; para semanal/quinzenal, o dia da semana.']);
        $d['lembrete_ativo'] = $request->boolean('lembrete_ativo');
        $conta->update($d);
        Evento::registrar('financeiro.lembrete_configurado', $conta, ['ativo' => $d['lembrete_ativo'] ? 'sim' : 'não', 'regra' => $conta->regraLembrete()]);

        return back()->with('ok', 'Lembrete salvo: '.($d['lembrete_ativo'] ? $conta->regraLembrete().'.' : 'desativado.'));
    }

    public function dispensarLembrete(Request $request, ContaFinanceira $conta, LembretesBancarios $l): RedirectResponse
    {
        if ($request->boolean('adiar')) {
            $l->dispensar($conta, CarbonImmutable::now()->addDays(3), 'Adiado por 3 dias');

            return back()->with('ok', 'Lembrete adiado por 3 dias.');
        }
        $d = $request->validate(['ate' => ['required', 'date', 'after_or_equal:today', 'before:+90 days'], 'motivo' => ['required', 'string', 'max:500']],
            ['ate.before' => 'A dispensa pode durar no máximo 90 dias.']);
        $l->dispensar($conta, CarbonImmutable::parse($d['ate']), $d['motivo']);

        return back()->with('ok', 'Lembrete dispensado até '.CarbonImmutable::parse($d['ate'])->format('d/m/Y').'. Registrado na auditoria.');
    }

    // ---------------- Conciliação ----------------

    public function conciliacao(Request $request, ContaFinanceira $conta): View
    {
        $mostrar = $request->query('mostrar') === 'todas' ? 'todas' : 'pendentes';
        $linhas = ExtratoLinha::with('movimento.parcela.titulo', 'transferencia.origem', 'transferencia.destino')->where('conta_id', $conta->id)
            ->when($mostrar === 'pendentes', fn ($q) => $q->pendentes())
            ->orderByDesc('data')->orderByDesc('id')->limit(300)->get();

        return view('financeiro.conciliacao', [
            'conta' => $conta, 'linhas' => $linhas, 'mostrar' => $mostrar,
            'sugestoes' => $linhas->reject->conciliada()->mapWithKeys(fn ($l) => [$l->id => $this->fin->sugestoes($l)]),
            'sugestoesTransf' => $linhas->reject->conciliada()->mapWithKeys(fn ($l) => [$l->id => $this->fin->sugestoesTransferencia($l)]),
            'saldo' => $conta->saldo(),
        ]);
    }

    public function importar(Request $request, ContaFinanceira $conta, ImportadorExtrato $importador): RedirectResponse
    {
        // OFX não tem tipo MIME padrão: valida pela extensão; o conteúdo é validado pelo importador.
        $request->validate(['arquivo' => ['required', 'file', 'max:5120', 'extensions:ofx,qfx,csv,txt']],
            ['arquivo.extensions' => 'Envie o extrato em OFX ou CSV.']);
        $arq = $request->file('arquivo');
        $r = $importador->importar($conta, $arq->get(), $arq->getClientOriginalName());
        $periodo = $r['fim'] ? ' Período coberto: '.CarbonImmutable::parse($r['inicio'])->format('d/m/Y').' a '.CarbonImmutable::parse($r['fim'])->format('d/m/Y').'.' : '';

        return back()->with('ok', "Extrato importado: {$r['novas']} lançamento(s) novo(s)".($r['repetidas'] ? ", {$r['repetidas']} já existiam e foram ignorados" : '').'.'.$periodo);
    }

    public function conciliar(Request $request, ExtratoLinha $linha): RedirectResponse
    {
        $d = $request->validate(['movimento_id' => ['required', 'integer']]);
        $mov = Movimento::find($d['movimento_id']);
        if (! $mov) {
            throw ValidationException::withMessages(['movimento_id' => 'Movimento não encontrado.']);
        }
        $this->fin->conciliar($linha, $mov);

        return back()->with('ok', 'Conciliado.');
    }

    public function conciliarTransferencia(Request $request, ExtratoLinha $linha): RedirectResponse
    {
        $d = $request->validate(['transferencia_id' => ['required', 'integer']]);
        $t = Transferencia::find($d['transferencia_id']) ?? throw ValidationException::withMessages(['transferencia_id' => 'Transferência não encontrada.']);
        $this->fin->conciliarTransferencia($linha, $t);

        return back()->with('ok', 'Conciliado com a transferência.');
    }

    public function desconciliar(ExtratoLinha $linha): RedirectResponse
    {
        $this->fin->desconciliar($linha);

        return back()->with('ok', 'Conciliação desfeita.');
    }

    public function ignorar(ExtratoLinha $linha): RedirectResponse
    {
        abort_if($linha->conciliada(), 422, 'Linha conciliada.');
        $linha->update(['ignorada' => ! $linha->ignorada]);

        return back()->with('ok', $linha->ignorada ? 'Linha marcada como sem lançamento correspondente.' : 'Linha voltou para pendentes.');
    }

    // ---------------- Categorias ----------------

    public function categorias(): View
    {
        $this->fin->garantirPadroes(ctx()->organizacaoId());

        return view('financeiro.categorias', ['categorias' => CategoriaFinanceira::orderBy('tipo')->orderBy('nome')->get()]);
    }

    public function salvarCategoria(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['receita', 'despesa'])],
            'nome' => ['required', 'string', 'max:80',
                Rule::unique('categorias_financeiras')->where('organizacao_id', ctx()->organizacaoId())->where('tipo', $request->input('tipo'))],
        ]);
        CategoriaFinanceira::create($d);

        return back()->with('ok', 'Categoria criada.');
    }

    public function alternarCategoria(CategoriaFinanceira $categoria): RedirectResponse
    {
        $categoria->update(['ativa' => ! $categoria->ativa]);

        return back()->with('ok', 'Categoria atualizada. Lançamentos antigos mantêm a categoria.');
    }

    private function centavos(?string $v, string $campo, bool $zeroOk = false): int
    {
        if ($zeroOk && trim((string) $v) === '') {
            return 0;
        }
        try {
            return Dinheiro::paraCentavos((string) $v);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$campo => 'Valor inválido. Use, por exemplo, 1.500,00.']);
        }
    }
}
