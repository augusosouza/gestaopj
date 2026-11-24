<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_conexoes', function (Blueprint $table) {
            $table->string('google_subject')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('google_conexoes', function (Blueprint $table) {
            $table->dropColumn('google_subject');
        });
    }
};
