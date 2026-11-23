@extends('layouts.app')
@section('titulo', 'Categorias')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Categorias' => null]" titulo="Categorias"
  sub="Organizam receitas e despesas para os relatórios. Desativar não altera lançamentos antigos." />
<div class="duas">
  <div class="tabela"><table>
    <thead><tr><th>Categoria</th><th>Tipo</th><th></th></tr></thead>
    <tbody>
    @foreach ($categorias->sortBy(fn ($c) => $c->tipo.'|'.$c->caminho()) as $c)
      <tr @unless ($c->ativa) style="color:var(--muted)" @endunless>
        <td>@if ($c->pai_id)<span class="sub" style="margin:0">{{ $c->pai->caminho() }} → </span>@endif{{ $c->nome }} @if ($c->chave)<span class="tag rotulo">automática</span>@endif @unless ($c->ativa)<span class="tag">inativa</span>@endunless</td>
        <td>{{ $c->tipo === 'receita' ? 'Receita' : 'Despesa' }}</td>
        <td>@if ($opera && ! $c->chave)<form method="post" action="{{ route('financeiro.categoria_alternar', $c) }}">@csrf @method('patch')<button class="link">{{ $c->ativa ? 'desativar' : 'reativar' }}</button></form>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
  @if ($opera)
  <form method="post" action="{{ route('financeiro.categoria_salvar') }}" class="painel">
    @csrf <strong>Nova categoria</strong>
    <div class="grade" style="margin-top:8px">
      <div><label for="tipo">Tipo</label><select id="tipo" name="tipo"><option value="despesa">Despesa</option><option value="receita">Receita</option></select></div>
      <div><label for="nome">Nome</label><input id="nome" name="nome" required></div>
      <div><label for="pai_id">Dentro de (subcategoria)</label><select id="pai_id" name="pai_id"><option value="">— categoria principal —</option>
        @foreach ($categorias->where('ativa', true)->sortBy(fn ($c) => $c->tipo.'|'.$c->caminho()) as $c)<option value="{{ $c->id }}">{{ $c->tipo === 'receita' ? 'Receita' : 'Despesa' }}: {{ $c->caminho() }}</option>@endforeach</select></div>
    </div>
    <p class="sub" style="font-size:12.5px;margin:8px 0 0">Ex.: Tecnologia → Servidores → Infraestrutura. Orçamento de uma categoria inclui as subcategorias.</p>
    <button style="margin-top:10px">Criar</button>
  </form>
  @endif
</div>
@endsection
