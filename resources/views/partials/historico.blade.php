{{-- Trilha de auditoria de um registro. Espera $eventos (App\Models\Evento com user). --}}
@php($dinheiro = ['valor', 'total', 'tarifa', 'encargos', 'limite'])
<div class="tabela"><table>
  <thead><tr><th>Quando</th><th>Quem</th><th>O quê</th><th>Detalhe</th></tr></thead>
  <tbody>
  @forelse ($eventos as $e)
    <tr><td>{{ $e->created_at->format('d/m/Y H:i') }}</td><td>{{ $e->user?->name ?? 'Sistema' }}</td><td>{{ $e->rotulo() }}</td>
      <td>{{ collect($e->dados ?? [])->map(fn ($v, $k) => in_array($k, $dinheiro, true) && is_numeric($v) ? $k.' '.brl((int) $v) : (is_scalar($v) ? $v : ''))->filter()->join(' · ') }}</td></tr>
  @empty
    <tr><td colspan="4" class="vazio">Sem eventos registrados.</td></tr>
  @endforelse
  </tbody>
</table></div>
