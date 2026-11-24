<details class="painel lista-filtros">
  <summary>Minhas visões @if ($visoes->isNotEmpty()) ({{ $visoes->count() }}) @endif</summary>
  @if ($visoes->isNotEmpty())
    <ul class="check">
      @foreach ($visoes as $visao)
        <li class="lista-barra">
          <a href="{{ route($modulo.'.index', $visao->filtros) }}">{{ $visao->nome }}</a>
          @if (ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro) && !isset($visao->filtros['q']) && !isset($visao->filtros['busca']))
            <form method="post" action="{{ route('visoes.compartilhar', [$modulo, $visao]) }}">
              @csrf @method('patch')
              <input type="hidden" name="compartilhada" value="{{ $visao->compartilhada ? '0' : '1' }}">
              <button class="link">{{ $visao->compartilhada ? 'Tornar pessoal' : 'Compartilhar com a equipe' }}</button>
            </form>
          @endif
          <form method="post" action="{{ route('visoes.remover', [$modulo, $visao]) }}">
            @csrf @method('delete')
            <button class="link" aria-label="Remover visão {{ $visao->nome }}">Remover</button>
          </form>
        </li>
      @endforeach
    </ul>
  @endif
  @if ($filtrosVisao)
    <form method="post" action="{{ route('visoes.salvar', $modulo) }}" class="busca-form">
      @csrf
      @foreach ($filtrosVisao as $campo => $valor)<input type="hidden" name="filtros[{{ $campo }}]" value="{{ $valor }}">@endforeach
      <div class="campo"><label for="nome-visao">Nome da visão</label><input id="nome-visao" name="nome" maxlength="60" required></div>
      <button class="sec">Salvar filtros</button>
    </form>
  @else
    <p class="sub">Aplique um filtro para salvar uma visão.</p>
  @endif
</details>

@if (ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
  @php($visoesEquipe = \App\Models\VisaoSalva::equipe($modulo)->orderBy('nome')->limit(50)->get())
  @if ($visoesEquipe->isNotEmpty())
    <details class="painel lista-filtros">
      <summary>Visões da equipe</summary>
      <ul class="check">@foreach ($visoesEquipe as $visao)
        <li><a href="{{ route($modulo.'.index', $visao->filtros) }}">{{ $visao->nome }}</a></li>
      @endforeach</ul>
    </details>
  @endif
@endif
