<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PDF (DANFSe) gerado pelo componente oficial da Receita, com a versão usada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->string('danfse_path')->nullable()->after('xml_sha256');
            $table->string('danfse_versao', 60)->nullable()->after('danfse_path');
            $table->timestamp('danfse_gerado_em')->nullable()->after('danfse_versao');
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->dropColumn(['danfse_path', 'danfse_versao', 'danfse_gerado_em']));
    }
};
