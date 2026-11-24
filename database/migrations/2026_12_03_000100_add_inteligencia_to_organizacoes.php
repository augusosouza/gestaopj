<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizacoes', function (Blueprint $table) {
            $table->boolean('assistente_ativo')->default(true);
            $table->boolean('sugestoes_ativas')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('organizacoes', function (Blueprint $table) {
            $table->dropColumn(['assistente_ativo', 'sugestoes_ativas']);
        });
    }
};
