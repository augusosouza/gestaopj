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
    @foreach ($categorias as $c)
      <tr @unless ($c->ativa) style="color:var(--muted)" @endunless>
        <td>{{ $c->nome }} @if ($c->chave)<span class="tag rotulo">automática</span>@endif @unless ($c->ativa)<span class="tag">inativa</span>@endunless</td>
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
    </div>
    <button style="margin-top:10px">Criar</button>
  </form>
  @endif
</div>
@endsection
