<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', 'Painel') · {{ config('app.name') }}</title>
  <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
@php($c = ctx())
@php($A = \App\Enums\Papel::class)
<header class="topo">
  <div class="wrap">
    <a class="marca" href="{{ $c->empresa() ? route('painel') : route('contexto.escolher') }}">{{ config('app.name') }}</a>
    <nav>
      @if ($c->empresa())
        <a href="{{ route('painel') }}" @class(['ativo' => request()->routeIs('painel')])>Painel</a>
        @if ($c->ehInterno())
          <a href="{{ route('faturas.index') }}" @class(['ativo' => request()->routeIs('faturas.*')])>A receber</a>
          <a href="{{ route('cobrancas.index') }}" @class(['ativo' => request()->routeIs('cobrancas.*')])>A pagar</a>
          <a href="{{ route('notas.index') }}" @class(['ativo' => request()->routeIs('notas.*')])>Notas</a>
          <a href="{{ route('contratos.index') }}" @class(['ativo' => request()->routeIs('contratos.*')])>Contratos</a>
          <a href="{{ route('perfis.index') }}" @class(['ativo' => request()->routeIs('perfis.*')])>Perfis fiscais</a>
        @endif
        @if ($c->tem($A::Admin))
          <a href="{{ route('clientes.index') }}" @class(['ativo' => request()->routeIs('clientes.*')])>Clientes</a>
          <a href="{{ route('prestadores.index') }}" @class(['ativo' => request()->routeIs('prestadores.*')])>Prestadores</a>
          <a href="{{ route('organizacao.show') }}" @class(['ativo' => request()->routeIs('organizacao.*')])>Empresa</a>
        @endif
      @endif
      @if ($c->ehEscritorio())
        <a href="{{ route('carteira') }}" @class(['ativo' => request()->routeIs('carteira')])>Carteira</a>
        @if ($c->tem($A::Revisor))
          <a href="{{ route('escritorio.atribuicoes') }}" @class(['ativo' => request()->routeIs('escritorio.*')])>Atribuições</a>
        @endif
      @endif
      @if ($c->tem($A::Admin, $A::Revisor))
        <a href="{{ route('usuarios.index') }}" @class(['ativo' => request()->routeIs('usuarios.*')])>Acessos</a>
      @endif
    </nav>
    <div class="quem">
      @if ($c->vinculo())
        <span title="Contexto ativo">
          @if ($c->ehEscritorio()){{ $c->vinculo()->organizacao->razao_social }}@if ($c->empresa()) → <strong>{{ $c->empresa()->razao_social }}</strong>@endif
          @else<strong>{{ $c->empresa()?->razao_social }}</strong>@endif
          · {{ $c->papel()->rotulo() }}
        </span>
        <a href="{{ route('contexto.escolher') }}">Trocar</a>
      @endif
      <span>{{ auth()->user()->name }}</span>
      <form method="post" action="{{ route('logout') }}">@csrf<button class="link">Sair</button></form>
    </div>
  </div>
</header>
@endauth
<main class="wrap">
  @if (session('ok'))<div class="aviso ok" role="status">{{ session('ok') }}</div>@endif
  @if (session('aviso'))<div class="aviso info" role="status">{{ session('aviso') }}</div>@endif
  @if ($errors->any())
    <div class="aviso bad" role="alert"><strong>Não foi possível concluir:</strong>
      <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif
  @yield('conteudo')
</main>
</body>
</html>
