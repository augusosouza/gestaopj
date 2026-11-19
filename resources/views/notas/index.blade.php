@extends('layouts.app')
@section('titulo', 'Notas fiscais')
@section('conteudo')
<div class="barra">
  <div><h1>Notas fiscais</h1><p class="sub" style="margin:0">NFS-e emitidas pela empresa e recebidas de prestadores</p></div>
  <form method="get">
    <div><label for="direcao">Tipo</label>
      <select id="direcao" name="direcao" onchange="this.form.submit()">
        <option value="">Todas</option>
        <option value="emitida" @selected($direcao === 'emitida')>Emitidas</option>
        <option value="recebida" @selected($direcao === 'recebida')>Recebidas</option>
      </select></div>
    <div><label for="competencia">Competência</label><input type="month" id="competencia" name="competencia" value="{{ $competencia?->format('Y-m') }}" onchange="this.form.submit()"></div>
    <noscript><button class="sec">Filtrar</button></noscript>
  </form>
</div>
<div class="tabela">
  <table>
    <thead><tr><th>Tipo</th><th>Nº</th><th>Emitente</th><th>Tomador</th><th>Competência</th><th class="n">Valor</th><th>XML</th></tr></thead>
    <tbody>
    @forelse ($notas as $n)
      <tr>
        <td><span class="tag">{{ $n->direcao === 'emitida' ? 'Emitida' : 'Recebida' }}</span></td>
        <td>{{ $n->numero ?? '—' }}</td>
        <td>{{ $n->emitente_nome ?? '—' }}<br><span class="mono">{{ doc($n->emitente_documento) }}</span></td>
        <td>{{ $n->tomador_nome ?? '—' }}@if ($n->tomador_documento)<br><span class="mono">{{ doc($n->tomador_documento) }}</span>@endif</td>
        <td>{{ $n->competencia?->format('m/Y') ?? '—' }}</td>
        <td class="n">{{ brl($n->valor_servicos_centavos) }}</td>
        <td>@if ($n->temXml())<a href="{{ route('notas.xml', $n) }}">Baixar</a>@else<span class="tag warn">Só chave</span>@endif</td>
      </tr>
    @empty
      <tr><td colspan="7" class="vazio">Nenhuma nota encontrada.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
<div style="margin-top:12px">{{ $notas->links('vendor.pagination.simples') }}</div>
@endsection
