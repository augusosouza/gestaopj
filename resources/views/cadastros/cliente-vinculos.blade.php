@foreach (['contato' => ['Contatos', 'Adicionar contato'], 'endereco' => ['Endereços', 'Adicionar endereço']] as $tipo => [$titulo, $acao])
<section class="painel" aria-labelledby="titulo-{{ $tipo }}">
  <div class="barra">
    <h2 id="titulo-{{ $tipo }}">{{ $titulo }}</h2>
    <a href="{{ route('clientes.relacionamentos.form', [$cliente, $tipo]) }}">{{ $acao }}</a>
  </div>
  <div class="tabela"><table>
    <thead><tr><th scope="col">Registro</th><th scope="col">Finalidade</th><th scope="col">Situação</th><th scope="col">Ações</th></tr></thead>
    <tbody>
    @forelse ($relacionamentos[$tipo] as $registro)
      <tr>
        <td>{{ $registro->dados[$tipo === 'contato' ? 'nome' : 'logradouro'] }}</td>
        <td>{{ \App\Models\ClienteRelacionamento::FINALIDADES[$tipo][$registro->finalidade] }}</td>
        <td>{{ $registro->ativo ? 'Ativo' : 'Inativo' }}</td>
        <td><a href="{{ route('clientes.relacionamentos.form', [$cliente, $tipo, $registro->id]) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="4" class="vazio">Nenhum registro cadastrado.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $relacionamentos[$tipo]->links() }}
</section>
@endforeach
