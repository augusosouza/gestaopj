<?php

namespace Database\Seeders;

use App\Enums\Papel;
use App\Enums\RegimeTributario;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\PerfilFiscal;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dados do piloto: 1 empresa, 1 cliente, 1 contrato de R$ 12.000/mês e 1 prestador PJ.
 * Senha de todos os acessos de demonstração: "piloto-2026" (troque em produção).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = Empresa::create(['razao_social' => 'Minha Empresa de Suporte e Consultoria Ltda', 'cnpj' => '11222333000181', 'municipio_ibge' => '3550308']);
        $cliente = Cliente::create(['razao_social' => 'Cliente Exemplo S.A.', 'documento' => '11444777000161', 'email' => 'financeiro@cliente.example']);
        $prestador = Prestador::create(['nome' => 'Consultor Exemplo ME', 'tipo' => 'PJ', 'documento' => '45723174000110', 'email' => 'consultor@prestador.example', 'chave_pix' => 'consultor@prestador.example']);

        $contrato = Contrato::create([
            'empresa_id' => $empresa->id, 'cliente_id' => $cliente->id, 'numero' => 'CT-2026-001',
            'descricao' => 'Suporte técnico e consultoria em TI', 'codigo_servico' => '010701',
            'valor_mensal_centavos' => 1_200_000, 'dia_vencimento' => 10, 'inicio' => '2026-01-01', 'status' => 'ativo',
        ]);
        $contrato->alocacoes()->create(['prestador_id' => $prestador->id, 'descricao' => 'Analista de suporte N2', 'valor_mensal_centavos' => 750_000, 'inicio' => '2026-01-01']);

        PerfilFiscal::create(['cnpj' => $empresa->cnpj, 'regime' => RegimeTributario::Simples, 'vigencia_inicio' => '2026-01-01', 'observacao' => 'Exemplo — confirmar com a contabilidade']);
        PerfilFiscal::create(['cnpj' => $prestador->documento, 'regime' => RegimeTributario::Simples, 'vigencia_inicio' => '2026-01-01']);

        $senha = 'piloto-2026';
        User::create(['name' => 'Administrador', 'email' => 'admin@gestaopj.local', 'password' => $senha, 'papel' => Papel::Admin]);
        User::create(['name' => 'Financeiro', 'email' => 'financeiro@gestaopj.local', 'password' => $senha, 'papel' => Papel::Financeiro]);
        User::create(['name' => 'Contabilidade', 'email' => 'contabilidade@gestaopj.local', 'password' => $senha, 'papel' => Papel::Contabilidade]);
        User::create(['name' => 'Consultor Exemplo', 'email' => 'prestador@gestaopj.local', 'password' => $senha, 'papel' => Papel::Prestador, 'prestador_id' => $prestador->id]);
    }
}
