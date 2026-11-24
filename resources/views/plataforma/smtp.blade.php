@extends('layouts.app')
@section('titulo', 'E-mail e SMTP')
@section('conteudo')
<x-cabecalho :trilha="['Administração Augi' => null, 'E-mail e SMTP' => null]" titulo="E-mail e SMTP"
  sub="Configure o envio de convites e links de recuperação para todas as empresas e escritórios." />
@if (session('erro'))<div class="aviso bad" role="alert">{{ session('erro') }}</div>@endif
<div class="ajustes-grade">
  <form method="post" action="{{ route('plataforma.smtp.salvar') }}" class="painel ajustes-painel">
    @csrf @method('put')
    <h2>Transporte da plataforma</h2>
    @unless ($disponivel)
      <p class="aviso info">Defina <code>GESTAO_SMTP_PAINEL_HOST</code> no ambiente da aplicação para liberar a edição. Em uma implantação com cache de configuração, recarregue o cache após alterar o ambiente.</p>
    @endunless
    <fieldset @disabled(! $disponivel) class="ajustes-campos">
      <input type="hidden" name="ativo" value="0">
      <label class="marcar"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $dados['ativo'] ?? false))> Usar a configuração do painel para o envio</label>
      <div class="grade">
        <div class="campo"><label for="smtp_host">Servidor autorizado</label><input id="smtp_host" value="{{ $host ?? '' }}" readonly></div>
        <div class="campo"><label for="porta">Porta e criptografia</label><select id="porta" name="porta">
          <option value="587" @selected((int) old('porta', $dados['porta'] ?? 587) === 587)>587 · STARTTLS obrigatório</option>
          <option value="465" @selected((int) old('porta', $dados['porta'] ?? 587) === 465)>465 · TLS implícito</option>
        </select></div>
      </div>
      <div class="grade">
        <div class="campo"><label for="remetente">E-mail do remetente</label><input id="remetente" name="remetente" type="email" maxlength="254" value="{{ old('remetente', $dados['remetente'] ?? '') }}" required></div>
        <div class="campo"><label for="nome">Nome do remetente</label><input id="nome" name="nome" maxlength="100" value="{{ old('nome', $dados['nome'] ?? '') }}" placeholder="{{ config('app.name') }}"></div>
      </div>
      <div class="campo"><label for="usuario">Usuário SMTP</label><input id="usuario" name="usuario" maxlength="254" value="{{ old('usuario', $dados['usuario'] ?? '') }}" autocomplete="off"></div>
      <div class="campo">
        <label for="smtp_password">Senha SMTP ou senha de aplicativo</label>
        <input id="smtp_password" type="password" name="smtp_password" maxlength="512" autocomplete="new-password" aria-describedby="smtp-senha-ajuda">
        <small class="sub" id="smtp-senha-ajuda">{{ $temSenha ? 'Credencial armazenada. Deixe em branco para mantê-la.' : 'Nenhuma senha foi cadastrada pelo painel.' }}</small>
      </div>
      @if ($temSenha)<label class="marcar"><input type="checkbox" name="remover_senha" value="1"> Remover a senha armazenada</label>@endif
      <div class="campo"><label for="confirmar_smtp">Confirme sua senha do Augi</label><input id="confirmar_smtp" name="current_password" type="password" autocomplete="current-password" required></div>
      <button>Salvar configuração de e-mail</button>
    </fieldset>
  </form>
  <aside class="painel ajustes-painel">
    <h2>Estado do envio</h2>
    <dl class="ajustes-dados">
      <div><dt>Transporte atual</dt><dd>{{ match ($transporte) { 'smtp-painel' => 'SMTP · configuração do painel', 'smtp' => 'SMTP · configuração do ambiente', 'array', 'log' => 'Envio externo desativado', default => $transporte } }}</dd></div>
      <div><dt>Último teste</dt><dd>{{ $teste ? ($teste->acao === 'plataforma.smtp_teste_ok' ? 'Aceito pelo servidor' : 'Falhou').' · '.$teste->created_at->format('d/m/Y H:i') : 'Ainda não executado' }} @if ($testeDesatualizado)<br><small class="sub">A configuração mudou desde este teste.</small>@endif</dd></div>
    </dl>
    @if (in_array($transporte, ['smtp', 'smtp-painel'], true))
      <form method="post" action="{{ route('plataforma.smtp.testar') }}">
        @csrf
        <p class="sub">A mensagem de teste vai para {{ auth()->user()->email }}.</p>
        <div class="campo"><label for="confirmar_teste">Confirme sua senha do Augi</label><input id="confirmar_teste" name="current_password" type="password" autocomplete="current-password" required></div>
        <button class="sec">Enviar e-mail de teste</button>
      </form>
    @endif
  </aside>
</div>
@endsection
