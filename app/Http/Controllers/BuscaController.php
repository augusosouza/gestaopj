<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\Anexo;
use App\Models\Cartao;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Contrato;
use App\Models\ExtratoLinha;
use App\Models\Fatura;
use App\Models\NotaFiscal;
use App\Models\Prestador;
use App\Models\Titulo;
use App\Support\Documento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class BuscaController extends Controller
{
    private const LIMITE = 5;

    public function __invoke(Request $request): Response
    {
        $termo = $request->isMethod('post') ? $request->input('q') : null;
        $termo = is_string($termo) ? Str::squish($termo) : '';
        $erro = $request->isMethod('post') && (mb_strlen($termo) < 2 || mb_strlen($termo) > 100);
        $atalhos = $this->atalhos();
        $grupos = [];

        if (! $erro && $termo !== '') {
            $grupos = $this->registros($termo);
            $normalizado = Str::lower(Str::ascii($termo));
            $atalhos = array_filter($atalhos, static fn ($item) => str_contains(Str::lower(Str::ascii($item[0])), $normalizado));
        }

        return response()->view('busca.index', compact('termo', 'grupos', 'atalhos', 'erro'), $erro ? 422 : 200)
            ->header('Cache-Control', 'private, no-store');
    }

    private function atalhos(): array
    {
        $atalhos = [
            ['Ferramentas: validador cadastral e simulador', route('ferramentas')],
            ['Contas vencidas', route('contas_pagar.index', ['aba' => 'vencidas'])],
            ['Relatórios financeiros', route('relatorios.index')],
            ['Minhas notificações', route('notificacoes.index')],
            ['Base de conhecimento', route('conhecimento.index')],
        ];

        if (ctx()->tem(Papel::Admin, Papel::Financeiro)) {
            $atalhos[] = ['Nova despesa', route('financeiro.novo')];
            $atalhos[] = ['Importar extrato bancário', route('financeiro.contas')];
            $atalhos[] = ['Configurações da empresa', route('configuracoes')];
        }
        if (ctx()->tem(Papel::Admin)) {
            $atalhos[] = ['Novo cliente', route('clientes.create')];
        }

        return $atalhos;
    }

    private function registros(string $termo): array
    {
        $grupos = [];
        $documento = Documento::limpar($termo);
        if (! in_array(strlen($documento), [11, 14], true) || ! preg_match('/[0-9]/', $documento)) {
            $documento = '';
        }

        $fontes = [
            ['Contratos', Contrato::class, 'contratos.show', 'numero', ['descricao'], null, null],
            ['Títulos a pagar', Titulo::class, 'financeiro.titulo', 'descricao', ['contraparte'], null, 'pagar'],
            ['Títulos a receber', Titulo::class, 'financeiro.titulo', 'descricao', ['contraparte'], null, 'receber'],
            ['Compras e fornecedores', Compra::class, 'compras.show', 'descricao', ['fornecedor'], 'fornecedor_documento', null],
            ['Cartões', Cartao::class, 'cartoes.show', 'nome', ['instituicao'], null, null],
            ['Documentos anexados', Anexo::class, 'anexos.baixar', 'nome_original', [], null, null],
        ];
        if (ctx()->tem(Papel::Admin)) {
            $fontes = [
                ['Clientes', Cliente::class, 'clientes.show', 'razao_social', ['nome_fantasia'], 'documento', null],
                ['Prestadores', Prestador::class, 'prestadores.edit', 'nome', [], 'documento', null],
                ...$fontes,
            ];
        }

        $id = ltrim($termo, '#');
        $id = ctype_digit($id) && strlen($id) <= 18 ? (int) $id : null;
        $prefixo = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termo).'%';
        $parcial = '%'.$prefixo;
        foreach ($fontes as [$grupo, $modelo, $rota, $titulo, $extras, $colunaDocumento, $tipo]) {
            $query = $modelo::query()->select('id', $titulo);
            if ($tipo !== null) {
                $query->where('tipo', $tipo);
            }
            if ($modelo === Anexo::class) {
                $query->whereHasMorph('anexavel', [Compra::class, Titulo::class]);
            }
            $query->where(function (Builder $q) use ($titulo, $extras, $colunaDocumento, $documento, $id, $parcial) {
                foreach ([$titulo, ...$extras] as $coluna) {
                    $q->orWhereRaw("$coluna LIKE ? ESCAPE '!'", [$parcial]);
                }
                if ($id !== null) {
                    $q->orWhereKey($id);
                }
                if ($colunaDocumento !== null && $documento !== '') {
                    $q->orWhere($colunaDocumento, $documento);
                }
            });
            if ($id !== null) {
                $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$id]);
            }
            if ($colunaDocumento !== null && $documento !== '') {
                $query->orderByRaw("CASE WHEN $colunaDocumento = ? THEN 0 ELSE 1 END", [$documento]);
            }

            $grupos[$grupo] = $query->orderByRaw("CASE WHEN $titulo = ? THEN 0 WHEN $titulo LIKE ? ESCAPE '!' THEN 1 ELSE 2 END", [$termo, $prefixo])
                ->orderBy($titulo)->orderBy('id')->limit(self::LIMITE)->get()
                ->map(fn ($registro) => [$registro->{$titulo}, route($rota, $registro->id), '#'.$registro->id])->all();
        }

        $grupos['Faturas'] = Fatura::query()->select('id', 'contrato_id', 'competencia')->with('contrato:id,numero')
            ->where(function (Builder $q) use ($id, $parcial) {
                $q->whereHas('contrato', fn ($c) => $c->whereRaw("numero LIKE ? ESCAPE '!'", [$parcial])
                    ->orWhereHas('cliente', fn ($cliente) => $cliente->whereRaw("razao_social LIKE ? ESCAPE '!'", [$parcial])));
                if ($id !== null) {
                    $q->orWhereKey($id);
                }
            })->when($id !== null, fn ($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$id]))
            ->orderByDesc('id')->limit(self::LIMITE)->get()
            ->map(fn (Fatura $fatura) => ['Fatura #'.$fatura->id, route('faturas.show', $fatura), $fatura->contrato->numero.' · '.$fatura->competencia->format('m/Y')])->all();

        $grupos['Notas fiscais'] = NotaFiscal::query()->select('id', 'numero', 'competencia')
            ->where(function ($q) use ($termo, $id, $parcial) {
                $q->where('numero', $termo)->orWhere('chave_acesso', $termo)
                    ->orWhereRaw("emitente_nome LIKE ? ESCAPE '!'", [$parcial])
                    ->orWhereRaw("tomador_nome LIKE ? ESCAPE '!'", [$parcial]);
                if ($id !== null) {
                    $q->orWhereKey($id);
                }
            })->latest('id')->limit(self::LIMITE)->get()
            ->map(fn ($nota) => ['Nota fiscal '.($nota->numero ?: '#'.$nota->id), route('notas.index', ['nota' => $nota->id]), $nota->competencia?->format('m/Y')])->all();

        $grupos['Movimentações bancárias'] = ExtratoLinha::query()->select('id', 'conta_id', 'descricao', 'data')
            ->whereHas('conta')->where(function ($q) use ($termo, $id, $parcial) {
                $q->where('identificador', $termo)->orWhereRaw("descricao LIKE ? ESCAPE '!'", [$parcial]);
                if ($id !== null) {
                    $q->orWhereKey($id);
                }
            })->orderByDesc('data')->orderByDesc('id')->limit(self::LIMITE)->get()
            ->map(fn ($linha) => [$linha->descricao, route('financeiro.conciliacao', ['conta' => $linha->conta_id, 'linha' => $linha->id, 'mostrar' => 'todas']), $linha->data->format('d/m/Y')])->all();

        return array_filter($grupos);
    }
}
