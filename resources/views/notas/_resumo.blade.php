<dl class="dados">
  <dt>Número</dt><dd>{{ $nota->numero ?? '—' }}</dd>
  <dt>Chave</dt><dd class="mono">{{ $nota->chave_acesso }}</dd>
  @if ($nota->temXml())
    <dt>Emitente</dt><dd>{{ $nota->emitente_nome }} <span class="mono">{{ doc($nota->emitente_documento) }}</span></dd>
    <dt>Tomador</dt><dd>{{ $nota->tomador_nome }} <span class="mono">{{ doc($nota->tomador_documento) }}</span></dd>
    <dt>Competência</dt><dd>{{ $nota->competencia?->format('m/Y') ?? '—' }}</dd>
    <dt>Emitida em</dt><dd>{{ $nota->emitida_em?->format('d/m/Y H:i') ?? '—' }}</dd>
    <dt>Serviço</dt><dd>{{ $nota->codigo_servico }} {{ \Illuminate\Support\Str::limit($nota->descricao_servico, 120) }}</dd>
    <dt>Valor do serviço</dt><dd>{{ brl($nota->valor_servicos_centavos) }}</dd>
    <dt>Valor líquido</dt><dd>{{ brl($nota->valor_liquido_centavos) }}</dd>
    <dt>Ambiente</dt><dd>@if ($nota->ambiente === '2')<span class="tag warn">Produção restrita (teste)</span>@else Produção @endif</dd>
    <dt>Arquivos</dt><dd><a href="{{ route('notas.xml', $nota) }}">XML</a>
      @if (app(\App\Services\Receita\GeradorDanfse::class)->habilitado() || $nota->danfse_path)
        · <a href="{{ route('notas.danfse', $nota) }}">PDF (DANFSe)</a>
        @if ($nota->danfse_versao)<span class="sub" style="font-size:12px"> gerador Receita {{ $nota->danfse_versao }}</span>@endif
      @endif</dd>
  @else
    <dt>XML</dt><dd><span class="tag warn">Somente chave informada</span></dd>
  @endif
</dl>
