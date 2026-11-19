<form method="get">
  @foreach (($manter ?? []) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
  <div><label for="competencia">Competência</label>
  <input type="month" id="competencia" name="competencia" value="{{ $competencia->format('Y-m') }}" onchange="this.form.submit()"></div>
  <noscript><button class="sec">Ver</button></noscript>
</form>
