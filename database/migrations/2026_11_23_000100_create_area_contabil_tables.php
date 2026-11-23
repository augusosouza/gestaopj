<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Área do contador (entrega 3): competência contábil com etapas, documentos
 * enviados pela empresa, solicitações do escritório e versões do fechamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Uma por empresa e mês. Etapas: coleta → conferencia → revisao → fechada.
        Schema::create('competencias_contabeis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->date('competencia');
            $table->string('etapa', 12)->default('coleta');
            $table->timestamp('enviada_em')->nullable();
            $table->foreignId('enviada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('versao_atual')->default(0);
            $table->timestamps();
            $table->unique(['organizacao_id', 'competencia']);
        });

        // Pedido do contador à empresa (documento ou informação), respondido item a item.
        Schema::create('solicitacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('competencia_contabil_id')->constrained('competencias_contabeis')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->date('prazo')->nullable();
            // aberta → respondida → aceita (ou volta a aberta); cancelada
            $table->string('status', 12)->default('aberta');
            $table->text('resposta')->nullable();
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('respondida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('respondida_em')->nullable();
            $table->foreignId('resolvida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolvida_em')->nullable();
            $table->timestamps();
        });

        // Arquivo enviado pela empresa (extrato, folha, guia...), com hash para integridade.
        Schema::create('documentos_contabeis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('competencia_contabil_id')->constrained('competencias_contabeis')->cascadeOnDelete();
            $table->foreignId('solicitacao_id')->nullable()->constrained('solicitacoes')->nullOnDelete();
            $table->string('categoria', 30);
            $table->string('descricao')->nullable();
            $table->string('nome_original');
            $table->string('arquivo_path');
            $table->unsignedBigInteger('tamanho');
            $table->string('sha256', 64);
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Retrato imutável de cada fechamento. Reabrir e fechar de novo cria a versão seguinte.
        Schema::create('fechamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('competencia_contabil_id')->constrained('competencias_contabeis')->restrictOnDelete();
            $table->unsignedInteger('versao');
            $table->string('arquivo_path');
            $table->string('sha256', 64);
            $table->json('resumo');
            $table->text('observacao')->nullable();
            $table->foreignId('fechado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['competencia_contabil_id', 'versao']);
        });
    }

    public function down(): void
    {
        foreach (['fechamentos', 'documentos_contabeis', 'solicitacoes', 'competencias_contabeis'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
