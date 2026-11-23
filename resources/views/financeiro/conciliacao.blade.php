@extends('layouts.app')
@section('titulo', 'Conciliação · '.$conta->nome)
@section('conteudo')
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<a class="voltar" href="{{ route('financeiro.contas') }}">← Contas</a>
<div class="barra">
  <div><h1>Conciliação · {{ $conta->nome }}</h1><p class="sub">Saldo no sistema hoje: <strong>{{ brl($saldo) }}</strong>. Compare com o saldo do extrato do banco.</p></div>
  @if ($opera)
  <form method="post" action="{{ route('financeiro.importar', $conta) }}" enctype="multipart/form-data" class="acoes">
    @csrf
    <div><label for="arquivo">Extrato (OFX ou CSV: data;descrição;valor)</label><input id="arquivo" type="file" name="arquivo" required accept=".ofx,.qfx,.csv,.txt"></div>
    <button>Importar</button>
  </form>
  @endif
</div>

<nav class="filtros" aria-label="Mostrar">
  <a href="{{ request()->fullUrlWithQuery(['mostrar' => null]) }}" @class(['ativo' => $mostrar === 'pendentes'])>Pendentes</a>
  <a href="{{ request()->fullUrlWithQuery(['mostrar' => 'todas']) }}" @class(['ativo' => $mostrar === 'todas'])>Todas</a>
</nav>
<div class="tabela"><table>
  <thead><tr><th>Data</th><th>Descrição no extrato</th><th class="n">Valor</th><th>Lançamento no sistema</th><th></th></tr></thead>
  <tbody>
  @forelse ($linhas as $l)
    <tr>
      <td>{{ $l->data->format('d/m/Y') }}</td>
      <td>{{ $l->descricao }}@if ($l->ignorada)<br><span class="tag">sem correspondência</span>@endif</td>
      <td class="n" style="color:var({{ $l->valor_centavos < 0 ? '--text' : '--ok' }})">{{ brl($l->valor_centavos) }}</td>
      <td>
        @if ($l->movimento)
          <span class="tag ok">Conciliado</span> <a href="{{ route('financeiro.titulo', $l->movimento->parcela->titulo_id) }}">{{ $l->movimento->parcela->titulo->descricao }}</a>
        @elseif (($sug = $sugestoes[$l->id] ?? collect())->isNotEmpty() && $opera)
          @foreach ($sug as $m)
            <form method="post" action="{{ route('financeiro.conciliar', $l) }}" style="display:flex;gap:6px;align-items:center;margin-bottom:4px">@csrf
              <input type="hidden" name="movimento_id" value="{{ $m->id }}">
              <span style="font-size:13px">{{ $m->parcela->titulo->descricao }} · {{ $m->data->format('d/m') }}</span><button class="sec">Conciliar</button></form>
          @endforeach
        @else<span class="sub" style="font-size:13px">Nenhuma baixa com mesmo valor e data próxima. Registre a baixa no lançamento e volte aqui.</span>@endif
      </td>
      <td>@if ($opera)
        @if ($l->movimento)<form method="post" action="{{ route('financeiro.desconciliar', $l) }}">@csrf<button class="link">desfazer</button></form>
        @else<form method="post" action="{{ route('financeiro.ignorar', $l) }}">@csrf<button class="link">{{ $l->ignorada ? 'voltar a pendente' : 'sem correspondência' }}</button></form>@endif
      @endif</td>
    </tr>
  @empty
    <tr><td colspan="5" class="vazio">{{ $mostrar === 'pendentes' ? 'Nada pendente. Importe um extrato para começar.' : 'Nenhuma linha importada.' }}</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
