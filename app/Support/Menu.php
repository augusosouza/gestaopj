<?php

namespace App\Support;

use App\Enums\Papel;
use App\Models\Titulo;
use App\Models\User;

/**
 * Menu lateral hierárquico, montado a partir do papel e do contexto ativo.
 * Regra: só aparece o que a pessoa pode usar e o que já existe no sistema.
 *
 * Cada grupo: ['chave', 'rotulo', 'icone', 'itens' => [[rotulo, url, ativo] | ['secao' => rótulo]]].
 */
final class Menu
{
    /** @return array{inicio: ?array, grupos: list<array>, config: ?array} */
    public static function para(Contexto $c, User $u): array
    {
        $P = Papel::class;
        $r = request();
        $rota = fn (string ...$nomes) => $r->routeIs(...$nomes);
        $tipo = $r->query('tipo');
        // No detalhe de um lançamento, o tipo vem do próprio título (para destacar "Contas a pagar/receber").
        if ($r->routeIs('financeiro.titulo') && ($t = $r->route('titulo')) instanceof Titulo) {
            $tipo = $t->tipo;
        }
        $grupos = [];
        $item = fn (string $rotulo, string $url, bool $ativo, ?int $contador = null) => compact('rotulo', 'url', 'ativo', 'contador');

        $inicio = $c->empresa()
            ? $item($c->ehPrestador() ? 'Minhas cobranças' : 'Início', route('painel'), $rota('painel'))
            : ($c->ehEscritorio() ? $item('Carteira', route('carteira'), $rota('carteira')) : null);

        if ($c->empresa() && $c->ehInterno()) {
            $grupos[] = ['chave' => 'operacao', 'rotulo' => 'Operação', 'icone' => 'operacao', 'itens' => array_values(array_filter([
                $item('Contratos', route('contratos.index'), $rota('contratos.*', 'alocacoes.*')),
                $item('Faturamento e NFS-e', route('faturas.index'), $rota('faturas.*')),
                $item('Pagamentos a prestadores', route('cobrancas.index'), $rota('cobrancas.*')),
                $c->tem($P::Admin) ? $item('Clientes', route('clientes.index'), $rota('clientes.*')) : null,
                $c->tem($P::Admin) ? $item('Prestadores', route('prestadores.index'), $rota('prestadores.*')) : null,
            ]))];

            $lanc = $rota('financeiro.index', 'financeiro.novo', 'financeiro.titulo');
            $secao = fn (string $rotulo) => ['secao' => $rotulo];
            $grupos[] = ['chave' => 'financeiro', 'rotulo' => 'Financeiro', 'icone' => 'financeiro', 'itens' => [
                $secao('Visão geral'),
                $item('Painel financeiro', route('financeiro.painel'), $rota('financeiro.painel')),
                $item('Meu Caixa', route('financeiro.caixa'), $rota('financeiro.caixa')),
                $item('Agenda', route('financeiro.agenda'), $rota('financeiro.agenda')),
                $secao('Movimentações'),
                $item('Lançamentos', route('financeiro.index'), $lanc && ! in_array($tipo, ['receber', 'pagar'], true)),
                $item('Transferências', route('transferencias.index'), $rota('transferencias.*')),
                $secao('Recebimentos'),
                $item('Contas a receber', route('contas_receber.index'), $rota('contas_receber.*') || ($lanc && $tipo === 'receber')),
                $item('Inadimplência', route('financeiro.inadimplencia'), $rota('financeiro.inadimplencia')),
                $secao('Pagamentos'),
                $item('Contas a pagar', route('contas_pagar.index'), $rota('contas_pagar.*') || ($lanc && $tipo === 'pagar')),
                $item('Compras', route('compras.index'), $rota('compras.*')),
                $item('Cartões', route('cartoes.index'), $rota('cartoes.*')),
                $secao('Planejamento e análises'),
                $item('Planejamento', route('planejamento.index'), $rota('planejamento.*')),
                $item('Relatórios', route('relatorios.index'), $rota('relatorios.*')),
                $item('Contas e bancos', route('financeiro.contas'), $rota('financeiro.contas', 'financeiro.conciliacao', 'financeiro.conta*')),
                $item('Categorias', route('financeiro.categorias'), $rota('financeiro.categorias')),
            ]];

            $grupos[] = ['chave' => 'fiscal', 'rotulo' => 'Fiscal', 'icone' => 'fiscal', 'itens' => [
                $item('Notas fiscais', route('notas.index'), $rota('notas.*')),
                $item('Perfis fiscais', route('perfis.index'), $rota('perfis.*')),
            ]];
        }

        $empresa = $c->empresa();
        if ($empresa && $c->ehInterno() && ($empresa->assistente_ativo || $empresa->sugestoes_ativas)) {
            $grupos[] = ['chave' => 'inteligencia', 'rotulo' => 'Inteligência', 'icone' => 'inteligencia', 'itens' => array_values(array_filter([
                $empresa->assistente_ativo ? $item('Assistente', route('inteligencia.assistente'), $rota('inteligencia.assistente')) : null,
                $empresa->sugestoes_ativas ? $item('Sugestões', route('inteligencia.sugestoes'), $rota('inteligencia.sugestoes')) : null,
            ]))];
        }

        $contab = [];
        if ($c->empresa() && $c->ehInterno()) {
            $contab[] = $item($c->ehEscritorio() ? 'Fechamento contábil' : 'Envios ao contador', route('contabil.index'), $rota('contabil.*'));
        }
        if ($c->ehEscritorio()) {
            if ($c->empresa()) {
                $contab[] = $item('Carteira', route('carteira'), $rota('carteira'));
            }
            if ($c->tem($P::Revisor)) {
                $contab[] = $item('Atribuições', route('escritorio.atribuicoes'), $rota('escritorio.*'));
            }
        }
        if ($contab) {
            $grupos[] = ['chave' => 'contabilidade', 'rotulo' => 'Contabilidade', 'icone' => 'contabilidade', 'itens' => $contab];
        }

        if ($c->tem($P::Admin, $P::Revisor)) {
            $grupos[] = ['chave' => 'administracao', 'rotulo' => 'Administração', 'icone' => 'administracao', 'itens' => array_values(array_filter([
                $c->empresa() && $c->tem($P::Admin) ? $item('Empresa', route('organizacao.show'), $rota('organizacao.*')) : null,
                $c->empresa() && $c->tem($P::Admin) ? $item('Governança', route('governanca.index'), $rota('governanca.index')) : null,
                $item('Equipe e acessos', route('usuarios.index'), $rota('usuarios.*')),
            ]))];
        }

        if ($u->admin_plataforma) {
            $grupos[] = ['chave' => 'augi', 'rotulo' => 'Administração Augi', 'icone' => 'plataforma', 'itens' => [
                $item('Identidade', route('plataforma.configuracoes'), $rota('plataforma.configuracoes', 'plataforma.salvar')),
                $item('E-mail e SMTP', route('plataforma.smtp'), $rota('plataforma.smtp*')),
                $item('Backups', route('plataforma.backups'), $rota('plataforma.backups*')),
                $item('Saúde do sistema', route('plataforma.saude'), $rota('plataforma.saude*')),
            ]];
        }

        $config = $c->empresa() && $c->tem($P::Admin, $P::Financeiro)
            ? $item('Configurações', route('configuracoes'), $rota('configuracoes*')) : null;

        foreach ($grupos as &$g) {
            $g['ativo'] = collect($g['itens'])->contains('ativo', true);
        }

        return ['inicio' => $inicio, 'grupos' => $grupos, 'config' => $config];
    }
}
