@extends('layouts.app')
@section('titulo', 'Verificação em duas etapas')
@section('conteudo')
<div class="login">
  <span class="marca">@include('partials.marca')</span>
  <h1>Verificação em duas etapas</h1>
  <p class="sub">Digite o código de 6 dígitos do seu aplicativo autenticador.</p>
  <form method="post" action="{{ route('2fa.verificar') }}">
    @csrf
    <div class="campo"><label for="codigo">Código</label>
      <input id="codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" required autofocus class="codigo"></div>
    <button style="width:100%">Confirmar</button>
  </form>
  <p class="sub" style="margin-top:16px;font-size:13px">Sem acesso ao celular? Digite um dos códigos de recuperação (formato XXXXX-XXXXX). <a href="{{ route('login') }}">Voltar ao login</a></p>
</div>
@endsection
