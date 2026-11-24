@extends('layouts.app')
@section('titulo', 'Equipe e acessos')
@section('larga', '1')
@section('conteudo')
<x-cabecalho :trilha="['Administração' => null, 'Equipe e acessos' => null]" titulo="Equipe e acessos"
  :sub="'Acessos, papéis e histórico de '.$org->razao_social" />

@if (session('erro'))<div class="aviso bad" role="alert">{{ session('erro') }}</div>@endif
<div class="ajustes-grade">
  <section class="painel ajustes-painel ajustes-equipe">
    <h2>Vínculos da equipe</h2>
    <form class="ajustes-filtros" method="get" action="{{ route('usuarios.index') }}">
      <div class="campo"><label for="q">Nome ou e-mail</label><input type="search" id="q" name="q" value="{{ $busca }}" maxlength="150"></div>
      <div class="campo"><label for="status">Situação</label><select id="status" name="status">
        <option value="">Todas</option>
        <option value="vigentes" @selected($status === 'vigentes')>Vigentes</option>
        <option value="encerrados" @selected($status === 'encerrados')>Encerrados</option>
      </select></div>
      <button class="sec">Filtrar</button>
    </form>
    <div class="tabela"><table>
      <thead><tr><th scope="col">Pessoa</th><th scope="col">Papel e vigência</th><th scope="col">Ações</th></tr></thead>
      <tbody>
      @forelse ($vinculos as $v)
        <tr>
          <td><strong>{{ $v->user->name }}</strong><br><span class="sub">{{ $v->user->email }}</span></td>
          <td>
            <span class="tag rotulo">{{ $v->papel->rotulo() }}</span>
            @if ($v->prestador)<br><span class="sub">{{ $v->prestador->nome }}</span>@endif
            <br>{{ $v->inicio->format('d/m/Y') }} – {{ $v->fim?->format('d/m/Y') ?? 'atual' }}
            <br><span class="sub">Criado por {{ $v->criadoPor?->name ?? 'sistema' }}</span>
          </td>
          <td>
            <p><a href="{{ route('usuarios.simular', $v) }}">Simular acesso</a></p>
            @if ($v->vigente() && $v->user_id !== auth()->id())
              <details>
                <summary>Alterar papel</summary>
                <form method="post" action="{{ route('usuarios.papel', $v) }}">
                  @csrf @method('patch')
                  <div class="campo"><label for="papel-{{ $v->id }}">Novo papel</label><select id="papel-{{ $v->id }}" name="papel">
                    @foreach ($papeis as $p)<option value="{{ $p->value }}" @selected($v->papel === $p)>{{ $p->rotulo() }}</option>@endforeach
                  </select></div>
                  @unless ($org->ehEscritorio())
                    <div class="campo"><label for="prestador-{{ $v->id }}">Prestador (obrigatório para esse papel)</label><select id="prestador-{{ $v->id }}" name="prestador_id">
                      <option value="">Selecione</option>
                      @foreach ($prestadores as $p)<option value="{{ $p->id }}" @selected($v->prestador_id === $p->id)>{{ $p->nome }}</option>@endforeach
                    </select></div>
                  @endunless
                  <div class="campo"><label for="senha-{{ $v->id }}">Confirme sua senha</label><input id="senha-{{ $v->id }}" type="password" name="current_password" autocomplete="current-password" required></div>
                  <button class="sec">Salvar novo papel</button>
                </form>
              </details>
              <details>
                <summary>Encerrar acesso</summary>
                <form method="post" action="{{ route('usuarios.encerrar', $v) }}">
                  @csrf @method('patch')
                  <p class="sub">O acesso será revogado e o histórico, preservado.</p>
                  <button class="perigo">Confirmar encerramento</button>
                </form>
              </details>
            @elseif ($v->vigente())
              <span class="sub">Seu acesso</span>
            @else
              <span class="tag rotulo">Encerrado</span>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="3" class="vazio">Nenhum vínculo corresponde aos filtros.</td></tr>
      @endforelse
      </tbody>
    </table></div>
    {{ $vinculos->links() }}
  </section>
  <div class="ajustes-lateral">
    <form method="post" action="{{ route('usuarios.store') }}" class="painel ajustes-painel">
      @csrf
      <h2>Convidar pessoa</h2>
      <div class="campo"><label for="name">Nome</label><input id="name" name="name" value="{{ old('name') }}" maxlength="255" required></div>
      <div class="campo"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required></div>
      <div class="campo"><label for="papel">Papel</label><select id="papel" name="papel">
        @foreach ($papeis as $p)<option value="{{ $p->value }}" @selected(old('papel', $org->ehEscritorio() ? 'analista' : 'financeiro') === $p->value)>{{ $p->rotulo() }}</option>@endforeach
      </select></div>
      @unless ($org->ehEscritorio())
        <div class="campo"><label for="prestador_id">Prestador (obrigatório para esse papel)</label><select id="prestador_id" name="prestador_id">
          <option value="">Selecione</option>
          @foreach ($prestadores as $p)<option value="{{ $p->id }}" @selected(old('prestador_id') == $p->id)>{{ $p->nome }}</option>@endforeach
        </select></div>
      @endunless
      <p class="sub">Quem já usa o Augi recebe o novo acesso na conta existente. Novas contas usam o link do e-mail para definir a senha.</p>
      <button>Conceder acesso</button>
    </form>
    <section class="painel ajustes-painel">
      <h2>O que cada papel permite</h2>
      <dl class="ajustes-dados">
        @if ($org->ehEscritorio())
          <div><dt>Revisor</dt><dd>Gerencia a equipe, distribui atribuições e trabalha na carteira autorizada.</dd></div>
          <div><dt>Analista contábil</dt><dd>Trabalha nas empresas autorizadas e atribuídas a ele.</dd></div>
        @else
          <div><dt>Administrador</dt><dd>Gerencia a empresa, a equipe, as autorizações do escritório e as operações financeiras.</dd></div>
          <div><dt>Financeiro</dt><dd>Opera lançamentos, pagamentos, recebimentos e cobranças conforme as regras financeiras da empresa.</dd></div>
          <div><dt>Prestador</dt><dd>Acompanha apenas os próprios documentos, cobranças e pagamentos no acesso vinculado.</dd></div>
        @endif
      </dl>
    </section>
  </div>
</div>
@endsection
