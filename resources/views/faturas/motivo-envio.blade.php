@if ($evento->acao === \App\Services\EnvioFatura::FALHOU)
  <div class="sub">{{ \App\Services\EnvioFatura::MOTIVOS[$evento->dados['motivo'] ?? ''] ?? 'Falha de envio registrada.' }}</div>
@elseif ($evento->acao === \App\Services\EnvioFatura::ACEITO)
  <div class="sub">Entrega na caixa de entrada sem confirmação.</div>
@endif
