@extends('layouts.app')
@section('titulo', 'Entrar')
@section('conteudo')
<div class="login painel">
  <h1>{{ config('app.name') }}</h1>
  <p class="sub">Contratos, notas e pagamentos em um só lugar.</p>
  <form method="post" action="{{ route('login') }}">
    @csrf
    <div class="campo"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></div>
    <div class="campo"><label for="password">Senha</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
    <div class="campo"><label><input type="checkbox" name="lembrar" value="1" style="width:auto"> Manter conectado</label></div>
    <button>Entrar</button>
  </form>
</div>
@endsection
