@extends('layouts.app')
@section('titulo', 'Carteira')
@section('conteudo')
<div class="barra">
  <div>
    <h1>Carteira</h1>
    <p class="sub">{{ $escritorio->razao_social }} ·
      @if (ctx()->tem(\App\Enums\Papel::Revisor)) todas as empresas que autorizaram o escritório @else empresas atribuídas a você @endif</p>
  </div>
  <nav class="mes" aria-label="Competência">
    <a href="{{ request()->fullUrlWithQuery(['mes' => $mes->subMonthNoOverflow()->format('Y-m')]) }}" aria-label="Mês anterior">‹</a>
    <strong>{{ $mes->translatedFormat('F \d\e Y') }}</strong>
    <a href="{{ request()->fullUrlWithQuery(['mes' => $mes->addMonthNoOverflow()->format('Y-m')]) }}" aria-label="Próximo mês">›</a>
  </nav>
</div>

@if (ctx()->empresa())
  <div class="aviso info">Você está acessando <strong>{{ ctx()->empresa()->razao_social }}</strong>.
    <form method="post" action="{{ route('carteira.sair') }}" style="display:inline">@csrf<button class="link">Voltar à carteira</button></form></div>
@endif

<section class="cards" aria-label="Situação da carteira">
  <div class="card"><div class="rot">Empresas</div><div class="num">{{ $total }}</div><div class="det">na carteira</div></div>
  <div class="card"><div class="rot">Aguardando a empresa</div><div class="num">{{ $contagem['com_cliente'] ?? 0 }}</div><div class="det">coleta ou pedido em aberto</div></div>
  <div class="card"><div class="rot">Aguardando o escritório</div><div class="num">{{ ($contagem['com_contador'] ?? 0) + ($contagem['revisao'] ?? 0) }}</div><div class="det">{{ $contagem['revisao'] ?? 0 }} pronta(s) para revisão</div></div>
  <div class="card"><div class="rot">Fechadas</div><div class="num">{{ $contagem['fechada'] ?? 0 }}</div><div class="det">de {{ $total }}</div></div>
</section>

<nav class="filtros" aria-label="Filtrar">
  @foreach ($filtros as $chave => $rotulo)
    <a href="{{ request()->fullUrlWithQuery(['filtro' => $chave ?: null]) }}" @class(['ativo' => $filtro === $chave])>{{ $rotulo }}</a>
  @endforeach
</nav>

<div class="tabela"><table>
  <thead><tr><th>Empresa</th><th>Etapa</th><th class="n">Documentos</th><th class="n">Pedidos abertos</th><th class="n">Respostas a conferir</th><th>Envio da empresa</th><th></th></tr></thead>
  <tbody>
  @forelse ($linhas as $l)
    <tr>
      <td>{{ $l['e']->razao_social }}<br><span class="mono">{{ doc($l['e']->cnpj) }}</span></td>
      <td>@include('contabil._etapa', ['etapa' => $l['etapa']])</td>
      <td class="n">{{ $l['cc']?->documentos_count ?? 0 }}</td>
      <td class="n">{{ $l['cc']?->abertas_count ?: '—' }}</td>
      <td class="n">{{ $l['cc']?->respondidas_count ?: '—' }}</td>
      <td>{{ $l['cc']?->enviada_em?->format('d/m/Y') ?? '—' }}</td>
      <td><form method="post" action="{{ route('carteira.entrar', $l['e']) }}">@csrf
        <input type="hidden" name="mes" value="{{ $mes->format('Y-m') }}"><button class="sec">Abrir</button></form></td>
    </tr>
  @empty
    <tr><td colspan="7" class="vazio">{{ $total ? 'Nenhuma empresa nesta situação.' : 'Nenhuma empresa autorizou o escritório ainda.' }}</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
