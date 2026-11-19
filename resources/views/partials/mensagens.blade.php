@if (session('ok'))<div class="aviso ok" role="status">{{ session('ok') }}</div>@endif
@if (session('aviso'))<div class="aviso info" role="status">{{ session('aviso') }}</div>@endif
@if ($errors->any())
  <div class="aviso bad" role="alert"><strong>Não foi possível concluir:</strong>
    <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
  </div>
@endif
