@extends('layouts.app')
@section('titulo', 'Perfis fiscais')
@section('conteudo')
@php($pode = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Analista, \App\Enums\Papel::Revisor))
@php($confirma = ctx()->tem(\App\Enums\Papel::Analista, \App\Enums\Papel::Revisor))
<h1>Perfis fiscais</h1>
<p class="sub">Regime tributário por CNPJ e vigência, confirmado pela contabilidade. Na fase 3, define qual motor de cálculo de IBS/CBS atende cada CNPJ.</p>
<div class="duas">
  <div class="tabela"><table>
    <thead><tr><th>CNPJ</th><th>Regime</th><th>Vigência</th><th>Confirmação</th></tr></thead>
    <tbody>
    @forelse ($perfis as $p)
      <tr>
        <td>{{ $nomes[$p->cnpj] ?? '—' }}<br><span class="mono">{{ doc($p->cnpj) }}</span></td>
        <td>{{ $p->regime->rotulo() }}@if ($p->observacao)<br><span class="sub">{{ $p->observacao }}</span>@endif</td>
        <td>{{ $p->vigencia_inicio->format('d/m/Y') }} – {{ $p->vigencia_fim?->format('d/m/Y') ?? 'atual' }}</td>
        <td>@if ($p->confirmado_em)<span class="tag ok">Confirmado</span><br><span class="sub">{{ $p->confirmadoPor?->name }}, {{ $p->confirmado_em->format('d/m/Y') }}</span>
            @elseif ($confirma)<form method="post" action="{{ route('perfis.confirmar', $p) }}">@csrf<button class="sec">Confirmar</button></form>
            @else<span class="tag warn">Pendente</span>@endif</td>
      </tr>
    @empty
      <tr><td colspan="4" class="vazio">Nenhum perfil registrado.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  @if ($pode)
  <form method="post" action="{{ route('perfis.store') }}" class="painel">
    @csrf
    <strong>Registrar perfil</strong>
    <div class="campo" style="margin-top:8px"><label for="cnpj">CNPJ</label><input id="cnpj" name="cnpj" value="{{ old('cnpj') }}" required class="mono" list="cnpjs">
      <datalist id="cnpjs">@foreach ($nomes as $cnpj => $nome)@if (strlen($cnpj) === 14)<option value="{{ $cnpj }}">{{ $nome }}</option>@endif @endforeach</datalist></div>
    <div class="campo"><label for="regime">Regime</label><select id="regime" name="regime">
      @foreach (\App\Enums\RegimeTributario::cases() as $r)<option value="{{ $r->value }}">{{ $r->rotulo() }}</option>@endforeach
    </select></div>
    <div class="grade">
      <div class="campo"><label for="vigencia_inicio">Vigente a partir de</label><input id="vigencia_inicio" type="date" name="vigencia_inicio" value="{{ old('vigencia_inicio') }}" required></div>
      <div class="campo"><label for="vigencia_fim">Até (opcional)</label><input id="vigencia_fim" type="date" name="vigencia_fim" value="{{ old('vigencia_fim') }}"></div>
    </div>
    <div class="campo"><label for="observacao">Observação</label><textarea id="observacao" name="observacao" rows="2">{{ old('observacao') }}</textarea></div>
    <button>Registrar</button>
    <p class="sub" style="margin-top:8px">A vigência anterior em aberto do mesmo CNPJ é encerrada automaticamente.</p>
  </form>
  @endif
</div>
@endsection
