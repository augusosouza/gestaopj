<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro 2.0 — compras, cartões, transferências e decisões.
 *
 * Regra de ouro: uma obrigação = um título com parcelas. Compras e cartões
 * NÃO criam um segundo registro financeiro:
 *  - a compra é a origem de um título a pagar (titulos.origem = compra);
 *  - a compra no cartão tem parcelas ligadas às faturas (parcelas.fatura_cartao_id);
 *    pagar a fatura = baixar essas parcelas. Encargos viram um título próprio.
 *  - transferência entre contas não é receita nem despesa: mexe só no saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('nome', 80);
            $table->string('instituicao', 80)->nullable();
            $table->string('final', 4)->nullable(); // só os 4 últimos dígitos, para reconhecer
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('limite_centavos')->default(0);
            $table->unsignedTinyInteger('dia_fechamento');
            $table->unsignedTinyInteger('dia_vencimento');
            $table->foreignId('conta_pagamento_id')->nullable()->constrained('contas_financeiras')->nullOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        // Ciclo de cobrança do cartão. "referencia" = mês do vencimento.
        Schema::create('faturas_cartao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('cartao_id')->constrained('cartoes')->restrictOnDelete();
            $table->date('referencia');
            $table->date('fechamento');
            $table->date('vencimento');
            $table->date('paga_em')->nullable();
            $table->foreignId('paga_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('encargos_titulo_id')->nullable()->constrained('titulos')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cartao_id', 'referencia']);
        });

        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('descricao');
            $table->string('fornecedor')->nullable();
            $table->string('fornecedor_documento', 14)->nullable();
            $table->date('data_compra');
            $table->unsignedBigInteger('valor_bruto_centavos');
            $table->unsignedBigInteger('desconto_centavos')->default(0);
            $table->unsignedBigInteger('juros_centavos')->default(0);
            $table->unsignedBigInteger('total_centavos');
            $table->unsignedBigInteger('entrada_centavos')->default(0);
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_financeiras')->nullOnDelete();
            $table->string('modalidade', 20); // ver App\Models\Compra::MODALIDADES
            $table->foreignId('conta_id')->nullable()->constrained('contas_financeiras')->nullOnDelete();
            $table->foreignId('cartao_id')->nullable()->constrained('cartoes')->restrictOnDelete();
            $table->string('nota_tipo', 20)->nullable();
            $table->string('nota_numero', 30)->nullable();
            $table->string('nota_chave', 50)->nullable();
            $table->date('garantia_ate')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('cancelada_em')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organizacao_id', 'data_compra']);
        });

        Schema::create('compra_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->string('descricao');
            $table->decimal('quantidade', 12, 3)->default(1);
            $table->unsignedBigInteger('valor_unitario_centavos');
            $table->unsignedBigInteger('total_centavos');
        });

        Schema::table('parcelas', function (Blueprint $table) {
            $table->foreignId('fatura_cartao_id')->nullable()->after('valor_centavos')->constrained('faturas_cartao')->nullOnDelete();
        });

        Schema::table('titulos', function (Blueprint $table) {
            $table->string('forma', 20)->nullable()->after('documento'); // forma prevista: pix, boleto, transferência...
        });

        // Imutável, como os movimentos: estorno = transferência inversa ligada à original.
        Schema::create('transferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('conta_origem_id')->constrained('contas_financeiras')->restrictOnDelete();
            $table->foreignId('conta_destino_id')->constrained('contas_financeiras')->restrictOnDelete();
            $table->date('data');
            $table->unsignedBigInteger('valor_centavos');
            $table->string('descricao')->nullable();
            $table->foreignId('estorno_de_id')->nullable()->unique()->constrained('transferencias')->restrictOnDelete();
            $table->string('chave_idempotencia', 64)->nullable()->unique();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('extrato_linhas', function (Blueprint $table) {
            $table->foreignId('transferencia_id')->nullable()->after('movimento_id')->constrained('transferencias')->nullOnDelete();
        });

        // Último saldo informado pelo banco no extrato (OFX LEDGERBAL): o "saldo confirmado".
        Schema::table('contas_financeiras', function (Blueprint $table) {
            $table->bigInteger('saldo_extrato_centavos')->nullable();
            $table->date('saldo_extrato_em')->nullable();
        });

        // Sugestões que a empresa dispensou (para sempre) ou adiou (até uma data).
        Schema::create('sugestoes_dispensadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('chave', 160);
            $table->date('ate')->nullable();
            $table->string('motivo', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['organizacao_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sugestoes_dispensadas');
        Schema::table('contas_financeiras', fn (Blueprint $t) => $t->dropColumn(['saldo_extrato_centavos', 'saldo_extrato_em']));
        Schema::table('extrato_linhas', fn (Blueprint $t) => $t->dropConstrainedForeignId('transferencia_id'));
        Schema::dropIfExists('transferencias');
        Schema::table('titulos', fn (Blueprint $t) => $t->dropColumn('forma'));
        Schema::table('parcelas', fn (Blueprint $t) => $t->dropConstrainedForeignId('fatura_cartao_id'));
        Schema::dropIfExists('compra_itens');
        Schema::dropIfExists('compras');
        Schema::dropIfExists('faturas_cartao');
        Schema::dropIfExists('cartoes');
    }
};
