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
      <a class="marca" href="{{ $c->empresa() ? route('painel') : route('carteira') }}">@include('partials.marca')</a>
      <label for="menu" class="menu-btn">Menu</label>
    </div>
    <input type="checkbox" id="menu" class="menu-toggle">
    <label for="menu" class="menu-fundo" aria-hidden="true"></label>
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

      @php($menu = \App\Support\Menu::para($c, auth()->user()))
      <nav aria-label="Principal" class="menu">
        @if ($menu['inicio'])
          <a href="{{ $menu['inicio']['url'] }}" @class(['menu-raiz', 'ativo' => $menu['inicio']['ativo']]) @if ($menu['inicio']['ativo']) aria-current="page" @endif>
            @include('partials.icone', ['nome' => 'inicio']) <span>{{ $menu['inicio']['rotulo'] }}</span></a>
        @endif
        @if (\Illuminate\Support\Facades\Route::has('notificacoes.index'))
          @php($naoLidas = \App\Models\Notificacao::naoLidasDe(auth()->user()))
          <a href="{{ route('notificacoes.index') }}" @class(['menu-raiz', 'ativo' => request()->routeIs('notificacoes.*')])>
            @include('partials.icone', ['nome' => 'sino']) <span>Notificações</span>
            @if ($naoLidas)<span class="contador" aria-label="{{ $naoLidas }} não lidas">{{ $naoLidas }}</span>@endif</a>
        @endif
        @foreach ($menu['grupos'] as $g)
          <details class="menu-grupo" data-grupo="{{ $g['chave'] }}" @if ($g['ativo']) open data-fixo @endif>
            <summary>@include('partials.icone', ['nome' => $g['icone']]) <span>{{ $g['rotulo'] }}</span></summary>
            @foreach ($g['itens'] as $i)
              <a href="{{ $i['url'] }}" @class(['ativo' => $i['ativo']]) @if ($i['ativo']) aria-current="page" @endif>{{ $i['rotulo'] }}</a>
            @endforeach
          </details>
        @endforeach
      </nav>
      <script>
        // Lembra os grupos abertos (preferência só deste navegador). Falha em silêncio se o armazenamento estiver bloqueado.
        (function () {
          var k = 'augi.menu.abertos', abertos = [];
          try { abertos = JSON.parse(localStorage.getItem(k) || '[]'); } catch (e) {}
          document.querySelectorAll('.menu-grupo').forEach(function (d) {
            if (abertos.indexOf(d.dataset.grupo) >= 0) d.open = true;
            d.addEventListener('toggle', function () {
              var lista = Array.prototype.map.call(document.querySelectorAll('.menu-grupo[open]'), function (x) { return x.dataset.grupo; });
              try { localStorage.setItem(k, JSON.stringify(lista)); } catch (e) {}
            });
          });
        })();
      </script>

      <div class="usuario">
        <a href="{{ route('seguranca') }}" title="Minha conta e segurança">{{ auth()->user()->name }}</a>
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
<main @class(["solo", "larga" => View::hasSection("larga")])>
  @auth
    <div class="barra" style="margin-bottom:32px">
      <span class="marca">@include('partials.marca')</span>
      <div class="acoes">
        @if (auth()->user()->admin_plataforma)<a class="sub" style="margin:0" href="{{ route('plataforma.configuracoes') }}">Configurações da plataforma</a>
          <a class="sub" style="margin:0" href="{{ route('plataforma.backups') }}">Backups</a>@endif
        <a class="sub" style="margin:0" href="{{ route('seguranca') }}">{{ auth()->user()->name }}</a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="link">Sair</button></form></div>
    </div>
  @endauth
  @include('partials.mensagens')
  @yield('conteudo')
</main>
@endif
</body>
</html>
