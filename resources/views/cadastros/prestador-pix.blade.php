<section class="painel ajustes-painel" style="margin-top:1rem">
  <h2>Dados de pagamento</h2>
  <p>Chave Pix em uso: <span class="mono">{{ $editar->chave_pix ?: 'Nenhuma' }}</span></p>
  @if ($pixPendente)
    <p class="aviso info">Solicitação pendente de {{ $pixPendente->solicitante->name }} · {{ $pixPendente->created_at->format('d/m/Y H:i') }}</p>
    <dl class="ajustes-dados">
      <div><dt>Chave proposta</dt><dd class="mono">{{ $pixPendente->chave_proposta ?: 'Remover a chave' }}</dd></div>
      <div><dt>Motivo</dt><dd>{{ $pixPendente->motivo }}</dd></div>
    </dl>
    <form method="post" action="{{ route('prestadores.pix.decidir', [$editar, $pixPendente]) }}">
      @csrf @method('patch')
      <div class="campo"><label for="justificativa_decisao">Justificativa da decisão</label><textarea id="justificativa_decisao" name="justificativa_decisao" maxlength="500" required></textarea></div>
      <div class="campo"><label for="senha_revisao_pix">Confirme sua senha</label><input type="password" id="senha_revisao_pix" name="current_password" autocomplete="current-password" required></div>
      @if ($pixPendente->solicitado_por === auth()->id())
        <button class="sec" name="decisao" value="cancelar">Cancelar minha solicitação</button>
      @else
        <button name="decisao" value="aprovar">Aprovar e aplicar chave</button>
        <button class="sec" name="decisao" value="rejeitar">Rejeitar</button>
      @endif
    </form>
  @else
    <form method="post" action="{{ route('prestadores.pix.solicitar', $editar) }}">
      @csrf
      <p class="sub">Outro administrador precisa aprovar a mudança antes de a chave entrar em uso.</p>
      <div class="campo"><label for="nova_chave_pix">Nova chave Pix</label><input id="nova_chave_pix" name="nova_chave_pix" maxlength="140" autocomplete="off"><small class="sub">Deixe em branco para solicitar a remoção da chave atual.</small></div>
      <div class="campo"><label for="motivo_pix">Motivo da alteração</label><textarea id="motivo_pix" name="motivo" maxlength="500" required>{{ old('motivo') }}</textarea></div>
      <div class="campo"><label for="senha_pix">Confirme sua senha</label><input type="password" id="senha_pix" name="current_password" autocomplete="current-password" required></div>
      <button>Solicitar alteração</button>
    </form>
  @endif
  @if ($alteracoesPix->isNotEmpty())
    <h3>Histórico de alterações</h3>
    <ul>
      @foreach ($alteracoesPix as $alteracao)
        <li>{{ $alteracao->created_at->format('d/m/Y H:i') }} · {{ $alteracao->solicitante->name }} · {{ ucfirst($alteracao->estado) }}</li>
      @endforeach
    </ul>
  @endif
</section>
