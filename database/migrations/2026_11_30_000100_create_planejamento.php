<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Augi 3.0, etapa 8 — Planejamento.
 *  - Subcategorias: pai_id na tabela de categorias que já existe (sem tabela nova).
 *  - Centros de custo: departamento, projeto, contrato ou filial.
 *  - Rateio: como o valor de um lançamento se divide entre centros. A soma é SEMPRE
 *    igual ao valor do lançamento (centavos distribuídos pelo maior resto).
 *  - Orçamentos: limite mensal por categoria (inclui subcategorias) ou por centro de custo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias_financeiras', function (Blueprint $table) {
            $table->foreignId('pai_id')->nullable()->after('tipo')->constrained('categorias_financeiras')->nullOnDelete();
        });

        Schema::create('centros_custo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('nome', 80);
            $table->string('codigo', 20)->nullable();
            $table->string('tipo', 12)->default('departamento'); // departamento | projeto | contrato | filial
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['organizacao_id', 'nome']);
        });

        Schema::create('titulo_rateios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('titulo_id')->constrained('titulos')->cascadeOnDelete();
            $table->foreignId('centro_custo_id')->constrained('centros_custo')->restrictOnDelete();
            $table->unsignedInteger('percentual_bp'); // pontos-base: 10000 = 100%
            $table->unsignedBigInteger('valor_centavos');
            $table->timestamps();
            $table->unique(['titulo_id', 'centro_custo_id']);
        });

        Schema::create('orcamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->date('competencia'); // 1º dia do mês
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_financeiras')->cascadeOnDelete();
            $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo')->cascadeOnDelete();
            $table->unsignedBigInteger('valor_centavos');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organizacao_id', 'competencia', 'categoria_id', 'centro_custo_id'], 'orcamentos_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamentos');
        Schema::dropIfExists('titulo_rateios');
        Schema::dropIfExists('centros_custo');
        Schema::table('categorias_financeiras', fn (Blueprint $t) => $t->dropConstrainedForeignId('pai_id'));
    }
};
