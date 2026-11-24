@extends('layouts.app')
@section('titulo', 'Conciliação · '.$conta->nome)
@section('larga', '1')
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Contas e bancos' => route('financeiro.contas'), $conta->nome => route('financeiro.conta', $conta), 'Conciliação' => null]"
  titulo="Conciliação" :sub="'Saldo no sistema hoje: '.brl($saldo).'. Extrato coberto até '.($conta->extrato_ate?->format('d/m/Y') ?? '—').'.'" />
<div class="barra">
  <div></div>
  @if ($opera)
  <form method="post" action="{{ route('financeiro.importar', $conta) }}" enctype="multipart/form-data" class="acoes" id="importar">
    @csrf
    <div><label for="arquivo">Extrato (OFX ou CSV: data;descrição;valor)</label><input id="arquivo" type="file" name="arquivo" required accept=".ofx,.qfx,.csv,.txt"></div>
    <button>Importar</button>
  </form>
  @endif
</div>

<nav class="filtros" aria-label="Mostrar">
  <a href="{{ request()->fullUrlWithQuery(['mostrar' => null, 'linha' => null, 'page' => null, 'aba' => null]) }}" @class(['ativo' => $mostrar === 'pendentes'])>Pendentes</a>
  <a href="{{ request()->fullUrlWithQuery(['mostrar' => 'todas', 'linha' => null, 'page' => null, 'aba' => null]) }}" @class(['ativo' => $mostrar === 'todas'])>Todas</a>
</nav>
<div class="duas conciliacao-colunas">
  <section aria-label="Extrato">
    <div class="tabela conciliacao-extrato"><table>
      <thead><tr><th scope="col">Data e descrição</th><th scope="col" class="n">Valor</th></tr></thead>
      <tbody>
      @forelse ($linhas as $linha)
        <tr>
          <td><a href="{{ request()->fullUrlWithQuery(['linha' => $linha->id, 'aba' => $aba === 'analise' ? 'analise' : null]).'#conferencia' }}"
            @if ($selecionada?->id === $linha->id) aria-current="true" @endif>{{ $linha->descricao }}</a>
            <div class="sub">{{ $linha->data->format('d/m/Y') }} · {{ $linha->conciliada() ? 'Conciliada' : ($linha->ignorada ? 'Ignorada' : 'Pendente') }}</div>
          </td>
          <td class="n">{{ brl($linha->valor_centavos) }}</td>
        </tr>
      @empty
        <tr><td colspan="2" class="vazio">Nenhuma movimentação neste filtro.</td></tr>
      @endforelse
      </tbody>
    </table></div>
    {{ $linhas->links() }}
  </section>

  <section class="painel" id="conferencia" aria-label="Conferência da movimentação">
    @if ($selecionada)
      @php($l = $selecionada)
      <h2>{{ $l->descricao }}</h2>
      <p class="sub">{{ $l->data->format('d/m/Y') }} · {{ $conta->nome }} · {{ brl($l->valor_centavos) }}</p>
      <p class="sub">Identificador {{ $l->identificador }} · Arquivo {{ $l->arquivo ? basename($l->arquivo) : 'indisponível' }}</p>
      <x-abas :itens="[
        ['Correspondências', request()->fullUrlWithQuery(['linha' => $l->id, 'aba' => null]).'#conferencia', $aba === 'correspondencias'],
        ['Análise inteligente', request()->fullUrlWithQuery(['linha' => $l->id, 'aba' => 'analise']).'#conferencia', $aba === 'analise'],
      ]" />
      @if ($evidencias)
        @include('financeiro.conciliacao-analise')
      @else
        @include('financeiro.conciliacao-correspondencias')
      @endif
    @else
      <h2>Conferência</h2>
      <p class="sub">Selecione uma linha do extrato para conferir suas correspondências e evidências.</p>
    @endif
  </section>
</div>
@endsection
