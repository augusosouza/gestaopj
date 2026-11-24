{{-- Botões de exportação: mantêm os filtros da tela. Espera $relatorio. --}}
@php($q = array_merge(request()->except(['formato', 'aba', 'page']), $extra ?? []))
@if ($recolher ?? false)
  <details class="exportar-menu">
    <summary class="botao sec">@include('partials.icone', ['nome' => 'exportar']) Exportar</summary>
@endif
<span class="exportar" role="group" aria-label="Exportar">
  <a class="botao sec" href="{{ route('financeiro.exportar', ['relatorio' => $relatorio] + $q + ['formato' => 'xlsx']) }}">Excel</a>
  <a class="botao sec" href="{{ route('financeiro.exportar', ['relatorio' => $relatorio] + $q + ['formato' => 'csv']) }}">CSV</a>
</span>
@if ($recolher ?? false)</details>@endif
