@extends('layouts.app')
@section('titulo', 'Backups')
@section('larga', '1')
@section('conteudo')
<div class="barra" style="margin-bottom:8px">
  <x-cabecalho :trilha="['Administração Augi' => null, 'Backups' => null]" titulo="Backups" sub="Banco de dados e arquivos de todas as empresas, criptografados antes de sair do servidor. Base periódica + incrementais nos horários definidos." />
  @if ($c['destino'] !== 'desligado')
    <div class="acoes">
      <form method="post" action="{{ route('plataforma.backups.testar') }}">@csrf<button class="sec">Testar conexão</button></form>
      <form method="post" action="{{ route('plataforma.backups.executar') }}">@csrf<button class="sec">Executar agora</button></form>
      <form method="post" action="{{ route('plataforma.backups.executar') }}">@csrf<input type="hidden" name="base" value="1"><button>Executar base completa</button></form>
    </div>
  @endif
</div>

@if (session('senha_gerada'))
  <div class="aviso info"><strong>Senha de criptografia dos backups:</strong> <span class="mono" style="font-size:15px;color:var(--text)">{{ session('senha_gerada') }}</span><br>
    Ela aparece só agora. Sem ela <strong>ninguém</strong> consegue restaurar — nem nós. Baixe a folha de recuperação abaixo e guarde fora do servidor.</div>
@endif
@if ($c['destino'] === 'desligado')
  <div class="aviso bad"><strong>Backup desligado.</strong> Configure um destino abaixo.</div>
@elseif ($atrasado)
  <div class="aviso bad"><strong>Atenção:</strong> nenhum backup concluído nas últimas 26 horas. Confira o cron do servidor (abaixo) e o histórico de falhas.</div>
@endif

<section class="cards" aria-label="Situação">
  <div class="card"><div class="rot">Último backup</div><div class="num texto">{{ $ultimo?->concluido_em?->format('d/m H:i') ?? '—' }}</div><div class="det">{{ $ultimo ? ($ultimo->tipo === 'base' ? 'Base' : 'Incremental').' · '.$ultimo->tamanhoLegivel() : 'nenhum ainda' }}</div></div>
  <div class="card"><div class="rot">Horários</div><div class="num texto">{{ str_replace(',', ' · ', $c['horarios']) }}</div><div class="det">base a cada {{ $c['dias_base'] }} dia(s)</div></div>
  <div class="card"><div class="rot">Retenção</div><div class="num texto">{{ $c['bases_mantidas'] }} bases</div><div class="det">≈ {{ (int) $c['bases_mantidas'] * (int) $c['dias_base'] }} dias de histórico</div></div>
  <div class="card"><div class="rot">Destino</div><div class="num texto">{{ $destinos[$c['destino']] ?? $c['destino'] }}</div><div class="det">{{ $c['bucket'] ? $c['bucket'].'/'.$c['prefixo'] : '—' }}</div></div>
</section>

<div class="duas" style="margin-top:24px">
  <form method="post" action="{{ route('plataforma.backups.salvar') }}" class="painel">
    @csrf @method('put')
    <strong>Destino</strong>
    <div class="campo" style="margin-top:8px"><label for="destino">Onde guardar</label><select id="destino" name="destino">
      @foreach ($destinos as $k => $r)<option value="{{ $k }}" @selected(old('destino', $c['destino']) === $k)>{{ $r }}</option>@endforeach</select></div>
    <div class="grade">
      <div class="campo"><label for="bucket">Bucket</label><input id="bucket" name="bucket" value="{{ old('bucket', $c['bucket']) }}" placeholder="minha-empresa-backups"></div>
      <div class="campo"><label for="prefixo">Pasta (prefixo)</label><input id="prefixo" name="prefixo" value="{{ old('prefixo', $c['prefixo']) }}"></div>
      <div class="campo"><label for="regiao">Região <span style="color:var(--faint)">(S3: ex. sa-east-1)</span></label><input id="regiao" name="regiao" value="{{ old('regiao', $c['regiao']) }}"></div>
      <div class="campo"><label for="endpoint">Endpoint <span style="color:var(--faint)">(só “compatível”)</span></label><input id="endpoint" name="endpoint" value="{{ old('endpoint', $c['endpoint']) }}" placeholder="https://…"></div>
      <div class="campo"><label for="chave">Chave de acesso</label><input id="chave" name="chave" autocomplete="off" placeholder="{{ $c['chave'] ? \App\Services\Backup\ConfigBackup::mascarar($c['chave']).' (salva)' : 'Access Key / HMAC' }}"></div>
      <div class="campo"><label for="segredo">Chave secreta</label><input id="segredo" type="password" name="segredo" autocomplete="new-password" placeholder="{{ $c['segredo'] ? '•••••••• (salva — deixe em branco para manter)' : '' }}"></div>
    </div>
    <strong style="display:block;margin-top:6px">Agenda e retenção</strong>
    <div class="grade" style="margin-top:8px">
      <div class="campo"><label for="horarios">Horários (HH:MM)</label><input id="horarios" name="horarios" value="{{ old('horarios', $c['horarios']) }}"></div>
      <div class="campo"><label for="dias_base">Nova base a cada (dias)</label><input id="dias_base" type="number" min="1" max="31" name="dias_base" value="{{ old('dias_base', $c['dias_base']) }}"></div>
      <div class="campo"><label for="bases_mantidas">Bases mantidas</label><input id="bases_mantidas" type="number" min="1" max="520" name="bases_mantidas" value="{{ old('bases_mantidas', $c['bases_mantidas']) }}"></div>
    </div>
    @if ($c['senha'])
      <label style="display:flex;gap:8px;align-items:center;color:var(--text);margin-bottom:12px"><input type="checkbox" name="nova_senha" value="1"> Gerar nova senha de criptografia (a anterior continua guardada para abrir backups antigos)</label>
    @else
      <p class="sub" style="font-size:13px">Ao salvar um destino, o sistema gera a <strong>senha de criptografia</strong> e a mostra uma única vez.</p>
    @endif
    <button>Salvar</button>
  </form>

  <div>
    <div class="painel">
      <strong>Como configurar cada destino</strong>
      <ul style="margin:8px 0 0;padding-left:18px;color:var(--muted);font-size:13.5px;line-height:1.6">
        <li><strong style="color:var(--text)">Google Cloud Storage:</strong> crie um bucket; em <em>Configurações → Interoperabilidade</em>, gere uma chave HMAC para uma conta de serviço com acesso só a esse bucket. Recomendado: ativar a <em>política de retenção</em> do bucket (proteção contra exclusão).</li>
        <li><strong style="color:var(--text)">Amazon S3:</strong> bucket + usuário IAM com permissão só nele (List, Get, Put, Delete). Recomendado: versionamento ou Object Lock.</li>
        <li><strong style="color:var(--text)">Compatível:</strong> Cloudflare R2, Backblaze B2, Wasabi, MinIO — informe o endpoint do provedor.</li>
      </ul>
    </div>
    <div class="painel" style="margin-top:12px">
      <strong>Agendador do servidor</strong>
      <p class="sub" style="margin:6px 0;font-size:13.5px"><strong>cPanel:</strong> em <em>Cron Jobs</em>, adicione com frequência “a cada minuto”:</p>
      <div class="mono" style="background:var(--bg);padding:8px 10px;border-radius:var(--radius);color:var(--text)">* * * * * {{ $cron }} &gt;&gt; /dev/null 2&gt;&amp;1</div>
      <p class="sub" style="margin:6px 0 0;font-size:13.5px"><strong>Docker:</strong> o serviço <em>agendador</em> do docker-compose já faz isso.</p>
    </div>
    @if ($c['senha'])
    <form method="post" action="{{ route('plataforma.backups.folha') }}" class="painel" style="margin-top:12px">
      @csrf
      <strong>Folha de recuperação</strong>
      <p class="sub" style="margin:6px 0;font-size:13.5px">Destino, chaves e senha de criptografia, com o passo a passo para restaurar num servidor novo. Guarde fora do servidor.</p>
      <div style="display:flex;gap:8px"><input type="password" name="senha_conta" placeholder="Sua senha de acesso" required autocomplete="current-password" aria-label="Sua senha"><button class="sec">Baixar</button></div>
    </form>
    @endif
  </div>
</div>

<h2>Histórico</h2>
<div class="tabela"><table>
  <thead><tr><th>Início</th><th>Tipo</th><th>Situação</th><th class="n">Tamanho</th><th class="n">Arquivos enviados</th><th class="n">Registros no banco</th><th>Duração</th><th>Origem</th></tr></thead>
  <tbody>
  @forelse ($backups as $b)
    <tr>
      <td>{{ $b->iniciado_em->format('d/m/Y H:i') }}<br><span class="mono" style="font-size:11.5px">{{ $b->nome }}</span></td>
      <td><span class="tag rotulo">{{ $b->tipo === 'base' ? 'Base' : 'Incremental' }}</span></td>
      <td>@switch($b->status)
          @case('concluido')<span class="tag ok">Concluído</span>@break
          @case('falhou')<span class="tag bad">Falhou</span><br><span style="font-size:12.5px;color:var(--bad)">{{ $b->erro }}</span>@break
          @case('removido')<span class="tag">Removido (retenção)</span>@break
          @default<span class="tag warn">Em andamento</span>
        @endswitch</td>
      <td class="n">{{ $b->tamanho_bytes ? $b->tamanhoLegivel() : '—' }}</td>
      <td class="n">{{ $b->arquivos_enviados !== null ? $b->arquivos_enviados.' de '.$b->arquivos_total : '—' }}</td>
      <td class="n">{{ $b->linhas !== null ? number_format($b->linhas, 0, ',', '.') : '—' }}</td>
      <td>{{ $b->duracao() ?? '—' }}</td>
      <td>{{ $b->acionado_por === 'agenda' ? 'Agenda' : 'Manual' }}</td>
    </tr>
  @empty
    <tr><td colspan="8" class="vazio">Nenhum backup ainda.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
