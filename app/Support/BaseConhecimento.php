<?php

namespace App\Support;

use App\Enums\Papel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class BaseConhecimento
{
    public static function categorias(): array
    {
        return config('conhecimento.categorias');
    }

    public static function artigos(): array
    {
        return config('conhecimento.artigos');
    }

    public static function filtrar(string $busca, ?string $categoria): array
    {
        $termos = preg_split('/\s+/', self::normalizar(trim($busca)), -1, PREG_SPLIT_NO_EMPTY);

        return array_filter(self::artigos(), function (array $artigo) use ($categoria, $termos) {
            if ($categoria !== null && $artigo['categoria'] !== $categoria) {
                return false;
            }
            $texto = self::normalizar(implode(' ', [
                $artigo['titulo'], self::categorias()[$artigo['categoria']], $artigo['resumo'], $artigo['quem'],
                $artigo['termos'], ...$artigo['passos'],
            ]));

            return collect($termos)->every(fn (string $termo) => str_contains($texto, $termo));
        });
    }

    public static function contextual(Request $request): ?string
    {
        $detalhes = [
            'contratos.*' => 'contratos',
            'faturas.*' => 'faturamento',
            'financeiro.titulo' => 'lancamentos',
            'compras.*' => 'compras',
            'cartoes.*' => 'cartoes',
            'financeiro.conta' => 'extratos',
            'financeiro.conciliacao' => 'extratos',
            'relatorios.*' => 'relatorios',
            'contabil.*' => 'fechamento',
        ];
        foreach ($detalhes as $rota => $slug) {
            if ($request->routeIs($rota)) {
                return $slug;
            }
        }

        foreach (self::artigos() as $slug => $artigo) {
            if ($artigo['tela'] && $request->routeIs($artigo['tela'])) {
                return $slug;
            }
        }

        return null;
    }

    public static function destino(array $artigo, Contexto $contexto, User $usuario): ?string
    {
        $tela = $artigo['destino'] ?? $artigo['tela'];
        if (! $tela) {
            return null;
        }
        if (in_array($tela, ['seguranca', 'contexto.escolher', 'notificacoes.index'], true)) {
            return route($tela);
        }

        $global = str_starts_with($tela, 'plataforma.');
        $exige2fa = $global ? $usuario->admin_plataforma : $contexto->tem(Papel::Admin, Papel::Revisor);
        if (config('gestao.exigir_2fa') && $exige2fa && ! $usuario->temDoisFatores()) {
            return null;
        }
        if ($contexto->empresa() && $contexto->tem(Papel::Admin, Papel::Financeiro)
            && in_array($tela, ['configuracoes.inteligencia', 'configuracoes.vencimentos', 'configuracoes.lembretes', 'integracoes.google'], true)) {
            return route($tela);
        }

        $menu = Menu::para($contexto, $usuario);
        $itens = [$menu['inicio'], $menu['config'], ...array_merge([], ...array_column($menu['grupos'], 'itens'))];
        $url = route($tela);

        return collect($itens)->contains(fn ($item) => ($item['url'] ?? null) === $url) ? $url : null;
    }

    private static function normalizar(string $texto): string
    {
        return mb_strtolower(Str::ascii($texto, 'pt'));
    }
}
