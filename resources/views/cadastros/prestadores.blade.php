@extends('layouts.app')
@section('titulo', 'Prestadores')
@section('conteudo')
<h1>Prestadores</h1>
<p class="sub">Contratados PJ, MEI ou pessoa física. Para dar acesso ao portal, crie um login em <a href="{{ route('usuarios.index') }}">Acessos</a>.</p>
<div class="duas">
  <div class="tabela"><table>
    <thead><tr><th>Nome</th><th>Tipo</th><th>Documento</th><th class="n">Alocações</th><th></th></tr></thead>
    <tbody>
    @forelse ($prestadores as $p)
      <tr><td>{{ $p->nome }}</td><td><span class="tag rotulo">{{ $p->tipo->value }}</span></td><td class="mono">{{ doc($p->documento) }}</td><td class="n">{{ $p->alocacoes_count }}</td><td><a href="{{ route('prestadores.edit', $p) }}">Editar</a></td></tr>
    @empty
      <tr><td colspan="5" class="vazio">Nenhum prestador.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <form method="post" action="{{ $editar ? route('prestadores.update', $editar) : route('prestadores.store') }}" class="painel">
    @csrf @if ($editar) @method('put') @endif
    <strong>{{ $editar ? 'Editar prestador' : 'Novo prestador' }}</strong>
    <div class="campo" style="margin-top:8px"><label for="nome">Razão social / nome</label><input id="nome" name="nome" value="{{ old('nome', $editar?->nome) }}" required></div>
    <div class="grade">
      <div class="campo"><label for="tipo">Tipo</label><select id="tipo" name="tipo">
        @foreach (\App\Enums\TipoPrestador::cases() as $t)<option value="{{ $t->value }}" @selected(old('tipo', $editar?->tipo?->value) === $t->value)>{{ $t->value }}</option>@endforeach
      </select></div>
      <div class="campo"><label for="documento">CNPJ (ou CPF se PF)</label><input id="documento" name="documento" value="{{ old('documento', doc($editar?->documento)) }}" required class="mono"></div>
      <div class="campo"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email', $editar?->email) }}"></div>
      @unless ($editar)<div class="campo"><label for="chave_pix">Chave PIX para pagamento</label><input id="chave_pix" name="chave_pix" value="{{ old('chave_pix', $editar?->chave_pix) }}"></div>@endunless
    </div>
    <button>Salvar</button> @if ($editar)<a href="{{ route('prestadores.index') }}">Cancelar</a>@endif
  </form>
</div>
@if ($editar)
  @include('cadastros.prestador-pix')
@endif
@endsection
