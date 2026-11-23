@extends('layouts.app')
@section('titulo', 'Lembretes de vencimento')
@section('conteudo')
<x-cabecalho :trilha="['Configurações' => route('configuracoes'), 'Lembretes de vencimento' => null]" titulo="Lembretes de vencimento"
  sub="Seu padrão de avisos para cada tipo de compromisso. Cada lançamento pode ter um ajuste próprio na aba Lembretes." />
<div class="aviso info">Google Agenda: @if ($google && $google->status === 'ativa')<strong>conectado</strong> ({{ $google->email_google }}).@else não conectado.@endif
  <a href="{{ route('integracoes.google') }}">Configurar integração</a></div>
@foreach (\App\Models\LembreteConfig::TIPOS as $tipo => $rotulo)
  <form method="post" action="{{ route('configuracoes.vencimentos.salvar', $tipo) }}" class="painel" style="margin-bottom:14px">@csrf @method('put')
    <strong>{{ $rotulo }}</strong>
    <p class="sub" style="font-size:12.5px;margin:2px 0 10px">{{ $configs[$tipo]['origem'] === 'sistema' ? 'Usando o padrão do sistema (5 e 1 dia antes e no dia, às 9h).' : 'Seu padrão.' }}</p>
    @include('partials.lembrete_form', ['cfg' => $configs[$tipo], 'id' => $tipo])
    <button>Salvar padrão</button>
  </form>
@endforeach
@endsection
