@extends('layouts.app')
@section('titulo', 'Acessos')
@section('conteudo')
<h1>Acessos de {{ $org->razao_social }}</h1>
<p class="sub">Cada acesso é um vínculo com papel e vigência. Encerrar corta o acesso na próxima ação e preserva o histórico. A mesma pessoa pode ter acesso a várias empresas.</p>
<div class="duas">
  <div class="tabela"><table>
    <thead><tr><th>Pessoa</th><th>Papel</th><th>Vigência</th><th></th></tr></thead>
    <tbody>
    @foreach ($vinculos as $v)
      <tr>
        <td>{{ $v->user->name }}<br><span class="sub">{{ $v->user->email }}</span></td>
        <td><span class="tag rotulo">{{ $v->papel->rotulo() }}</span>@if ($v->prestador)<br><span class="sub">{{ $v->prestador->nome }}</span>@endif</td>
        <td>{{ $v->inicio->format('d/m/Y') }} – {{ $v->fim?->format('d/m/Y') ?? 'atual' }}<br><span class="sub">por {{ $v->criadoPor?->name ?? 'sistema' }}</span></td>
        <td>@if ($v->vigente() && $v->id !== ctx()->vinculo()->id)
          <form method="post" action="{{ route('usuarios.encerrar', $v) }}">@csrf @method('patch')<button class="link" onclick="return confirm('Encerrar este acesso?')">encerrar</button></form>
        @elseif (! $v->vigente())<span class="tag rotulo">Encerrado</span>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
  <form method="post" action="{{ route('usuarios.store') }}" class="painel">
    @csrf
    <strong>Convidar</strong>
    <div class="campo" style="margin-top:8px"><label for="name">Nome</label><input id="name" name="name" value="{{ old('name') }}" required></div>
    <div class="campo"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required></div>
    <div class="grade">
      <div class="campo"><label for="papel">Papel</label><select id="papel" name="papel">
        @foreach ($papeis as $p)<option value="{{ $p->value }}" @selected(old('papel') === $p->value)>{{ $p->rotulo() }}</option>@endforeach
      </select></div>
      @if ($prestadores->isNotEmpty())
      <div class="campo"><label for="prestador_id">Prestador (se papel Prestador)</label><select id="prestador_id" name="prestador_id">
        <option value="">—</option>
        @foreach ($prestadores as $p)<option value="{{ $p->id }}" @selected(old('prestador_id') == $p->id)>{{ $p->nome }}</option>@endforeach
      </select></div>
      @endif
    </div>
    <p class="sub">Quem ainda não tem conta recebe por e-mail um link para definir a senha.</p>
    <button>Enviar convite</button>
  </form>
</div>
@endsection
