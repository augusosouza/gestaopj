<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentos', function (Blueprint $table) {
            $table->boolean('tarifa_separada')->default(false);
            $table->foreignId('tarifa_movimento_id')->nullable()->unique()->constrained('movimentos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentos', function (Blueprint $table) {
            $table->dropForeign(['tarifa_movimento_id']);
            $table->dropUnique(['tarifa_movimento_id']);
            $table->dropColumn(['tarifa_movimento_id', 'tarifa_separada']);
        });
    }
};
