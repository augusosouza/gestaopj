@extends('layouts.app')
@section('titulo', 'Nova compra')
@section('conteudo')
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Compras' => route('compras.index'), 'Nova compra' => null]" titulo="Nova compra"
  sub="A compra gera as parcelas a pagar. No cartão, cada parcela entra na fatura do mês certo." />
@if ($pre)<div class="aviso info">Formulário preparado pelo assistente. Confira os dados antes de salvar — nada foi gravado ainda.</div>@endif
<form method="post" action="{{ route('compras.store') }}" class="painel" style="max-width:900px" id="form-compra">
  @csrf
  <div class="grade">
    <div class="campo" style="grid-column:span 2"><label for="descricao">Descrição</label><input id="descricao" name="descricao" value="{{ old('descricao', $pre['descricao'] ?? '') }}" required placeholder="Ex.: Notebook para o time de suporte"></div>
    <div class="campo"><label for="data_compra">Data da compra</label><input id="data_compra" type="date" name="data_compra" value="{{ old('data_compra', now()->toDateString()) }}" required></div>
  </div>
  <div class="grade">
    <div class="campo"><label for="fornecedor">Fornecedor</label><input id="fornecedor" name="fornecedor" value="{{ old('fornecedor') }}"></div>
    <div class="campo"><label for="fornecedor_documento">CNPJ/CPF do fornecedor</label><input id="fornecedor_documento" name="fornecedor_documento" value="{{ old('fornecedor_documento') }}"></div>
    @if ($centros->isNotEmpty())<div class="campo"><label for="centro_custo_id">Centro de custo</label><select id="centro_custo_id" name="centro_custo_id"><option value="">—</option>
      @foreach ($centros as $c)<option value="{{ $c->id }}" @selected(old('centro_custo_id') == $c->id)>{{ $c->rotulo() }}</option>@endforeach</select></div>@endif
    <div class="campo"><label for="categoria_id">Categoria</label><select id="categoria_id" name="categoria_id"><option value="">—</option>
      @foreach ($categorias as $c)<option value="{{ $c->id }}" @selected(old('categoria_id') == $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
  </div>

  <fieldset class="bloco"><legend>Valores</legend>
    <details @if (old('itens')) open @endif class="itens-compra"><summary class="link-sum">Detalhar itens (opcional) — o valor passa a ser a soma dos itens</summary>
      <table class="itens"><thead><tr><th>Item</th><th style="width:90px">Qtd.</th><th style="width:140px">Valor unitário</th></tr></thead><tbody>
      @for ($i = 0; $i < 5; $i++)
        <tr><td><input name="itens[{{ $i }}][descricao]" value="{{ old("itens.$i.descricao") }}" aria-label="Item {{ $i + 1 }}"></td>
          <td><input name="itens[{{ $i }}][quantidade]" value="{{ old("itens.$i.quantidade", 1) }}" inputmode="decimal" aria-label="Quantidade {{ $i + 1 }}" data-calc></td>
          <td><input name="itens[{{ $i }}][unitario]" value="{{ old("itens.$i.unitario") }}" inputmode="decimal" placeholder="0,00" aria-label="Valor unitário {{ $i + 1 }}" data-calc></td></tr>
      @endfor
      </tbody></table>
    </details>
    <div class="grade">
      <div class="campo"><label for="valor_bruto">Valor da compra (R$)</label><input id="valor_bruto" name="valor_bruto" inputmode="decimal" value="{{ old('valor_bruto', $pre['valor'] ?? '') }}" placeholder="4.500,00" data-calc></div>
      <div class="campo"><label for="desconto">Desconto</label><input id="desconto" name="desconto" inputmode="decimal" value="{{ old('desconto') }}" placeholder="0,00" data-calc></div>
      <div class="campo"><label for="juros">Juros do parcelamento</label><input id="juros" name="juros" inputmode="decimal" value="{{ old('juros') }}" placeholder="0,00" data-calc></div>
      <div class="campo"><label>Total</label><div class="total-calc" id="total-calc">—</div></div>
    </div>
  </fieldset>

  <fieldset class="bloco"><legend>Pagamento</legend>
    <div class="grade">
      <div class="campo"><label for="modalidade">Forma</label><select id="modalidade" name="modalidade" required>
        @foreach (\App\Models\Compra::MODALIDADES as $k => $v)<option value="{{ $k }}" @selected(old('modalidade', $pre['modalidade'] ?? 'boleto') === $k)>{{ $v }}</option>@endforeach</select></div>
      <div class="campo" data-so="cartao"><label for="cartao_id">Cartão</label><select id="cartao_id" name="cartao_id"><option value="">—</option>
        @foreach ($cartoes as $c)<option value="{{ $c->id }}" @selected(old('cartao_id') == $c->id)>{{ $c->rotulo() }} (fecha dia {{ $c->dia_fechamento }})</option>@endforeach</select>
        @if ($cartoes->isEmpty())<span class="sub" style="font-size:12.5px"><a href="{{ route('cartoes.index') }}">Cadastre um cartão</a> primeiro.</span>@endif</div>
      <div class="campo"><label for="parcelas">Parcelas</label><input id="parcelas" type="number" name="parcelas" min="1" max="120" value="{{ old('parcelas', $pre['parcelas'] ?? 1) }}" required data-calc></div>
      <div class="campo" data-nao="cartao"><label for="entrada">Entrada (opcional)</label><input id="entrada" name="entrada" inputmode="decimal" value="{{ old('entrada') }}" placeholder="0,00" data-calc></div>
      <div class="campo" data-nao="cartao"><label for="primeiro_vencimento">1º vencimento</label><input id="primeiro_vencimento" type="date" name="primeiro_vencimento" value="{{ old('primeiro_vencimento', now()->addMonth()->toDateString()) }}"></div>
    </div>
    <div class="grade" data-nao="cartao">
      <div class="campo"><label for="conta_id">Conta de pagamento</label><select id="conta_id" name="conta_id"><option value="">—</option>
        @foreach ($contas as $c)<option value="{{ $c->id }}" @selected(old('conta_id') == $c->id)>{{ $c->nome }}</option>@endforeach</select></div>
      <div class="campo" style="align-self:end"><label class="marcar"><input type="checkbox" name="quitar_primeira" value="1" @checked(old('quitar_primeira'))> Entrada (ou pagamento à vista) já foi paga na data da compra</label></div>
    </div>
    <p class="sub" style="font-size:13px;margin:0" id="resumo-parcelas"></p>
  </fieldset>

  <fieldset class="bloco"><legend>Documento e garantia</legend>
    <div class="grade">
      <div class="campo"><label for="nota_tipo">Tipo de documento</label><select id="nota_tipo" name="nota_tipo"><option value="">—</option>
        @foreach (['NF-e', 'NFS-e', 'NFC-e', 'Recibo', 'Outro'] as $t)<option @selected(old('nota_tipo') === $t)>{{ $t }}</option>@endforeach</select></div>
      <div class="campo"><label for="nota_numero">Número</label><input id="nota_numero" name="nota_numero" value="{{ old('nota_numero') }}"></div>
      <div class="campo"><label for="nota_chave">Chave de acesso</label><input id="nota_chave" name="nota_chave" value="{{ old('nota_chave') }}" maxlength="50"></div>
      <div class="campo"><label for="garantia_ate">Garantia até</label><input id="garantia_ate" type="date" name="garantia_ate" value="{{ old('garantia_ate') }}"></div>
    </div>
    <div class="campo"><label for="observacao">Observação</label><textarea id="observacao" name="observacao" rows="2">{{ old('observacao') }}</textarea></div>
  </fieldset>
  <button>Registrar compra</button>
</form>
<script>
  (function () {
    var f = document.getElementById('form-compra'), mod = document.getElementById('modalidade');
    function c(v) { v = (v || '').replace(/[R$\s]/g, ''); if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.'); var n = Math.round(parseFloat(v) * 100); return isNaN(n) ? 0 : n; }
    function brl(n) { return (n < 0 ? '−' : '') + 'R$ ' + (Math.abs(n) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2 }); }
    function atualizar() {
      var cartao = mod.value === 'cartao';
      f.querySelectorAll('[data-so=cartao]').forEach(function (e) { e.hidden = !cartao; });
      f.querySelectorAll('[data-nao=cartao]').forEach(function (e) { e.hidden = cartao; });
      var itens = 0, temItens = false;
      for (var i = 0; i < 5; i++) {
        var d = f.querySelector('[name="itens[' + i + '][descricao]"]').value.trim();
        if (!d) continue; temItens = true;
        var q = parseFloat((f.querySelector('[name="itens[' + i + '][quantidade]"]').value || '1').replace(',', '.')) || 0;
        itens += Math.round(q * c(f.querySelector('[name="itens[' + i + '][unitario]"]').value));
      }
      var bruto = temItens ? itens : c(f.valor_bruto.value);
      f.valor_bruto.readOnly = temItens; if (temItens) f.valor_bruto.value = (itens / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
      var total = bruto - c(f.desconto.value) + c(f.juros.value), n = parseInt(f.parcelas.value, 10) || 1, ent = cartao ? 0 : c(f.entrada.value);
      document.getElementById('total-calc').textContent = total > 0 ? brl(total) : '—';
      var base = Math.floor((total - ent) / n);
      document.getElementById('resumo-parcelas').textContent = total > 0
        ? (ent ? 'Entrada de ' + brl(ent) + ' + ' : '') + n + 'x de ' + brl(base) + (cartao ? ', nas faturas do cartão.' : '.') : '';
    }
    f.addEventListener('input', atualizar); mod.addEventListener('change', atualizar); atualizar();
  })();
</script>
@endsection
