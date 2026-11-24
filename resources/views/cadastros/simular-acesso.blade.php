@extends('layouts.app')
@section('titulo', 'Simular acesso')
@section('conteudo')
<x-cabecalho :trilha="['Equipe e acessos' => route('usuarios.index'), 'Simular acesso' => null]"
  titulo="Simular acesso" :sub="$vinculo->user->name.' · '.$vinculo->papel->rotulo()" />
<div class="ajustes-grade">
  <section class="painel ajustes-painel">
    <h2>Resultado por ação</h2>
    <form method="get" action="{{ route('usuarios.simular', $vinculo) }}" class="ajustes-filtros">
      <div class="campo">
        <label for="recurso">Recurso</label>
        <select id="recurso" name="recurso">
          @foreach ($recursos as $chave => [$rotulo])
            <option value="{{ $chave }}" @selected($recurso === $chave)>{{ $rotulo }}</option>
          @endforeach
        </select>
      </div>
      <button>Simular acesso</button>
    </form>
    <div class="tabela"><table>
      <thead><tr><th scope="col">Ação</th><th scope="col">Acesso à ação</th><th scope="col">Motivo</th></tr></thead>
      <tbody>
        @foreach ($resultados as [$acao, $permitido, $motivo])
          <tr><td>{{ $acao }}</td><td><span @class(['tag', 'ok' => $permitido, 'bad' => ! $permitido])>{{ $permitido ? 'Permitido' : 'Negado' }}</span></td><td>{{ $motivo }}</td></tr>
        @endforeach
      </tbody>
    </table></div>
  </section>
  <aside class="painel ajustes-painel">
    <h2>Contexto avaliado</h2>
    <dl class="ajustes-dados">
      <div><dt>Organização do vínculo</dt><dd>{{ $org->razao_social }}</dd></div>
      <div><dt>Empresa da simulação</dt><dd>{{ $empresa?->razao_social ?? 'Nenhuma empresa selecionada na carteira' }}</dd></div>
      <div><dt>Vigência</dt><dd>{{ $vinculo->vigente() ? 'Vigente' : 'Fora da vigência' }}</dd></div>
    </dl>
    <p class="sub">O resultado avalia o acesso à ação no contexto exibido. A operação real ainda valida o registro, a situação e as confirmações exigidas.</p>
  </aside>
</div>
@endsection
