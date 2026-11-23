<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-ALERT-01 — lembretes de atualização de extrato por conta, histórico de importações
 * (com o período coberto) e central de notificações interna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table) {
            $table->boolean('lembrete_ativo')->default(false);
            $table->string('lembrete_frequencia', 12)->default('semanal'); // semanal | quinzenal | mensal
            $table->unsignedTinyInteger('lembrete_dia')->default(1); // 1–7 (seg–dom) ou 1–28 (dia do mês)
            $table->string('lembrete_hora', 5)->default('09:00');
            $table->unsignedTinyInteger('lembrete_antecedencia')->default(0); // dias de aviso antes do prazo
            $table->unsignedTinyInteger('lembrete_repetir_dias')->default(2); // repetição enquanto atrasado
            $table->foreignId('lembrete_responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('extrato_ate')->nullable(); // último dia COBERTO por extrato importado
            $table->timestamp('extrato_importado_em')->nullable();
            $table->date('lembrete_dispensado_ate')->nullable();
            $table->text('lembrete_dispensa_motivo')->nullable();
        });

        Schema::create('extrato_importacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('conta_id')->constrained('contas_financeiras')->restrictOnDelete();
            $table->string('arquivo');
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();
            $table->unsignedInteger('linhas_novas');
            $table->unsignedInteger('linhas_repetidas');
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->nullable()->constrained('organizacoes')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->string('titulo');
            $table->text('mensagem');
            $table->string('url')->nullable();
            $table->string('chave', 120); // evita repetir o mesmo aviso
            $table->timestamp('lida_em')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'chave']);
            $table->index(['user_id', 'lida_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
        Schema::dropIfExists('extrato_importacoes');
        Schema::table('contas_financeiras', fn (Blueprint $t) => $t->dropConstrainedForeignId('lembrete_responsavel_id'));
        Schema::table('contas_financeiras', fn (Blueprint $t) => $t->dropColumn(['lembrete_ativo', 'lembrete_frequencia', 'lembrete_dia', 'lembrete_hora',
            'lembrete_antecedencia', 'lembrete_repetir_dias', 'extrato_ate', 'extrato_importado_em', 'lembrete_dispensado_ate', 'lembrete_dispensa_motivo']));
    }
};
