<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('nome_fantasia')->nullable();
            $table->string('telefone', 32)->nullable();
            $table->index(['organizacao_id', 'razao_social', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['organizacao_id', 'razao_social', 'id']);
            $table->dropColumn(['nome_fantasia', 'telefone']);
        });
    }
};
