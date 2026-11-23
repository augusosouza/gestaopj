{{-- Campos de configuração de lembretes. Espera $cfg (dias, hora, ativo, canais_google) e $id (sufixo único dos campos). --}}
<label class="marcar" style="margin-bottom:10px"><input type="checkbox" name="ativo" value="1" @checked($cfg['ativo'])> Lembrar destes vencimentos</label>
<fieldset class="bloco" style="margin-bottom:10px"><legend>Quando avisar</legend>
  <div class="lembrete-dias">
    @foreach (\App\Models\LembreteConfig::DIAS as $d => $rot)
      <label class="marcar"><input type="checkbox" name="dias[]" value="{{ $d }}" @checked(in_array($d, $cfg['dias'], true))> {{ $rot }}</label>
    @endforeach
  </div>
  <div class="grade" style="margin-top:8px">
    <div class="campo"><label for="hora-{{ $id }}">Horário (Brasília)</label><input id="hora-{{ $id }}" type="time" name="hora" value="{{ $cfg['hora'] }}" required></div>
    <div class="campo"><span class="sub" style="display:block;font-size:13px;margin-bottom:4px">No Google Agenda (se conectado)</span>
      @foreach (\App\Models\LembreteConfig::CANAIS as $k => $rot)
        <label class="marcar"><input type="checkbox" name="canais_google[]" value="{{ $k }}" @checked(in_array($k, $cfg['canais_google'] ?? [], true))> {{ $rot }}</label>
      @endforeach
    </div>
  </div>
  <p class="sub" style="font-size:12.5px;margin:4px 0 0">Os avisos internos do Augi (central de notificações) funcionam sempre, com ou sem Google. O Google aceita até 5 lembretes por evento.</p>
</fieldset>
