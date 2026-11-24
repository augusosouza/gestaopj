<?php

namespace App\Support;

use App\Http\Middleware\ExigePapel;
use App\Models\Vinculo;
use Illuminate\Support\Facades\Route;

final class SimuladorAcesso
{
    public const RECURSOS = [
        'bancos' => ['Contas e bancos', [
            'financeiro.contas' => 'Consultar contas',
            'financeiro.conta_salvar' => 'Criar conta e informar saldo inicial',
            'financeiro.conta_alternar' => 'Ativar ou desativar conta',
            'financeiro.importar' => 'Importar extrato',
            'financeiro.conciliar' => 'Conciliar movimento',
        ]],
        'financeiro' => ['Operações financeiras', [
            'contas_pagar.index' => 'Consultar contas a pagar',
            'financeiro.store' => 'Criar lançamento',
            'financeiro.baixar' => 'Registrar pagamento ou recebimento',
            'financeiro.estornar' => 'Registrar estorno',
            'relatorios.exportar' => 'Exportar relatório financeiro',
            'cobrancas.aprovar' => 'Aprovar cobrança de prestador',
        ]],
        'equipe' => ['Equipe e acessos', [
            'usuarios.index' => 'Consultar equipe',
            'usuarios.store' => 'Conceder acesso',
            'usuarios.papel' => 'Alterar papel',
            'usuarios.encerrar' => 'Encerrar acesso',
        ]],
        'contabilidade' => ['Fechamento contábil', [
            'contabil.index' => 'Consultar competências',
            'contabil.enviar_documento' => 'Enviar documento da empresa',
            'contabil.solicitar' => 'Solicitar evidência à empresa',
            'contabil.fechar' => 'Fechar competência',
            'contabil.reabrir' => 'Reabrir competência',
        ]],
    ];

    public function avaliar(Vinculo $vinculo, ?int $empresa, string $recurso): array
    {
        $contexto = new Contexto;
        $valido = $contexto->ativar($vinculo->user, ['vinculo' => $vinculo->id, 'empresa' => $empresa]);

        return collect(self::RECURSOS[$recurso][1])->map(function (string $acao, string $nome) use ($contexto, $valido, $vinculo) {
            $rota = Route::getRoutes()->getByName($nome);
            if (! $rota) {
                return [$acao, false, 'A ação está indisponível nesta versão.'];
            }
            if (! $valido) {
                return [$acao, false, 'O vínculo está encerrado ou a empresa deixou de estar autorizada.'];
            }
            $middleware = $rota->gatherMiddleware();
            if ($contexto->faltaDoisFatores($vinculo->user)) {
                return [$acao, false, 'O perfil precisa ativar a verificação em duas etapas.'];
            }
            if (in_array('contexto:empresa', $middleware, true) && $contexto->organizacaoId() === null) {
                return [$acao, false, 'A ação exige uma empresa ativa e autorizada.'];
            }
            $regras = collect($middleware)->filter(fn (string $regra) => str_starts_with($regra, 'papel:'));
            if ($regras->isEmpty() || ! $regras->every(fn (string $regra) => ExigePapel::permite($contexto, ...explode(',', substr($regra, strlen('papel:')))))) {
                return [$acao, false, 'O papel selecionado não atende à regra de acesso da ação.'];
            }

            return [$acao, true, 'O papel e o contexto atendem à regra de acesso da ação.'];
        })->values()->all();
    }
}
