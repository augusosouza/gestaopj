@extends('layouts.app')
@section('titulo', 'Notificações')
@section('larga', '1')
@section('conteudo')
<x-cabecalho :trilha="['Notificações' => null]" titulo="Notificações" sub="Avisos que precisam da sua ação, de todas as empresas em que você atua.">
  <x-slot:acoes>
    <form method="post" action="{{ route('notificacoes.lidas') }}">@csrf<button class="sec">Marcar todas como lidas</button></form>
  </x-slot:acoes>
</x-cabecalho>
<form method="get" action="{{ route('notificacoes.index') }}" class="painel lista-filtros">
  <div class="lista-filtros-principais">
    <div class="campo"><label for="notificacao-filtro">Tipo de aviso</label><select name="filtro" id="notificacao-filtro">
      @foreach ($rotulos as $valor => $rotulo)<option value="{{ $valor }}" @selected($filtro === $valor)>{{ $rotulo }}</option>@endforeach
    </select></div>
    <div class="campo"><label for="notificacao-agrupar">Agrupar</label><select name="agrupar" id="notificacao-agrupar">
      <option value="">Sem agrupamento</option><option value="empresa" @selected($agrupar === 'empresa')>Por empresa</option>
      <option value="periodo" @selected($agrupar === 'periodo')>Por mês</option>
    </select></div>
  </div>
  <div class="acoes"><button class="sec">Filtrar</button></div>
</form>
<div class="lista-notif">
  @php($grupoAnterior = null)
  @forelse ($notificacoes as $n)
    @php($grupo = match ($agrupar) { 'empresa' => $n->organizacao?->razao_social ?? 'Plataforma', 'periodo' => $n->created_at->format('m/Y'), default => null })
    @if ($grupo && $grupo !== $grupoAnterior)<h2>{{ $grupo }}</h2>@endif
    @php($grupoAnterior = $grupo)
    <div>
      <a href="{{ route('notificacoes.abrir', $n) }}" @class(['notif', 'lida' => $n->lida_em])>
        <span class="tag {{ str_contains($n->tipo, 'pendente') ? 'warn' : 'info' }}">{{ $n->titulo }}</span>
        <span class="notif-texto">{{ $n->mensagem }}</span>
        <span class="notif-meta">{{ $n->organizacao?->razao_social ?? 'Plataforma' }} · {{ $n->created_at->format('d/m/Y H:i') }}
          @if ($n->adiada_ate?->isFuture()) · Adiada até {{ $n->adiada_ate->format('d/m/Y H:i') }} @endif
        </span>
      </a>
      @if (!$n->lida_em && !$n->adiada_ate?->isFuture() && in_array($n->tipo, \App\Models\Notificacao::ADIAVEIS, true))
        <form method="post" action="{{ route('notificacoes.adiar', $n) }}">
          @csrf @method('patch') <button class="link">Adiar por 24 horas</button>
        </form>
      @endif
    </div>
  @empty
    <p class="vazio painel">Nenhuma notificação neste filtro.</p>
  @endforelse
</div>
{{ $notificacoes->links() }}
@endsection
