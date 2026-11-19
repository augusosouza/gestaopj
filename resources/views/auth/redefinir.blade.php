@extends('layouts.app')
@section('titulo', 'Definir senha')
@section('conteudo')
<div class="login painel">
  <h1>Definir senha</h1>
  <form method="post" action="{{ route('password.update') }}">
    @csrf <input type="hidden" name="token" value="{{ $token }}">
    <div class="campo"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username"></div>
    <div class="campo"><label for="password">Nova senha (mín. 10 caracteres)</label><input id="password" type="password" name="password" required autocomplete="new-password"></div>
    <div class="campo"><label for="password_confirmation">Repita a senha</label><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
    <button>Salvar senha</button>
  </form>
</div>
@endsection
