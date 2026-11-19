@extends('layouts.app')
@section('titulo', 'Escolher acesso')
@section('conteudo')
<div style="max-width:640px;margin:0 auto">
  <h1>Com qual acesso você quer entrar?</h1>
  <p class="sub">Você tem acesso a mais de uma empresa ou escritório. Os dados de cada um ficam separados; troque a qualquer momento pelo link “Trocar” no topo.</p>
  <div class="opcoes">
    @forelse ($vinculos as $v)
      <form method="post" action="{{ route('contexto.selecionar') }}" class="painel opcao">
        @csrf <input type="hidden" name="vinculo" value="{{ $v->id }}">
        <div>
          <strong>{{ $v->organizacao->razao_social }}</strong>
          <div class="sub" style="margin:0">{{ $v->organizacao->tipo->rotulo() }} · <span class="mono">{{ doc($v->organizacao->cnpj) }}</span></div>
          <div style="margin-top:4px"><span class="tag">{{ $v->papel->rotulo() }}</span>@if ($v->prestador) <span class="sub">como {{ $v->prestador->nome }}</span>@endif</div>
        </div>
        <button>Entrar</button>
      </form>
    @empty
      <div class="aviso bad">Você não tem nenhum acesso ativo. Peça ao administrador da empresa ou do escritório um novo convite.</div>
    @endforelse
  </div>
</div>
@endsection
