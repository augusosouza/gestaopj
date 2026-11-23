@extends('layouts.app')
@section('titulo', 'Agenda financeira')
@section('conteudo')
@php($meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'])
@php($semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'])
<x-cabecalho :trilha="['Financeiro' => route('financeiro.painel'), 'Agenda' => null]" titulo="Agenda financeira"
  sub="Vencimentos de contas, faturas de cartão, parcelas de compras e recebimentos esperados." />
<x-abas :itens="[
  ['Próximos vencimentos', route('financeiro.agenda', array_filter(['tipo' => $tipo])), $visao === 'lista'],
  ['Mês', route('financeiro.agenda', array_filter(['visao' => 'mes', 'tipo' => $tipo])), $visao === 'mes'],
]" />

<div class="filtros-barra">
  <nav class="filtros" aria-label="Tipo" style="margin:0">
    <a href="{{ request()->fullUrlWithQuery(['tipo' => null]) }}" @class(['ativo' => ! $tipo])>Tudo</a>
    <a href="{{ request()->fullUrlWithQuery(['tipo' => 'receber']) }}" @class(['ativo' => $tipo === 'receber'])>A receber</a>
    <a href="{{ request()->fullUrlWithQuery(['tipo' => 'pagar']) }}" @class(['ativo' => $tipo === 'pagar'])>A pagar</a>
  </nav>
  @if ($visao === 'lista')
    <nav class="filtros" aria-label="Período" style="margin:0">
      @foreach ([7, 15, 30, 60] as $d)<a href="{{ request()->fullUrlWithQuery(['dias' => $d, 'dia' => null]) }}" @class(['ativo' => $dias === $d && ! request('dia')])>{{ $d }} dias</a>@endforeach
    </nav>
  @else
    <div class="mes">
      <a href="{{ request()->fullUrlWithQuery(['mes' => $mes->subMonthNoOverflow()->format('Y-m')]) }}" aria-label="Mês anterior">‹</a>
      <strong>{{ ucfirst($meses[(int) $mes->format('n')]) }} de {{ $mes->format('Y') }}</strong>
      <a href="{{ request()->fullUrlWithQuery(['mes' => $mes->addMonthNoOverflow()->format('Y-m')]) }}" aria-label="Próximo mês">›</a>
    </div>
  @endif
</div>

@if ($visao === 'lista')
  @if (request('dia'))<p class="sub">Mostrando {{ \Carbon\CarbonImmutable::parse(request('dia'))->format('d/m/Y') }}. <a href="{{ request()->fullUrlWithQuery(['dia' => null]) }}">Ver todos</a></p>@endif
  @if ($atrasados->isNotEmpty())
    <h2>Atrasados <span class="sub" style="font-weight:400">· {{ $atrasados->count() }} · entradas {{ brl((int) $atrasados->where('tipo', 'receber')->sum('valor')) }} · saídas {{ brl((int) $atrasados->where('tipo', 'pagar')->sum('valor')) }}</span></h2>
    @include('financeiro._agenda_lista', ['itens' => $atrasados, 'mostrarData' => true])
  @endif
  @forelse ($porDia as $data => $itens)
    @php($d = \Carbon\CarbonImmutable::parse($data))
    <h2 class="dia-agenda">{{ $d->isToday() ? 'Hoje' : ($d->isTomorrow() ? 'Amanhã' : ucfirst($semana[$d->dayOfWeek]).', '.$d->format('d/m')) }}
      <span class="sub" style="font-weight:400">@if ($e = $itens->where('tipo', 'receber')->sum('valor'))· entra {{ brl((int) $e) }} @endif @if ($s = $itens->where('tipo', 'pagar')->sum('valor'))· sai {{ brl((int) $s) }}@endif</span></h2>
    @include('financeiro._agenda_lista', ['itens' => $itens, 'mostrarData' => false])
  @empty
    @if ($atrasados->isEmpty())<p class="painel vazio">Nenhum vencimento no período.</p>@endif
  @endforelse
@else
  @php($inicio = $mes->startOfWeek(\Carbon\CarbonInterface::SUNDAY))
  @php($fim = $mes->endOfMonth()->endOfWeek(\Carbon\CarbonInterface::SATURDAY))
  <div class="calendario" role="table" aria-label="Vencimentos de {{ $meses[(int) $mes->format('n')] }}">
    <div class="cal-cab" role="row">@foreach ($semana as $s)<span role="columnheader">{{ $s }}</span>@endforeach</div>
    <div class="cal-grade">
    @for ($d = $inicio; $d->lte($fim); $d = $d->addDay())
      @php($itens = $porDia->get($d->toDateString(), collect()))
      @php($ent = (int) $itens->where('tipo', 'receber')->sum('valor'))
      @php($sai = (int) $itens->where('tipo', 'pagar')->sum('valor'))
      <div @class(['cal-dia', 'fora' => $d->month !== $mes->month, 'hoje' => $d->isToday()]) role="cell">
        <span class="cal-num">{{ $d->day }}</span>
        @if ($itens->isNotEmpty())
          <a href="{{ route('financeiro.agenda', array_filter(['dia' => $d->toDateString(), 'dias' => 90, 'tipo' => $tipo])) }}" class="cal-itens" title="{{ $itens->count() }} compromisso(s)">
            @if ($ent)<span class="cal-ent">+{{ brl($ent) }}</span>@endif
            @if ($sai)<span class="cal-sai">−{{ brl($sai) }}</span>@endif
            <span class="cal-qtd">{{ $itens->count() }} item(ns)</span>
          </a>
        @endif
      </div>
    @endfor
    </div>
  </div>
  <p class="sub" style="font-size:12.5px;margin-top:8px">Total do mês: entradas {{ brl((int) $eventos->where('tipo', 'receber')->sum('valor')) }} · saídas {{ brl((int) $eventos->where('tipo', 'pagar')->sum('valor')) }}. Valores são os saldos em aberto. Clique em um dia para ver a lista.</p>
@endif
@endsection
