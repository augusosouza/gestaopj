@extends('layouts.app')
@section('titulo', 'Segurança da conta')
@section('conteudo')
<a class="voltar" href="{{ route('contexto.escolher') }}">← Voltar ao sistema</a>
<h1>Segurança da conta</h1>
<p class="sub">{{ $user->name }} · {{ $user->email }}</p>

<div class="painel" style="max-width:640px">
  <strong>Verificação em duas etapas</strong>
  @if ($user->temDoisFatores())
    <p><span class="tag ok">Ativa</span> desde {{ $user->two_factor_confirmed_at->format('d/m/Y') }} ·
      {{ count($user->two_factor_recovery_codes ?? []) }} códigos de recuperação restantes</p>

    @if ($codigos)
      <div class="aviso info">
        <strong>Guarde estes códigos de recuperação agora.</strong> Eles não serão mostrados de novo e cada um funciona uma única vez.
        <div class="codigos">@foreach ($codigos as $c)<code>{{ $c }}</code>@endforeach</div>
      </div>
    @endif

    <div class="grade" style="margin-top:12px">
      <form method="post" action="{{ route('2fa.codigos') }}">
        @csrf
        <label for="senha1">Gerar novos códigos de recuperação</label>
        <div style="display:flex;gap:8px"><input id="senha1" type="password" name="senha" placeholder="Sua senha" required autocomplete="current-password"><button class="sec">Gerar</button></div>
      </form>
      @unless ($user->exigeDoisFatores())
      <form method="post" action="{{ route('2fa.desativar') }}">
        @csrf @method('delete')
        <label for="senha2">Desativar a verificação</label>
        <div style="display:flex;gap:8px"><input id="senha2" type="password" name="senha" placeholder="Sua senha" required autocomplete="current-password"><button class="perigo">Desativar</button></div>
      </form>
      @endunless
    </div>
    @if ($user->exigeDoisFatores())<p class="sub">A verificação em duas etapas é obrigatória para seu perfil.</p>@endif

  @elseif ($segredo)
    <p class="sub">1. Abra seu aplicativo autenticador (Google Authenticator, Microsoft Authenticator, 1Password…) e leia o QR code.</p>
    <div class="qr">{!! $qr !!}</div>
    <p class="sub">Ou digite a chave manualmente: <span class="mono">{{ trim(chunk_split($segredo, 4, ' ')) }}</span></p>
    <form method="post" action="{{ route('2fa.confirmar') }}">
      @csrf
      <label for="codigo">2. Digite o código de 6 dígitos que aparece no aplicativo</label>
      <div style="display:flex;gap:8px;max-width:320px"><input id="codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" required class="codigo"><button>Ativar</button></div>
    </form>

  @else
    <p class="sub">Além da senha, o acesso passa a pedir um código do seu celular. Mesmo que alguém descubra a sua senha, não entra sem o aparelho.</p>
    <form method="post" action="{{ route('2fa.iniciar') }}">@csrf<button>Ativar verificação em duas etapas</button></form>
  @endif
</div>
@endsection
