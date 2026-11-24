<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#20232b">
  <link rel="manifest" href="{{ url('manifest.webmanifest') }}">
  <link rel="icon" href="{{ url('favicon.ico') }}">
  <link rel="apple-touch-icon" href="{{ url('pwa/apple-touch.png') }}">
  <script src="{{ url('js/pwa.js') }}?v={{ filemtime(public_path('js/pwa.js')) }}" defer></script>
  <title>@yield('titulo', 'Painel') · {{ config('app.name') }}</title>
  <link rel="preload" href="{{ asset('fonts/ibm-plex-sans-latin-400-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
  <script src="{{ asset('js/interface.js') }}?v={{ filemtime(public_path('js/interface.js')) }}" defer></script>
</head>
<body>
@php($c = ctx())
@php($A = \App\Enums\Papel::class)

@if (auth()->check() && $c->vinculo())
<div class="app" data-menu-preferencia="augi.menu.{{ auth()->id() }}.{{ $c->vinculo()->id }}">
  <aside class="lateral">
    <div class="lateral-topo">
      <a class="marca" href="{{ $c->empresa() ? route('painel') : route('carteira') }}">@include('partials.marca')</a>
      <button type="button" class="lateral-compactar" aria-controls="menu-principal" aria-expanded="true" aria-label="Recolher navegação" title="Recolher navegação" hidden>
        @include('partials.icone', ['nome' => 'lateral'])
      </button>
      <label for="menu" class="menu-btn">Menu</label>
    </div>
    <input type="checkbox" id="menu" class="menu-toggle" aria-controls="menu-principal">
    <label for="menu" class="menu-fundo" aria-hidden="true"></label>
    <div class="lateral-corpo" id="menu-principal">
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
          <a href="{{ $menu['inicio']['url'] }}" title="{{ $menu['inicio']['rotulo'] }}" @class(['menu-raiz', 'ativo' => $menu['inicio']['ativo']]) @if ($menu['inicio']['ativo']) aria-current="page" @endif>
            @include('partials.icone', ['nome' => 'inicio']) <span>{{ $menu['inicio']['rotulo'] }}</span></a>
        @endif
        @if (\Illuminate\Support\Facades\Route::has('notificacoes.index'))
          @php($naoLidas = \App\Models\Notificacao::naoLidasDe(auth()->user()))
          <a href="{{ route('notificacoes.index') }}" title="Notificações" @class(['menu-raiz', 'ativo' => request()->routeIs('notificacoes.*')])>
            @include('partials.icone', ['nome' => 'sino']) <span>Notificações</span>
            @if ($naoLidas)<span class="contador" aria-label="{{ $naoLidas }} não lidas">{{ $naoLidas }}</span>@endif</a>
        @endif
        @foreach ($menu['grupos'] as $g)
          <details class="menu-grupo" data-grupo="{{ $g['chave'] }}" @if ($g['ativo']) open data-fixo @endif>
            <summary>@include('partials.icone', ['nome' => $g['icone']]) <span>{{ $g['rotulo'] }}</span></summary>
            @foreach ($g['itens'] as $i)
              @if (isset($i['secao']))<span class="menu-secao">{{ $i['secao'] }}</span>@continue @endif
              <a href="{{ $i['url'] }}" @class(['ativo' => $i['ativo']]) @if ($i['ativo']) aria-current="page" @endif>{{ $i['rotulo'] }}</a>
            @endforeach
          </details>
        @endforeach
        @if ($menu['config'])
          <a href="{{ $menu['config']['url'] }}" title="Configurações" @class(['menu-raiz', 'ativo' => $menu['config']['ativo']])>
            @include('partials.icone', ['nome' => 'administracao']) <span>Configurações</span></a>
        @endif
        <a href="{{ route('conhecimento.index') }}" title="Base de conhecimento" @class(['menu-raiz', 'ativo' => request()->routeIs('conhecimento.*')])
          @if (request()->routeIs('conhecimento.index')) aria-current="page" @endif>
          @include('partials.icone', ['nome' => 'ajuda']) <span>Base de conhecimento</span></a>
      </nav>
      <div class="usuario">
        <a href="{{ route('seguranca') }}" title="Minha conta e segurança">{{ auth()->user()->name }}</a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="link">Sair</button></form>
      </div>
    </div>
  </aside>
  <main @class(['conteudo', 'conteudo-compacto' => View::hasSection('compacta'), 'conteudo-expandido' => View::hasSection('larga')])>
    @include('partials.mensagens')
    @include('conhecimento.contextual')
    @yield('conteudo')
    @include('partials.instalar-pwa')
  </main>
  <nav class="navegacao-mobile" aria-label="Atalhos no celular">
    @if ($menu['inicio'])
      <a href="{{ $menu['inicio']['url'] }}" @if ($menu['inicio']['ativo']) aria-current="page" @endif>
        @include('partials.icone', ['nome' => 'inicio'])
        <span>{{ $c->ehPrestador() ? 'Cobranças' : $menu['inicio']['rotulo'] }}</span>
      </a>
    @endif
    @if ($c->empresa() && $c->ehInterno())
      <a href="{{ route('financeiro.painel') }}" @if (request()->routeIs('financeiro.painel')) aria-current="page" @endif>
        @include('partials.icone', ['nome' => 'financeiro']) <span>Financeiro</span>
      </a>
      <a href="{{ route('financeiro.agenda') }}" @if (request()->routeIs('financeiro.agenda')) aria-current="page" @endif>
        @include('partials.icone', ['nome' => 'agenda']) <span>Agenda</span>
      </a>
    @endif
    <a href="{{ route('notificacoes.index') }}" @if (request()->routeIs('notificacoes.*')) aria-current="page" @endif>
      @include('partials.icone', ['nome' => 'sino']) <span>Avisos</span>
    </a>
    <button type="button" data-menu-mobile aria-controls="menu-principal" aria-expanded="false" hidden>
      @include('partials.icone', ['nome' => 'operacao']) <span>Menu</span>
    </button>
  </nav>
</div>
@else
<main @class(["solo", "larga" => View::hasSection("larga")])>
  @auth
    <div class="barra" style="margin-bottom:32px">
      <span class="marca">@include('partials.marca')</span>
      <div class="acoes">
        @if (auth()->user()->admin_plataforma)<a class="sub" style="margin:0" href="{{ route('plataforma.configuracoes') }}">Configurações da plataforma</a>
          <a class="sub" style="margin:0" href="{{ route('plataforma.backups') }}">Backups</a>@endif
        <a class="sub" style="margin:0" href="{{ route('conhecimento.index') }}">Base de conhecimento</a>
        <a class="sub" style="margin:0" href="{{ route('seguranca') }}">{{ auth()->user()->name }}</a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="link">Sair</button></form></div>
    </div>
  @endauth
  @include('partials.mensagens')
  @include('conhecimento.contextual')
  @yield('conteudo')
  @include('partials.instalar-pwa')
</main>
@endif
</body>
</html>
