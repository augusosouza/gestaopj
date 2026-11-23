@extends('layouts.app')
@section('titulo', 'Notificações')
@section('larga', '1')
@section('conteudo')
<x-cabecalho :trilha="['Notificações' => null]" titulo="Notificações" sub="Avisos que precisam da sua ação, de todas as empresas em que você atua.">
  <x-slot:acoes>
    <form method="post" action="{{ route('notificacoes.lidas') }}">@csrf<button class="sec">Marcar todas como lidas</button></form>
  </x-slot:acoes>
</x-cabecalho>
<x-abas :itens="[['Não lidas', route('notificacoes.index'), $filtro === 'nao_lidas'], ['Todas', route('notificacoes.index', ['filtro' => 'todas']), $filtro === 'todas']]" />
<div class="lista-notif">
  @forelse ($notificacoes as $n)
    <a href="{{ route('notificacoes.abrir', $n) }}" @class(['notif', 'lida' => $n->lida_em])>
      <span class="tag {{ str_contains($n->tipo, 'pendente') ? 'warn' : 'info' }}">{{ $n->titulo }}</span>
      <span class="notif-texto">{{ $n->mensagem }}</span>
      <span class="notif-meta">{{ $n->organizacao?->razao_social }} · {{ $n->created_at->format('d/m/Y H:i') }}</span>
    </a>
  @empty
    <p class="vazio painel">{{ $filtro === 'nao_lidas' ? 'Nenhuma notificação pendente.' : 'Nenhuma notificação.' }}</p>
  @endforelse
</div>
@endsection
