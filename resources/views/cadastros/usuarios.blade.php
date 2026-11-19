@extends('layouts.app')
@section('titulo', 'Acessos')
@section('conteudo')
<h1>Acessos</h1>
<p class="sub">Cada prestador vê apenas as próprias cobranças, notas e pagamentos.</p>
<div class="duas">
  <div class="tabela"><table>
    <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Prestador</th></tr></thead>
    <tbody>
    @foreach ($usuarios as $u)
      <tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td><span class="tag">{{ $u->papel->rotulo() }}</span></td><td>{{ $u->prestador?->nome ?? '—' }}</td></tr>
    @endforeach
    </tbody>
  </table></div>
  <form method="post" action="{{ route('usuarios.store') }}" class="painel">
    @csrf
    <strong>Novo acesso</strong>
    <div class="campo" style="margin-top:8px"><label for="name">Nome</label><input id="name" name="name" value="{{ old('name') }}" required></div>
    <div class="campo"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required></div>
    <div class="grade">
      <div class="campo"><label for="papel">Perfil</label><select id="papel" name="papel">
        @foreach (\App\Enums\Papel::cases() as $p)<option value="{{ $p->value }}" @selected(old('papel', 'prestador') === $p->value)>{{ $p->rotulo() }}</option>@endforeach
      </select></div>
      <div class="campo"><label for="prestador_id">Prestador (se perfil Prestador)</label><select id="prestador_id" name="prestador_id">
        <option value="">—</option>
        @foreach ($prestadores as $p)<option value="{{ $p->id }}" @selected(old('prestador_id') == $p->id)>{{ $p->nome }}</option>@endforeach
      </select></div>
    </div>
    <div class="campo"><label for="password">Senha inicial (mín. 10 caracteres)</label><input id="password" type="password" name="password" required autocomplete="new-password"></div>
    <button>Criar acesso</button>
  </form>
</div>
@endsection
