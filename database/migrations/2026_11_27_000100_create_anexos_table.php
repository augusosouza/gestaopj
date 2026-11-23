<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anexos (comprovantes, notas, boletos) de lançamentos e compras.
 * Mesmo padrão dos documentos do contador: disco privado, por empresa, com sha256.
 * Ver docs/anexos-comparativo.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->morphs('anexavel'); // titulo | compra
            $table->string('descricao', 120)->nullable();
            $table->string('nome_original', 180);
            $table->string('caminho');
            $table->string('mime', 100);
            $table->unsignedBigInteger('tamanho');
            $table->string('sha256', 64);
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anexos');
    }
};
