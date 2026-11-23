@extends('layouts.app')
@section('titulo', 'Configurações da plataforma')
@section('larga', '1')
@section('conteudo')
<x-cabecalho :trilha="['Administração Augi' => null, 'Identidade' => null]" titulo="Identidade da plataforma" />
<p class="sub">Identidade do sistema para todas as empresas e escritórios. As mudanças valem na próxima página aberta por qualquer usuário.</p>

<div class="duas">
  <form method="post" action="{{ route('plataforma.salvar') }}" class="painel">
    @csrf @method('put')
    <strong>Identidade</strong>
    @foreach ($campos as $chave => [$rotulo, $max, $obrigatorio])
      <div class="campo" @if ($loop->first) style="margin-top:8px" @endif>
        <label for="{{ $chave }}">{{ $rotulo }}@unless ($obrigatorio) <span style="color:var(--faint)">(opcional)</span>@endunless</label>
        <input id="{{ $chave }}" name="{{ $chave }}" value="{{ old($chave, $valores[$chave] ?? '') }}" maxlength="{{ $max }}" @if ($obrigatorio) required @endif>
      </div>
    @endforeach
    <p class="sub" style="font-size:13px">No logotipo em texto, a <strong>primeira palavra</strong> aparece na cor de destaque. Ex.: “Augi Gestão” → <span class="marca"><span class="marca-a">Augi</span> <span class="marca-b">Gestão</span></span></p>
    <button>Salvar</button>
  </form>

  <div class="painel">
    <strong>Como aparece</strong>
    <div style="margin:12px 0 4px;padding:16px;border-radius:var(--radius);background:var(--l-bg)">
      <span class="marca" style="color:var(--l-text)">@include('partials.marca')</span>
    </div>
    @if (\App\Support\Plataforma::get('slogan'))<p class="sub" style="margin:8px 0 0">{{ \App\Support\Plataforma::get('slogan') }}</p>@endif
    <p class="sub" style="font-size:13px;margin-top:14px">O nome também aparece no título das abas, nos e-mails (convites e recuperação de senha) e no aplicativo autenticador de quem ativar o 2FA depois da mudança. Quem já ativou continua vendo o nome antigo no aplicativo, sem nenhum efeito no funcionamento.</p>
  </div>
</div>

<h2>Histórico de alterações</h2>
<div class="tabela"><table>
  <thead><tr><th>Quando</th><th>Quem</th><th>O que mudou</th></tr></thead>
  <tbody>
  @forelse ($historico as $e)
    <tr><td>{{ $e->created_at->format('d/m/Y H:i') }}</td><td>{{ $e->user?->name ?? '—' }}</td>
      <td>@foreach ($e->dados ?? [] as $chave => $m)
        <div>{{ $campos[$chave][0] ?? $chave }}: <span style="color:var(--muted)">{{ $m['de'] ?? '(vazio)' }}</span> → <strong>{{ $m['para'] ?? '(vazio)' }}</strong></div>
      @endforeach</td></tr>
  @empty
    <tr><td colspan="3" class="vazio">Nenhuma alteração ainda.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
