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
@php($u = auth()->user())
<header class="topo">
  <div class="wrap">
    <a class="marca" href="{{ route('painel') }}">{{ config('app.name') }}</a>
    <nav>
      <a href="{{ route('painel') }}" @class(['ativo' => request()->routeIs('painel')])>Painel</a>
      @if ($u->ehInterno())
        <a href="{{ route('faturas.index') }}" @class(['ativo' => request()->routeIs('faturas.*')])>A receber</a>
        <a href="{{ route('cobrancas.index') }}" @class(['ativo' => request()->routeIs('cobrancas.*')])>A pagar</a>
        <a href="{{ route('notas.index') }}" @class(['ativo' => request()->routeIs('notas.*')])>Notas</a>
        <a href="{{ route('contratos.index') }}" @class(['ativo' => request()->routeIs('contratos.*')])>Contratos</a>
        <a href="{{ route('perfis.index') }}" @class(['ativo' => request()->routeIs('perfis.*')])>Perfis fiscais</a>
      @endif
      @if ($u->tem(\App\Enums\Papel::Admin))
        <a href="{{ route('clientes.index') }}" @class(['ativo' => request()->routeIs('clientes.*')])>Clientes</a>
        <a href="{{ route('prestadores.index') }}" @class(['ativo' => request()->routeIs('prestadores.*')])>Prestadores</a>
        <a href="{{ route('empresas.index') }}" @class(['ativo' => request()->routeIs('empresas.*')])>Empresa</a>
        <a href="{{ route('usuarios.index') }}" @class(['ativo' => request()->routeIs('usuarios.*')])>Acessos</a>
      @endif
    </nav>
    <div class="quem">
      <span>{{ $u->name }} · {{ $u->papel->rotulo() }}</span>
      <form method="post" action="{{ route('logout') }}">@csrf<button class="link">Sair</button></form>
    </div>
  </div>
</header>
@endauth
<main class="wrap">
  @if (session('ok'))<div class="aviso ok" role="status">{{ session('ok') }}</div>@endif
  @if ($errors->any())
    <div class="aviso bad" role="alert"><strong>Não foi possível concluir:</strong>
      <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif
  @yield('conteudo')
</main>
</body>
</html>
