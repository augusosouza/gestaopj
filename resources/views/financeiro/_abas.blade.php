<nav class="filtros" aria-label="Financeiro" style="margin-top:0">
  <a href="{{ route('financeiro.index') }}" @class(['ativo' => request()->routeIs('financeiro.index', 'financeiro.titulo', 'financeiro.novo')])>Lançamentos</a>
  <a href="{{ route('financeiro.contas') }}" @class(['ativo' => request()->routeIs('financeiro.contas', 'financeiro.conciliacao')])>Contas e conciliação</a>
  <a href="{{ route('financeiro.categorias') }}" @class(['ativo' => request()->routeIs('financeiro.categorias')])>Categorias</a>
</nav>
