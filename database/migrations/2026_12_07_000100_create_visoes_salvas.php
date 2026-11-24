<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visoes_salvas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('modulo', 24);
            $table->string('nome', 60);
            $table->json('filtros');
            $table->timestamps();
            $table->unique(['organizacao_id', 'user_id', 'modulo', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visoes_salvas');
    }
};
