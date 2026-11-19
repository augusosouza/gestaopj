@extends('layouts.app')
@section('titulo', 'Recuperar acesso')
@section('conteudo')
<div class="login">
  <span class="marca">@include('partials.marca')</span>
  <h1>Recuperar acesso</h1>
  <p class="sub">Informe seu e-mail. Se houver conta, enviaremos um link para definir uma nova senha.</p>
  <form method="post" action="{{ route('password.email') }}">
    @csrf
    <div class="campo"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus></div>
    <div class="acoes"><button>Enviar link</button> <a href="{{ route('login') }}">Voltar ao login</a></div>
  </form>
</div>
@endsection
