@extends('layouts.app')
@section('titulo', 'Entrar')
@section('conteudo')
<div class="login">
  <span class="marca">@include('partials.marca')</span>
  <h1>Entrar</h1>
  <p class="sub">Contratos, notas e pagamentos da sua empresa e dos seus prestadores.</p>
  <form method="post" action="{{ route('login') }}">
    @csrf
    <div class="campo"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></div>
    <div class="campo">
      <div style="display:flex;justify-content:space-between"><label for="password">Senha</label><a href="{{ route('password.request') }}" style="font-size:13px">Esqueci a senha</a></div>
      <input id="password" type="password" name="password" required autocomplete="current-password">
    </div>
    <div class="campo"><label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="lembrar" value="1"> Manter conectado neste computador</label></div>
    <button style="width:100%">Entrar</button>
  </form>
</div>
@endsection
