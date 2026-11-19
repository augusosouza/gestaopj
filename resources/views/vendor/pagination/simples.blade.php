@if ($paginator->hasPages())
  <nav style="display:flex;gap:12px" aria-label="Paginação">
    @if ($paginator->onFirstPage())<span class="sub">« Anterior</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">« Anterior</a>@endif
    @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima »</a>@else<span class="sub">Próxima »</span>@endif
  </nav>
@endif
