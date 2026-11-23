<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LeValores;
use App\Models\Cartao;
use App\Models\CategoriaFinanceira;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Services\Compras;
use App\Services\Financeiro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** FIN-04 — compras, parcelamentos e documentos. */
class CompraController extends Controller
{
    use LeValores;

    public function __construct(private Compras $compras, private Financeiro $fin) {}

    public function index(Request $request): View
    {
        $aba = in_array($request->query('aba'), ['resumo', 'todas', 'parcelamentos'], true) ? $request->query('aba') : 'resumo';
        $busca = trim((string) $request->query('q'));
        $todas = Compra::with('titulo.parcelas.movimentos.estornoDe', 'categoria', 'cartao')
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('descricao', 'like', "%{$busca}%")->orWhere('fornecedor', 'like', "%{$busca}%")->orWhere('nota_numero', 'like', "%{$busca}%")))
            ->orderByDesc('data_compra')->orderByDesc('id')->limit(500)->get();
        $ativas = $todas->whereNull('cancelada_em');
        $parceladas = $ativas->filter(fn ($c) => $c->titulo && $c->titulo->parcelas->count() > 1)->values();
        $mes = now()->startOfMonth();

        return view('compras.index', [
            'aba' => $aba, 'busca' => $busca, 'compras' => $todas, 'parceladas' => $parceladas,
            'resumo' => [
                'mes' => (int) $ativas->filter(fn ($c) => $c->data_compra->gte($mes))->sum('total_centavos'),
                'qtd_mes' => $ativas->filter(fn ($c) => $c->data_compra->gte($mes))->count(),
                'restante' => (int) $ativas->sum(fn ($c) => $c->titulo?->saldo() ?? 0),
                'pago' => (int) $ativas->sum(fn ($c) => $c->titulo?->liquidado() ?? 0),
                'parceladas' => $parceladas->filter(fn ($c) => $c->titulo->saldo() > 0)->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->fin->garantirPadroes(ctx()->organizacaoId());

        return view('compras.nova', [
            'categorias' => CategoriaFinanceira::where('ativa', true)->where('tipo', 'despesa')->orderBy('nome')->get(),
            'contas' => ContaFinanceira::where('ativa', true)->orderBy('nome')->get(),
            'cartoes' => Cartao::where('ativo', true)->orderBy('nome')->get(),
            // Pré-preenchimento vindo do assistente (nada é gravado até confirmar).
            'pre' => $request->only(['valor', 'parcelas', 'modalidade', 'descricao']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $org = ctx()->organizacaoId();
        $d = $request->validate([
            'descricao' => ['required', 'string', 'max:255'],
            'fornecedor' => ['nullable', 'string', 'max:255'],
            'fornecedor_documento' => ['nullable', 'string', 'max:18'],
            'data_compra' => ['required', 'date'],
            'valor_bruto' => ['nullable', 'string'],
            'desconto' => ['nullable', 'string'], 'juros' => ['nullable', 'string'], 'entrada' => ['nullable', 'string'],
            'categoria_id' => ['nullable', Rule::exists('categorias_financeiras', 'id')->where('organizacao_id', $org)->where('tipo', 'despesa')],
            'modalidade' => ['required', Rule::in(array_keys(Compra::MODALIDADES))],
            'conta_id' => ['nullable', Rule::exists('contas_financeiras', 'id')->where('organizacao_id', $org)],
            'cartao_id' => ['nullable', Rule::exists('cartoes', 'id')->where('organizacao_id', $org)],
            'parcelas' => ['required', 'integer', 'between:1,120'],
            'primeiro_vencimento' => ['nullable', 'date'],
            'quitar_primeira' => ['nullable', 'boolean'],
            'nota_tipo' => ['nullable', 'string', 'max:20'], 'nota_numero' => ['nullable', 'string', 'max:30'], 'nota_chave' => ['nullable', 'string', 'max:50'],
            'garantia_ate' => ['nullable', 'date'], 'observacao' => ['nullable', 'string', 'max:2000'],
            'itens' => ['nullable', 'array', 'max:50'],
            'itens.*.descricao' => ['nullable', 'string', 'max:255'],
            'itens.*.quantidade' => ['nullable', 'regex:/^\d+([.,]\d{1,3})?$/'],
            'itens.*.unitario' => ['nullable', 'string'],
        ], ['categoria_id.exists' => 'Escolha uma categoria de despesa.', 'itens.*.quantidade.regex' => 'Quantidade inválida.']);

        $itens = [];
        foreach ($d['itens'] ?? [] as $k => $i) {
            if (trim((string) ($i['descricao'] ?? '')) === '') {
                continue;
            }
            $itens[] = ['descricao' => $i['descricao'], 'quantidade' => $i['quantidade'] ?? '1', 'unitario' => $this->centavos($i['unitario'] ?? '', "itens.{$k}.unitario")];
        }
        $compra = $this->compras->registrar($org, [
            'descricao' => $d['descricao'], 'fornecedor' => $d['fornecedor'] ?? null,
            'fornecedor_documento' => isset($d['fornecedor_documento']) ? preg_replace('/[^0-9A-Z]/', '', strtoupper($d['fornecedor_documento'])) ?: null : null,
            'data_compra' => $d['data_compra'], 'valor_bruto' => $itens ? 0 : $this->centavos($d['valor_bruto'] ?? '', 'valor_bruto', true),
            'desconto' => $this->centavos($d['desconto'] ?? '', 'desconto', true), 'juros' => $this->centavos($d['juros'] ?? '', 'juros', true),
            'entrada' => $this->centavos($d['entrada'] ?? '', 'entrada', true), 'categoria_id' => $d['categoria_id'] ?? null,
            'modalidade' => $d['modalidade'], 'conta_id' => $d['conta_id'] ?? null, 'cartao_id' => $d['cartao_id'] ?? null,
            'parcelas' => (int) $d['parcelas'], 'primeiro_vencimento' => $d['primeiro_vencimento'] ?? null, 'quitar_primeira' => $request->boolean('quitar_primeira'),
            'nota_tipo' => $d['nota_tipo'] ?? null, 'nota_numero' => $d['nota_numero'] ?? null, 'nota_chave' => $d['nota_chave'] ?? null,
            'garantia_ate' => $d['garantia_ate'] ?? null, 'observacao' => $d['observacao'] ?? null, 'itens' => $itens,
        ]);

        return redirect()->route('compras.show', $compra)->with('ok', 'Compra registrada com '.$compra->titulo->parcelas()->count().' parcela(s).');
    }

    public function show(Request $request, Compra $compra): View
    {
        $aba = in_array($request->query('aba'), ['informacoes', 'itens', 'parcelas', 'pagamentos', 'historico'], true) ? $request->query('aba') : 'informacoes';
        $compra->load('itens', 'categoria', 'cartao', 'conta', 'titulo.parcelas.movimentos.estornoDe', 'titulo.parcelas.movimentos.estorno',
            'titulo.parcelas.movimentos.conta', 'titulo.parcelas.fatura');
        $t = $compra->titulo;
        $historico = Evento::with('user')->where(fn ($q) => $q->where('alvo_type', $compra->getMorphClass())->where('alvo_id', $compra->id))
            ->orWhere(fn ($q) => $q->where('alvo_type', $t->getMorphClass())->where('alvo_id', $t->id))->latest('id')->get();

        return view('compras.show', ['c' => $compra, 't' => $t, 'aba' => $aba, 'historico' => $historico]);
    }

    public function cancelar(Request $request, Compra $compra): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);
        $this->compras->cancelar($compra, $d['motivo']);

        return back()->with('ok', 'Compra cancelada. O registro continua no histórico.');
    }
}
