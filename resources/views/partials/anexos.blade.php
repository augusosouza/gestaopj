{{-- Anexos de um lançamento ou compra. Espera $anexos, $tipo (titulo|compra), $alvoId e opcionalmente $extras (anexos herdados, só leitura). --}}
@php($opera = ctx()->tem(\App\Enums\Papel::Admin, \App\Enums\Papel::Financeiro))
<div class="tabela"><table>
  <thead><tr><th>Arquivo</th><th>Descrição</th><th>Tamanho</th><th>Enviado</th><th></th></tr></thead>
  <tbody>
  @forelse ($anexos->concat($extras ?? collect()) as $a)
    <tr><td><a href="{{ route('anexos.baixar', $a) }}">{{ $a->nome_original }}</a>@if (($extras ?? collect())->contains($a)) <span class="tag">{{ $rotuloExtras ?? 'da compra' }}</span>@endif</td>
      <td>{{ $a->descricao ?? '—' }}</td><td>{{ $a->tamanhoLegivel() }}</td>
      <td>{{ $a->created_at->format('d/m/Y H:i') }}<br><span class="sub" style="font-size:12px">{{ $a->enviadoPor?->name ?? '—' }}</span></td>
      <td>@if ($opera && $anexos->contains($a))
        <form method="post" action="{{ route('anexos.remover', $a) }}" onsubmit="return confirm('Remover este anexo? A remoção fica no histórico.')">@csrf @method('delete')<button class="link">remover</button></form>
      @endif</td></tr>
  @empty
    <tr><td colspan="5" class="vazio">Nenhum documento anexado.</td></tr>
  @endforelse
  </tbody>
</table></div>
@if ($opera)
  <form method="post" action="{{ route('anexos.store', [$tipo, $alvoId]) }}" enctype="multipart/form-data" class="painel" style="margin-top:12px">@csrf
    <strong>Anexar documento</strong>
    <div class="grade" style="margin-top:10px">
      <div class="campo"><label for="arquivo">Arquivo (PDF, JPG, PNG, WEBP ou XML, até 10 MB)</label><input id="arquivo" type="file" name="arquivo" required accept=".pdf,.jpg,.jpeg,.png,.webp,.xml"></div>
      <div class="campo"><label for="descricao-anexo">Descrição (opcional)</label><input id="descricao-anexo" name="descricao" maxlength="120" placeholder="Ex.: comprovante Pix, nota fiscal, boleto"></div>
    </div>
    <button>Anexar</button>
    <p class="sub" style="font-size:12.5px;margin:8px 0 0">Os arquivos ficam em área privada, separados por empresa, e só podem ser baixados por quem tem acesso a este lançamento.</p>
  </form>
@endif
