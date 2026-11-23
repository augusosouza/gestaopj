@extends('layouts.app')
@section('titulo', $tipo === 'receber' ? 'Nova receita' : 'Nova despesa')
@section('conteudo')
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), ($tipo === 'receber' ? 'Contas a receber' : 'Contas a pagar') => route('financeiro.index', ['tipo' => $tipo]), 'Novo lançamento' => null]"
  :titulo="$tipo === 'receber' ? 'Nova receita' : 'Nova despesa'" />
<x-abas :itens="[['Despesa', route('financeiro.novo', ['tipo' => 'pagar']), $tipo === 'pagar'], ['Receita', route('financeiro.novo', ['tipo' => 'receber']), $tipo === 'receber']]" />
<p class="sub">Faturas de clientes e pagamentos de prestadores entram sozinhos ao gerar a competência. Use aqui para o restante (aluguel, impostos, software, outras receitas…).</p>
<form method="post" action="{{ route('financeiro.store') }}" class="painel" style="max-width:760px">
  @csrf <input type="hidden" name="tipo" value="{{ $tipo }}">
  <div class="campo"><label for="descricao">Descrição</label><input id="descricao" name="descricao" value="{{ old('descricao') }}" required placeholder="{{ $tipo === 'receber' ? 'Ex.: Consultoria avulsa' : 'Ex.: Aluguel do escritório' }}"></div>
  <div class="grade">
    <div class="campo"><label for="categoria_id">Categoria</label><select id="categoria_id" name="categoria_id">
      <option value="">—</option>
      @foreach ($categorias->where('tipo', $tipo === 'receber' ? 'receita' : 'despesa') as $c)<option value="{{ $c->id }}" @selected(old('categoria_id') == $c->id)>{{ $c->nome }}</option>@endforeach
    </select></div>
    <div class="campo"><label for="contraparte">{{ $tipo === 'receber' ? 'Pagador' : 'Fornecedor / favorecido' }}</label><input id="contraparte" name="contraparte" value="{{ old('contraparte') }}"></div>
    <div class="campo"><label for="documento">Documento (opcional)</label><input id="documento" name="documento" value="{{ old('documento') }}" placeholder="Nº da nota, boleto…"></div>
  </div>
  <div class="grade">
    <div class="campo"><label for="competencia">Competência</label><input id="competencia" type="month" name="competencia" value="{{ old('competencia', now()->format('Y-m')) }}" required></div>
    <div class="campo"><label for="valor">Valor total (R$)</label><input id="valor" name="valor" value="{{ old('valor') }}" inputmode="decimal" required placeholder="3.400,00"></div>
    <div class="campo"><label for="parcelas">Parcelas</label><input id="parcelas" type="number" name="parcelas" min="1" max="120" value="{{ old('parcelas', 1) }}" required></div>
    <div class="campo"><label for="vencimento">1º vencimento</label><input id="vencimento" type="date" name="vencimento" value="{{ old('vencimento', now()->toDateString()) }}" required></div>
  </div>
  <p class="sub" style="font-size:13px">Parcelas mensais de mesmo valor; centavos da divisão vão para a última. <strong>Competência</strong> é o mês a que a receita/despesa pertence; o <strong>vencimento</strong> é quando deve ser paga.</p>
  <button>Salvar</button>
</form>
@endsection
