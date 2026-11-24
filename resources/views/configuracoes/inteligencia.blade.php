@extends('layouts.app')
@section('titulo', 'Inteligência')
@section('conteudo')
<x-cabecalho :trilha="['Configurações' => route('configuracoes'), 'Inteligência' => null]" titulo="Inteligência"
  :sub="'Preferências de '.$empresa->razao_social" />

<div class="ajustes-grade">
  <form method="post" action="{{ route('configuracoes.inteligencia.salvar') }}" class="painel ajustes-painel">
    @csrf @method('put')
    <h2>Disponibilidade na empresa</h2>
    @unless ($editavel)<p class="sub">Somente o administrador da empresa pode alterar estas preferências.</p>@endunless
    <div class="ajustes-opcao">
      <div>
        <label for="assistente_ativo"><strong>Assistente financeiro</strong></label>
        <p class="sub">Responde a perguntas sobre caixa, contas a pagar e receber e orçamento com os dados da empresa ativa.</p>
        @if ($empresa->assistente_ativo)<a href="{{ route('inteligencia.assistente') }}">Abrir assistente</a>@endif
      </div>
      @if ($editavel)<input type="hidden" name="assistente_ativo" value="0">@endif
      <input id="assistente_ativo" name="assistente_ativo" type="checkbox" value="1" @checked(old('assistente_ativo', $empresa->assistente_ativo)) @disabled(! $editavel)>
    </div>
    <div class="ajustes-opcao">
      <div>
        <label for="sugestoes_ativas"><strong>Sugestões automáticas</strong></label>
        <p class="sub">Aponta situações como saldo projetado insuficiente, atraso, extrato desatualizado e orçamento excedido.</p>
        @if ($empresa->sugestoes_ativas)<a href="{{ route('inteligencia.sugestoes') }}">Revisar sugestões</a>@endif
      </div>
      @if ($editavel)<input type="hidden" name="sugestoes_ativas" value="0">@endif
      <input id="sugestoes_ativas" name="sugestoes_ativas" type="checkbox" value="1" @checked(old('sugestoes_ativas', $empresa->sugestoes_ativas)) @disabled(! $editavel)>
    </div>
    @if ($editavel)<button>Salvar preferências da empresa</button>@endif
  </form>
  <aside class="painel ajustes-painel">
    <h2>Motor e dados</h2>
    <dl class="ajustes-dados">
      <div><dt>Motor</dt><dd>Regras financeiras do Augi</dd></div>
      <div><dt>Tokens e chaves de IA</dt><dd>Dispensados neste modo</dd></div>
      <div><dt>Processamento</dt><dd>Na aplicação, com o contexto da empresa ativa</dd></div>
      <div><dt>Permissões</dt><dd>Acesso pelos papéis internos autorizados</dd></div>
      <div><dt>Operações financeiras</dt><dd>Consulta e sugestão. O usuário executa as ações nas telas de origem.</dd></div>
    </dl>
  </aside>
</div>
@endsection
