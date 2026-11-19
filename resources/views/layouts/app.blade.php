<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', 'Painel') · {{ config('app.name') }}</title>
  <link rel="preload" href="{{ asset('fonts/ibm-plex-sans-latin-400-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
@php($c = ctx())
@php($A = \App\Enums\Papel::class)

@if (auth()->check() && $c->vinculo())
<div class="app">
  <aside class="lateral">
    <div class="lateral-topo">
      <a class="marca" href="{{ $c->empresa() ? route('painel') : route('carteira') }}">{{ config('app.name') }}</a>
      <label for="menu" class="menu-btn">Menu</label>
    </div>
    <input type="checkbox" id="menu" class="menu-toggle">
    <div class="lateral-corpo">
      <div class="contexto">
        @if ($c->empresa())
          <div class="org">{{ $c->empresa()->razao_social }}</div>
          @if ($c->ehEscritorio())<div class="via">via {{ $c->vinculo()->organizacao->razao_social }}</div>@endif
        @else
          <div class="org">{{ $c->vinculo()->organizacao->razao_social }}</div>
        @endif
        <div class="meta"><span>{{ $c->papel()->rotulo() }}</span><a href="{{ route('contexto.escolher') }}">Trocar</a></div>
      </div>

      <nav aria-label="Principal">
        @if ($c->empresa())
          <div class="nav-grupo">
            <span>Mês</span>
            <a href="{{ route('painel') }}" @class(['ativo' => request()->routeIs('painel')])>{{ $c->ehPrestador() ? 'Minhas cobranças' : 'Fechamento' }}</a>
            @if ($c->ehInterno())
              <a href="{{ route('faturas.index') }}" @class(['ativo' => request()->routeIs('faturas.*')])>A receber</a>
              <a href="{{ route('cobrancas.index') }}" @class(['ativo' => request()->routeIs('cobrancas.*')])>A pagar</a>
              <a href="{{ route('notas.index') }}" @class(['ativo' => request()->routeIs('notas.*')])>Notas fiscais</a>
            @endif
          </div>
          @if ($c->ehInterno())
            <div class="nav-grupo">
              <span>Cadastros</span>
              <a href="{{ route('contratos.index') }}" @class(['ativo' => request()->routeIs('contratos.*')])>Contratos</a>
              @if ($c->tem($A::Admin))
                <a href="{{ route('clientes.index') }}" @class(['ativo' => request()->routeIs('clientes.*')])>Clientes</a>
                <a href="{{ route('prestadores.index') }}" @class(['ativo' => request()->routeIs('prestadores.*')])>Prestadores</a>
              @endif
              <a href="{{ route('perfis.index') }}" @class(['ativo' => request()->routeIs('perfis.*')])>Perfis fiscais</a>
            </div>
          @endif
        @endif

        @if ($c->ehEscritorio())
          <div class="nav-grupo">
            <span>Escritório</span>
            <a href="{{ route('carteira') }}" @class(['ativo' => request()->routeIs('carteira')])>Carteira</a>
            @if ($c->tem($A::Revisor))
              <a href="{{ route('escritorio.atribuicoes') }}" @class(['ativo' => request()->routeIs('escritorio.*')])>Atribuições</a>
            @endif
          </div>
        @endif

        @if ($c->tem($A::Admin, $A::Revisor))
          <div class="nav-grupo">
            <span>Administração</span>
            @if ($c->tem($A::Admin))
              <a href="{{ route('organizacao.show') }}" @class(['ativo' => request()->routeIs('organizacao.*')])>Empresa</a>
            @endif
            <a href="{{ route('usuarios.index') }}" @class(['ativo' => request()->routeIs('usuarios.*')])>Acessos</a>
          </div>
        @endif
      </nav>

      <div class="usuario">
        <span>{{ auth()->user()->name }}</span>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="link">Sair</button></form>
      </div>
    </div>
  </aside>
  <main class="conteudo">
    @include('partials.mensagens')
    @yield('conteudo')
  </main>
</div>
@else
<main class="solo">
  @auth
    <div class="barra" style="margin-bottom:32px">
      <span class="marca">{{ config('app.name') }}</span>
      <div class="acoes"><span class="sub" style="margin:0">{{ auth()->user()->name }}</span>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="link">Sair</button></form></div>
    </div>
  @endauth
  @include('partials.mensagens')
  @yield('conteudo')
</main>
@endif
</body>
</html>
