{{-- Navegação mês a mês, independente do idioma do navegador. Preserva os demais filtros da URL. --}}
<nav class="mes" aria-label="Competência">
  <a href="{{ request()->fullUrlWithQuery(['competencia' => $competencia->subMonthNoOverflow()->format('Y-m')]) }}" title="Mês anterior" aria-label="Mês anterior">‹</a>
  <strong>{{ $competencia->translatedFormat('F \d\e Y') }}</strong>
  <a href="{{ request()->fullUrlWithQuery(['competencia' => $competencia->addMonthNoOverflow()->format('Y-m')]) }}" title="Próximo mês" aria-label="Próximo mês">›</a>
</nav>
