<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alteracoes_pix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes');
            $table->foreignId('prestador_id')->constrained('prestadores');
            $table->foreignId('solicitado_por')->constrained('users');
            $table->foreignId('decidido_por')->nullable()->constrained('users');
            $table->text('chave_anterior')->nullable();
            $table->text('chave_proposta')->nullable();
            $table->string('estado', 16)->default('pendente');
            $table->string('motivo', 500);
            $table->string('justificativa_decisao', 500)->nullable();
            $table->timestamp('decidido_em')->nullable();
            $table->timestamps();
            $table->index(['organizacao_id', 'prestador_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alteracoes_pix');
    }
};
