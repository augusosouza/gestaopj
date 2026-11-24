@auth
  @if ($guiaContextual = \App\Support\BaseConhecimento::contextual(request()))
    <div class="conhecimento-contextual">
      <a href="{{ route('conhecimento.artigo', $guiaContextual) }}">
        @include('partials.icone', ['nome' => 'ajuda']) Ajuda desta tela
      </a>
    </div>
  @endif
@endauth
