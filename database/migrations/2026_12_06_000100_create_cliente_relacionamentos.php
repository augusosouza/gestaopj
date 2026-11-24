<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_relacionamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('tipo', 16);
            $table->string('finalidade', 24);
            $table->json('dados');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->index(['organizacao_id', 'cliente_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_relacionamentos');
    }
};
