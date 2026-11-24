<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizacoes', function (Blueprint $table) {
            $table->boolean('ia_radar_ativa')->default(false);
            $table->text('ia_radar_chave')->nullable();
            $table->unsignedSmallInteger('ia_radar_limite')->default(20);
        });
    }

    public function down(): void
    {
        Schema::table('organizacoes', fn (Blueprint $table) => $table->dropColumn(['ia_radar_ativa', 'ia_radar_chave', 'ia_radar_limite']));
    }
};
