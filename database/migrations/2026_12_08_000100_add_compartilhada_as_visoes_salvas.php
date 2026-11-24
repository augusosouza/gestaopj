<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visoes_salvas', function (Blueprint $table) {
            $table->boolean('compartilhada')->default(false);
            $table->index(['organizacao_id', 'modulo', 'compartilhada'], 'visoes_equipe_idx');
        });
    }

    public function down(): void
    {
        Schema::table('visoes_salvas', function (Blueprint $table) {
            $table->dropIndex('visoes_equipe_idx');
            $table->dropColumn('compartilhada');
        });
    }
};
