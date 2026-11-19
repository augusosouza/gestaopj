<?php

namespace Database\Seeders;

use App\Enums\Papel;
use App\Enums\RegimeTributario;
use App\Enums\TipoOrganizacao;
use App\Models\Atribuicao;
use App\Models\AutorizacaoEscritorio;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Organizacao;
use App\Models\PerfilFiscal;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Vinculo;
use Illuminate\Database\Seeder;

/**
 * Cenário de aceite da entrega 1: duas empresas e um escritório.
 *
 * - Empresa A: contrato de R$ 12.000/mês com um prestador PJ de R$ 7.500.
 * - Empresa B: outro contrato, para provar o isolamento.
 * - Escritório: autorizado por A e B. A revisora vê as duas; o analista só A.
 * - O prestador atende as duas empresas com o mesmo login.
 *
 * Senha de todos os acessos de demonstração: "piloto-2026" (troque em produção).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $inicio = '2026-01-01';
        $senha = 'piloto-2026';

        $a = Organizacao::create(['tipo' => TipoOrganizacao::Empresa, 'razao_social' => 'Minha Empresa de Suporte e Consultoria Ltda', 'cnpj' => '11222333000181', 'municipio_ibge' => '3550308']);
        $b = Organizacao::create(['tipo' => TipoOrganizacao::Empresa, 'razao_social' => 'Outra Empresa de Serviços Ltda', 'cnpj' => '22333444000181', 'municipio_ibge' => '3304557']);
        $esc = Organizacao::create(['tipo' => TipoOrganizacao::Escritorio, 'razao_social' => 'Escritório Contábil Exemplo', 'cnpj' => '33444555000181']);

        // Empresa A
        $clienteA = Cliente::create(['organizacao_id' => $a->id, 'razao_social' => 'Cliente Exemplo S.A.', 'documento' => '11444777000161', 'email' => 'financeiro@cliente.example']);
        $prestA = Prestador::create(['organizacao_id' => $a->id, 'nome' => 'Consultor Exemplo ME', 'tipo' => 'PJ', 'documento' => '45723174000110', 'email' => 'consultor@prestador.example', 'chave_pix' => 'consultor@prestador.example']);
        $ctA = Contrato::create([
            'organizacao_id' => $a->id, 'cliente_id' => $clienteA->id, 'numero' => 'CT-2026-001',
            'descricao' => 'Suporte técnico e consultoria em TI', 'codigo_servico' => '010701',
            'valor_mensal_centavos' => 1_200_000, 'dia_vencimento' => 10, 'inicio' => $inicio, 'status' => 'ativo',
        ]);
        $ctA->alocacoes()->create(['organizacao_id' => $a->id, 'prestador_id' => $prestA->id, 'descricao' => 'Analista de suporte N2', 'valor_mensal_centavos' => 750_000, 'inicio' => $inicio]);
        PerfilFiscal::create(['organizacao_id' => $a->id, 'cnpj' => $a->cnpj, 'regime' => RegimeTributario::Simples, 'vigencia_inicio' => $inicio, 'observacao' => 'Exemplo — confirmar com a contabilidade']);
        PerfilFiscal::create(['organizacao_id' => $a->id, 'cnpj' => $prestA->documento, 'regime' => RegimeTributario::Simples, 'vigencia_inicio' => $inicio]);

        // Empresa B (o mesmo prestador também atende B)
        $clienteB = Cliente::create(['organizacao_id' => $b->id, 'razao_social' => 'Cliente da Outra S.A.', 'documento' => '55666777000181']);
        $prestB = Prestador::create(['organizacao_id' => $b->id, 'nome' => 'Consultor Exemplo ME', 'tipo' => 'PJ', 'documento' => '45723174000110', 'email' => 'consultor@prestador.example']);
        $ctB = Contrato::create([
            'organizacao_id' => $b->id, 'cliente_id' => $clienteB->id, 'numero' => 'CT-B-001',
            'descricao' => 'Consultoria em processos', 'valor_mensal_centavos' => 800_000, 'dia_vencimento' => 15, 'inicio' => $inicio, 'status' => 'ativo',
        ]);
        $ctB->alocacoes()->create(['organizacao_id' => $b->id, 'prestador_id' => $prestB->id, 'descricao' => 'Consultor', 'valor_mensal_centavos' => 500_000, 'inicio' => $inicio]);

        // Pessoas e vínculos
        $u = fn (string $nome, string $email) => User::create(['name' => $nome, 'email' => $email, 'password' => $senha]);
        $vinc = fn (User $user, Organizacao $org, Papel $papel, ?Prestador $p = null) => Vinculo::create([
            'user_id' => $user->id, 'organizacao_id' => $org->id, 'papel' => $papel, 'prestador_id' => $p?->id, 'inicio' => $inicio,
        ]);

        $vinc($u('Administrador A', 'admin@gestaopj.local'), $a, Papel::Admin);
        $vinc($u('Financeiro A', 'financeiro@gestaopj.local'), $a, Papel::Financeiro);
        $vinc($u('Administrador B', 'admin.b@gestaopj.local'), $b, Papel::Admin);
        $consultor = $u('Consultor Exemplo', 'prestador@gestaopj.local');
        $vinc($consultor, $a, Papel::Prestador, $prestA);
        $vinc($consultor, $b, Papel::Prestador, $prestB);

        $revisora = $u('Revisora do Escritório', 'revisor@gestaopj.local');
        $analista = $u('Analista Contábil', 'contabilidade@gestaopj.local');
        $vinc($revisora, $esc, Papel::Revisor);
        $vinc($analista, $esc, Papel::Analista);

        foreach ([$a, $b] as $empresa) {
            AutorizacaoEscritorio::create(['empresa_id' => $empresa->id, 'escritorio_id' => $esc->id, 'inicio' => $inicio]);
        }
        Atribuicao::create(['escritorio_id' => $esc->id, 'empresa_id' => $a->id, 'user_id' => $analista->id, 'inicio' => $inicio, 'criado_por' => $revisora->id]);
    }
}
