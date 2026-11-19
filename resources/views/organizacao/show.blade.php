@extends('layouts.app')
@section('titulo', 'Empresa')
@section('conteudo')
<h1>{{ $empresa->razao_social }}</h1>
<p class="sub"><span class="mono">{{ doc($empresa->cnpj) }}</span> · emitente das notas aos clientes e tomadora das notas dos prestadores</p>
<div class="duas">
  <form method="post" action="{{ route('organizacao.update') }}" class="painel">
    @csrf @method('put')
    <strong>Dados cadastrais</strong>
    <div class="campo" style="margin-top:8px"><label for="razao_social">Razão social</label><input id="razao_social" name="razao_social" value="{{ old('razao_social', $empresa->razao_social) }}" required></div>
    <div class="grade">
      <div class="campo"><label for="inscricao_municipal">Inscrição municipal</label><input id="inscricao_municipal" name="inscricao_municipal" value="{{ old('inscricao_municipal', $empresa->inscricao_municipal) }}"></div>
      <div class="campo"><label for="municipio_ibge">Código IBGE do município</label><input id="municipio_ibge" name="municipio_ibge" value="{{ old('municipio_ibge', $empresa->municipio_ibge) }}" maxlength="7" inputmode="numeric"></div>
    </div>
    <p class="sub">O CNPJ identifica a empresa e não é editável aqui.</p>
    <button>Salvar</button>
  </form>

  <div class="painel">
    <strong>Escritório contábil</strong>
    <p class="sub">O escritório autorizado vê contratos, notas, pagamentos e exporta o fechamento desta empresa. Revogar corta o acesso imediatamente.</p>
    @foreach ($autorizacoes as $a)
      <div class="opcao" style="padding:8px 0;border-bottom:1px solid var(--line)">
        <div>{{ $a->escritorio->razao_social }} <span class="mono">{{ doc($a->escritorio->cnpj) }}</span><br>
          <span class="sub">desde {{ $a->inicio->format('d/m/Y') }}@if ($a->fim) até {{ $a->fim->format('d/m/Y') }}@endif · por {{ $a->concedidaPor?->name ?? '—' }}</span></div>
        @if ($a->vigente())
          <form method="post" action="{{ route('organizacao.revogar', $a) }}">@csrf @method('patch')<button class="perigo" onclick="return confirm('Revogar o acesso deste escritório?')">Revogar</button></form>
        @else <span class="tag">Encerrada</span> @endif
      </div>
    @endforeach
    <form method="post" action="{{ route('organizacao.autorizar') }}" style="margin-top:12px;display:flex;gap:8px;align-items:end">
      @csrf
      <div style="flex:1"><label for="cnpj">CNPJ do escritório</label><input id="cnpj" name="cnpj" value="{{ old('cnpj') }}" required class="mono"></div>
      <button>Autorizar</button>
    </form>
  </div>
</div>
@endsection
