<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Augi 3.0, etapa 9 — DRE gerencial: em que linha da DRE cada categoria entra.
 * Nulo = herda da categoria-mãe; sem mãe, receita → receita bruta, despesa → despesas operacionais.
 * Preenche as categorias padrão conhecidas; o usuário pode mudar em Categorias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias_financeiras', function (Blueprint $table) {
            $table->string('grupo_dre', 24)->nullable()->after('pai_id');
        });
        $padrao = ['prestadores' => 'custos', 'tarifas' => 'despesas_financeiras', 'encargos' => 'despesas_financeiras'];
        foreach ($padrao as $chave => $grupo) {
            DB::table('categorias_financeiras')->where('chave', $chave)->update(['grupo_dre' => $grupo]);
        }
        DB::table('categorias_financeiras')->where('nome', 'Rendimentos financeiros')->update(['grupo_dre' => 'receitas_financeiras']);
        DB::table('categorias_financeiras')->where('nome', 'Impostos e taxas')->update(['grupo_dre' => 'deducoes']);
        DB::table('categorias_financeiras')->where('nome', 'Outras receitas')->update(['grupo_dre' => 'outras_receitas']);
    }

    public function down(): void
    {
        Schema::table('categorias_financeiras', fn (Blueprint $t) => $t->dropColumn('grupo_dre'));
    }
};
