<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de backups da plataforma (base semanal + incrementais).
 * O pacote remoto é autossuficiente: a restauração funciona mesmo sem este banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120)->unique(); // nome do pacote no destino
            $table->string('tipo', 12); // base | incremental
            $table->string('base_nome', 120)->nullable(); // base da cadeia
            $table->string('status', 12); // executando | concluido | falhou | removido
            $table->string('destino', 20);
            $table->string('acionado_por', 20); // agenda | manual
            $table->unsignedBigInteger('tamanho_bytes')->nullable();
            $table->unsignedInteger('arquivos_enviados')->nullable();
            $table->unsignedInteger('arquivos_total')->nullable();
            $table->unsignedInteger('arquivos_removidos')->nullable();
            $table->unsignedInteger('tabelas')->nullable();
            $table->unsignedBigInteger('linhas')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('iniciado_em');
            $table->timestamp('concluido_em')->nullable();
            $table->index(['status', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
