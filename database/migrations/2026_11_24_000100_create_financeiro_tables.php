<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-01 — Fundação financeira.
 *
 * Três datas, três tabelas: competência (título), vencimento (parcela),
 * liquidação (movimento). Movimentos são imutáveis: estorno é um novo
 * movimento que anula o original, nunca uma edição ou exclusão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_financeiras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('nome', 80);
            $table->string('tipo', 20); // corrente, poupanca, caixa, investimento
            $table->string('banco', 80)->nullable();
            $table->string('identificacao', 40)->nullable(); // agência/conta mascarada, só para reconhecer
            $table->bigInteger('saldo_inicial_centavos')->default(0);
            $table->date('saldo_inicial_em');
            $table->boolean('ativa')->default(true);
            $table->timestamps();
        });

        Schema::create('categorias_financeiras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('tipo', 10); // receita | despesa
            $table->string('nome', 80);
            $table->string('chave', 40)->nullable(); // categorias padrão usadas pela integração
            $table->boolean('ativa')->default(true);
            $table->timestamps();
            $table->unique(['organizacao_id', 'tipo', 'nome']);
        });

        // Obrigação: o que se tem a receber ou a pagar.
        Schema::create('titulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('tipo', 10); // receber | pagar
            $table->string('descricao');
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_financeiras')->nullOnDelete();
            $table->string('contraparte')->nullable();
            $table->date('competencia');
            $table->unsignedBigInteger('valor_total_centavos');
            $table->string('documento', 60)->nullable();
            // Origem operacional (fatura, cobrança). Único: integração nunca duplica.
            $table->nullableMorphs('origem');
            $table->timestamp('cancelado_em')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['origem_type', 'origem_id']);
            $table->index(['organizacao_id', 'tipo', 'competencia']);
        });

        Schema::create('parcelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('titulo_id')->constrained('titulos')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->date('vencimento');
            $table->unsignedBigInteger('valor_centavos');
            $table->timestamps();
            $table->unique(['titulo_id', 'numero']);
            $table->index(['organizacao_id', 'vencimento']);
        });

        // Baixa (pagamento/recebimento) ou estorno. Nunca atualizado nem apagado.
        Schema::create('movimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('parcela_id')->constrained('parcelas')->restrictOnDelete();
            $table->foreignId('conta_id')->constrained('contas_financeiras')->restrictOnDelete();
            $table->string('tipo', 10); // baixa | estorno
            $table->date('data');
            // Dinheiro que entrou/saiu da conta e o que mais abateu a parcela.
            $table->unsignedBigInteger('valor_centavos');
            $table->unsignedBigInteger('desconto_centavos')->default(0);
            $table->unsignedBigInteger('retencao_centavos')->default(0);
            $table->unsignedBigInteger('acrescimo_centavos')->default(0);
            $table->foreignId('estorno_de_id')->nullable()->unique()->constrained('movimentos')->restrictOnDelete();
            $table->text('observacao')->nullable();
            $table->string('chave_idempotencia', 64)->nullable()->unique(); // evita baixa duplicada por duplo envio
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organizacao_id', 'conta_id', 'data']);
        });

        Schema::create('extrato_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('conta_id')->constrained('contas_financeiras')->restrictOnDelete();
            $table->date('data');
            $table->bigInteger('valor_centavos'); // positivo = entrada, negativo = saída
            $table->string('descricao');
            $table->string('identificador', 80); // FITID do OFX ou hash da linha
            $table->string('arquivo')->nullable();
            $table->foreignId('movimento_id')->nullable()->unique()->constrained('movimentos')->nullOnDelete();
            $table->foreignId('conciliado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('conciliado_em')->nullable();
            $table->boolean('ignorada')->default(false);
            $table->timestamps();
            $table->unique(['conta_id', 'identificador']);
        });
    }

    public function down(): void
    {
        foreach (['extrato_linhas', 'movimentos', 'parcelas', 'titulos', 'categorias_financeiras', 'contas_financeiras'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
