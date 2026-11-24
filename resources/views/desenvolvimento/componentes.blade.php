@extends('layouts.app')
@section('titulo', 'Componentes Augi')
@section('conteudo')
<x-cabecalho titulo="Componentes Augi" sub="Amostras para conferir espaçamento, foco e contraste." />
<x-abas :itens="[['Controles', '#controles', true], ['Dados', '#dados', false], ['Estados', '#estados', false]]" />
<div class="ficha-secoes">
  <section class="painel" id="controles">
    <h2>Controles</h2>
    <div class="acoes">
      <button type="button">Primário</button>
      <button type="button" class="sec">Secundário</button>
      <a class="botao terciario" href="#dados">Discreto</a>
      <button type="button" class="perigo">Destrutivo</button>
      <button type="button" disabled>Salvando…</button>
    </div>
    <div class="grade">
      <div class="campo"><label for="exemplo-email">E-mail</label><input id="exemplo-email" type="email" aria-invalid="true" aria-describedby="exemplo-erro"><p id="exemplo-erro" class="campo-erro">Informe um e-mail válido.</p></div>
      <div class="campo"><label for="exemplo-select">Situação</label><select id="exemplo-select"><option>Em aberto</option><option>Liquidada</option></select></div>
    </div>
  </section>
  <section class="painel" id="dados">
    <h2>Dados</h2>
    <div class="cards"><div class="card"><span class="rot">A receber</span><span class="num">R$ 1.400,00</span></div></div>
    <div class="lista-barra">
      <button class="sec" type="button" data-densidade="amostra" aria-controls="exemplo-tabela" aria-pressed="false" hidden>Modo compacto</button>
    </div>
    <div class="tabela" id="exemplo-tabela"><table>
      <thead><tr><th scope="col">Descrição</th><th scope="col">Situação</th><th scope="col" class="n">Valor</th></tr></thead>
      <tbody><tr><td>Serviço mensal</td><td><span class="tag warn">Em aberto</span></td><td class="n">R$ 1.400,00</td></tr></tbody>
    </table></div>
  </section>
  <x-grafico.barras titulo="Receitas por categoria" serie="receita"
    :itens="[['rotulo' => 'Serviços', 'valor' => 140000], ['rotulo' => 'Contratos', 'valor' => 95000]]" />
  <section class="painel" id="estados">
    <h2>Estados</h2>
    <p class="aviso ok">Alterações salvas.</p>
    <p class="aviso bad">Confira os campos destacados.</p>
    <x-estado-vazio titulo="Nenhum registro encontrado" descricao="Altere os filtros para consultar outros registros." url="#controles" acao="Voltar aos controles" />
  </section>
</div>
@endsection
