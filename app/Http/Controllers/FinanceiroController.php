<?php

namespace App\Http\Controllers;

use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\ExtratoLinha;
use App\Models\Movimento;
use App\Models\Parcela;
use App\Models\Titulo;
use App\Services\Financeiro;
use App\Services\ImportadorExtrato;
use App\Support\Competencia;
use App\Support\Dinheiro;
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

        $titulos = Titulo::with('parcelas.movimentos.estornoDe', 'categoria')
            ->whereDate('competencia', $competencia)
            ->when($tipo, fn ($q) => $q->where('tipo', $tipo))
            ->orderBy('tipo')->orderBy('id')->get()
            ->filter(fn (Titulo $t) => match ($situacao) {
                null => true,
                'vencido' => $t->vencido(),
                default => $t->situacao() === $situacao,
            })->values();

        return view('financeiro.index', [
            'titulos' => $titulos, 'competencia' => $competencia, 'tipo' => $tipo, 'situacao' => $situacao,
            'p' => $this->fin->painel($competencia),
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

    public function show(Titulo $titulo): View
    {
        $titulo->load('parcelas.movimentos.estornoDe', 'parcelas.movimentos.estorno', 'parcelas.movimentos.conta',
            'parcelas.movimentos.criadoPor', 'parcelas.movimentos.linhaExtrato', 'categoria');

        return view('financeiro.titulo', [
            't' => $titulo,
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
        return view('financeiro.contas', [
            'contas' => ContaFinanceira::orderByDesc('ativa')->orderBy('nome')->get(),
            'pendentes' => ExtratoLinha::whereNull('movimento_id')->where('ignorada', false)->selectRaw('conta_id, count(*) n')->groupBy('conta_id')->pluck('n', 'conta_id'),
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

    // ---------------- Conciliação ----------------

    public function conciliacao(Request $request, ContaFinanceira $conta): View
    {
        $mostrar = $request->query('mostrar') === 'todas' ? 'todas' : 'pendentes';
        $linhas = ExtratoLinha::with('movimento.parcela.titulo')->where('conta_id', $conta->id)
            ->when($mostrar === 'pendentes', fn ($q) => $q->whereNull('movimento_id')->where('ignorada', false))
            ->orderByDesc('data')->orderByDesc('id')->limit(300)->get();

        return view('financeiro.conciliacao', [
            'conta' => $conta, 'linhas' => $linhas, 'mostrar' => $mostrar,
            'sugestoes' => $linhas->whereNull('movimento_id')->mapWithKeys(fn ($l) => [$l->id => $this->fin->sugestoes($l)]),
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

        return back()->with('ok', "Extrato importado: {$r['novas']} lançamento(s) novo(s)".($r['repetidas'] ? ", {$r['repetidas']} já existiam e foram ignorados" : '').'.');
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

    public function desconciliar(ExtratoLinha $linha): RedirectResponse
    {
        $this->fin->desconciliar($linha);

        return back()->with('ok', 'Conciliação desfeita.');
    }

    public function ignorar(ExtratoLinha $linha): RedirectResponse
    {
        abort_if($linha->movimento_id !== null, 422, 'Linha conciliada.');
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
