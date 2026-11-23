<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Http\Controllers\Concerns\LeValores;
use App\Models\Cartao;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\Evento;
use App\Models\FaturaCartao;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Compras;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** FIN-05 — cartões corporativos e faturas. */
class CartaoController extends Controller
{
    use LeValores;

    public function __construct(private Compras $compras) {}

    public function index(): View
    {
        $cartoes = Cartao::with('contaPagamento', 'responsavel')->orderByDesc('ativo')->orderBy('nome')->get();
        $hoje = CarbonImmutable::today();
        $faturas = FaturaCartao::with('cartao', 'parcelas.movimentos.estornoDe')->whereIn('cartao_id', $cartoes->pluck('id'))
            ->whereDate('vencimento', '>=', $hoje->subMonths(3))->orderBy('vencimento')->get()->filter(fn ($f) => $f->total() > 0);

        return view('cartoes.index', [
            'cartoes' => $cartoes, 'faturas' => $faturas,
            'emAberto' => $cartoes->mapWithKeys(fn ($c) => [$c->id => $c->emAberto()]),
            'contas' => ContaFinanceira::where('ativa', true)->orderBy('nome')->get(),
            'responsaveis' => $this->responsaveis(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $org = ctx()->organizacaoId();
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:80'], 'instituicao' => ['nullable', 'string', 'max:80'],
            'final' => ['nullable', 'digits:4'], 'limite' => ['nullable', 'string'],
            'dia_fechamento' => ['required', 'integer', 'between:1,28'], 'dia_vencimento' => ['required', 'integer', 'between:1,28', 'different:dia_fechamento'],
            'conta_pagamento_id' => ['nullable', Rule::exists('contas_financeiras', 'id')->where('organizacao_id', $org)],
            'responsavel_id' => ['nullable', Rule::exists('vinculos', 'user_id')->where('organizacao_id', $org)],
        ], ['final.digits' => 'Informe apenas os 4 últimos dígitos.', 'dia_vencimento.different' => 'Fechamento e vencimento precisam ser em dias diferentes.']);
        $d['limite_centavos'] = $this->centavos($d['limite'] ?? '', 'limite', true);
        unset($d['limite']);
        $c = Cartao::create($d);
        Evento::registrar('financeiro.cartao_cadastrado', $c, ['nome' => $c->nome]);

        return redirect()->route('cartoes.show', $c)->with('ok', 'Cartão cadastrado.');
    }

    public function show(Request $request, Cartao $cartao): View
    {
        $aba = in_array($request->query('aba'), ['faturas', 'compras', 'dados'], true) ? $request->query('aba') : 'faturas';
        $faturas = $cartao->faturas()->with('parcelas.movimentos.estornoDe')->get()->filter(fn ($f) => $f->total() > 0 || $f->paga_em);

        return view('cartoes.show', [
            'cartao' => $cartao->load('contaPagamento', 'responsavel'), 'aba' => $aba, 'faturas' => $faturas, 'emAberto' => $cartao->emAberto(),
            'compras' => $aba === 'compras' ? Compra::with('titulo.parcelas.movimentos.estornoDe')->where('cartao_id', $cartao->id)->orderByDesc('data_compra')->get() : collect(),
            'contas' => ContaFinanceira::where('ativa', true)->orderBy('nome')->get(), 'responsaveis' => $this->responsaveis(),
        ]);
    }

    public function atualizar(Request $request, Cartao $cartao): RedirectResponse
    {
        $org = ctx()->organizacaoId();
        if ($request->has('alternar')) {
            $cartao->update(['ativo' => ! $cartao->ativo]);

            return back()->with('ok', $cartao->ativo ? 'Cartão reativado.' : 'Cartão desativado. Faturas e compras continuam no histórico.');
        }
        $d = $request->validate([
            'limite' => ['nullable', 'string'], 'responsavel_id' => ['nullable', Rule::exists('vinculos', 'user_id')->where('organizacao_id', $org)],
            'conta_pagamento_id' => ['nullable', Rule::exists('contas_financeiras', 'id')->where('organizacao_id', $org)],
        ]);
        $cartao->update(['limite_centavos' => $this->centavos($d['limite'] ?? '', 'limite', true), 'responsavel_id' => $d['responsavel_id'] ?? null,
            'conta_pagamento_id' => $d['conta_pagamento_id'] ?? null]);
        Evento::registrar('financeiro.cartao_atualizado', $cartao, ['limite' => $cartao->limite_centavos]);

        return back()->with('ok', 'Cartão atualizado. Dia de fechamento e vencimento não mudam para não alterar faturas já formadas.');
    }

    public function fatura(FaturaCartao $faturaCartao): View
    {
        $f = $faturaCartao->load('cartao.contaPagamento', 'parcelas.titulo.origem', 'parcelas.movimentos.estornoDe', 'parcelas.movimentos.conta', 'encargos');
        $anterior = FaturaCartao::where('cartao_id', $f->cartao_id)->whereDate('referencia', '<', $f->referencia)->orderByDesc('referencia')->first();
        $seguinte = FaturaCartao::where('cartao_id', $f->cartao_id)->whereDate('referencia', '>', $f->referencia)->orderBy('referencia')->first();

        return view('cartoes.fatura', ['f' => $f, 'anterior' => $anterior, 'seguinte' => $seguinte,
            'contas' => ContaFinanceira::where('ativa', true)->orderBy('nome')->get(), 'chave' => (string) Str::uuid()]);
    }

    public function pagar(Request $request, FaturaCartao $faturaCartao): RedirectResponse
    {
        $d = $request->validate(['conta_id' => ['required', 'integer'], 'data' => ['required', 'date', 'before_or_equal:today'],
            'encargos' => ['nullable', 'string'], 'chave' => ['nullable', 'string', 'max:64']]);
        $this->compras->pagarFatura($faturaCartao, (int) $d['conta_id'], $d['data'], $this->centavos($d['encargos'] ?? '', 'encargos', true), $d['chave'] ?? null);

        return back()->with('ok', 'Fatura paga. As compras foram baixadas pela fatura, sem duplicar despesas.');
    }

    private function responsaveis()
    {
        return User::whereIn('id', Vinculo::vigentes()->where('organizacao_id', ctx()->organizacaoId())
            ->whereIn('papel', [Papel::Admin, Papel::Financeiro])->pluck('user_id'))->orderBy('name')->get();
    }
}
