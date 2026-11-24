@extends('layouts.app')
@section('titulo', 'Saúde do sistema')
@section('larga', '1')
@section('conteudo')
@php($rotulo = ['ok' => ['ok', 'Ok'], 'warning' => ['warn', 'Atenção'], 'failed' => ['bad', 'Falhou'], 'crashed' => ['bad', 'Erro na verificação'], 'skipped' => ['', 'Não se aplica']])
<x-cabecalho :trilha="['Administração Augi' => null, 'Saúde do sistema' => null]" titulo="Saúde do sistema"
  sub="Banco, cache, agendador, disco e backups, mais o histórico de cada rotina automática. Só infraestrutura: nenhum dado das empresas aparece aqui.">
  <x-slot:acoes><form method="post" action="{{ route('plataforma.saude.verificar') }}">@csrf<button class="sec">Verificar agora</button></form></x-slot:acoes>
</x-cabecalho>

<section class="painel ajustes-painel" aria-labelledby="saude-publicacao">
  <h2 id="saude-publicacao">Publicação e portabilidade</h2>
  <dl class="ajustes-dados">
    <div><dt>Endereço configurado</dt><dd>{{ $endereco }}</dd></div>
    <div><dt>Caminho da sessão</dt><dd>{{ $caminhoSessao }}</dd></div>
    <div><dt>PHP do servidor web</dt><dd>{{ $phpWeb }}</dd></div>
  </dl>
  <p class="sub"><a href="{{ route('conhecimento.artigo', 'migrar-instalacao') }}">Consultar guia de migração</a></p>
</section>
<h2>Verificações</h2>
@if (! $resultados)
  <p class="painel vazio">Nenhuma verificação registrada ainda. Clique em “Verificar agora” ou aguarde o agendador (a cada 5 minutos).</p>
@else
  <p class="sub" style="font-size:13px">Última verificação: {{ \Carbon\CarbonImmutable::instance($resultados->finishedAt)->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
  <div class="tabela"><table>
    <thead><tr><th>Verificação</th><th>Situação</th><th>Resumo</th><th>Detalhe</th></tr></thead>
    <tbody>
    @foreach ($resultados->storedCheckResults as $r)
      @php([$classe, $texto] = $rotulo[$r->status] ?? ['', $r->status])
      <tr><td>{{ $r->label }}</td><td><span class="tag {{ $classe }}">{{ $texto }}</span></td><td>{{ \App\Http\Controllers\SaudeController::resumo($r->shortSummary) }}</td><td>{{ \App\Http\Controllers\SaudeController::traduzir($r->notificationMessage) ?: '—' }}</td></tr>
    @endforeach
    </tbody>
  </table></div>
@endif

<h2>Rotinas agendadas</h2>
<div class="tabela"><table>
  <thead><tr><th>Rotina</th><th>Quando</th><th>Último início</th><th>Último fim</th><th>Última falha</th><th>Situação</th></tr></thead>
  <tbody>
  @forelse ($rotinas as ['t' => $t, 'situacao' => $s])
    <tr><td>{{ \App\Http\Controllers\SaudeController::rotulo($t->name) }}<br><code style="font-size:12px">{{ \Illuminate\Support\Str::before($t->name, ' ') }}</code></td>
      <td><code style="font-size:12px;white-space:nowrap">{{ $t->cron_expression }}</code></td>
      <td>{{ $t->last_started_at?->format('d/m H:i') ?? '—' }}</td><td>{{ $t->last_finished_at?->format('d/m H:i') ?? '—' }}</td><td>{{ $t->last_failed_at?->format('d/m H:i') ?? '—' }}</td>
      <td>@switch($s)
        @case('ok')<span class="tag ok">Em dia</span>@break
        @case('falhou')<span class="tag bad">Falhou na última execução</span>@break
        @case('nao_rodou')<span class="tag bad">Não rodou no horário</span>@break
        @default<span class="tag">Aguardando</span>
      @endswitch</td></tr>
  @empty
    <tr><td colspan="6" class="vazio">Nenhuma rotina monitorada. Rode <code>php artisan schedule-monitor:sync</code> após cada atualização do sistema.</td></tr>
  @endforelse
  </tbody>
</table></div>
<p class="sub" style="font-size:12.5px;margin-top:8px">Requer o cron único do servidor: <code>* * * * * php artisan schedule:run</code>. Após cada atualização, rode <code>php artisan schedule-monitor:sync</code>.</p>
@endsection
