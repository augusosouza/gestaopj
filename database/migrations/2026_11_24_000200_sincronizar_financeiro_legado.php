<?php

use App\Services\Financeiro;
use Illuminate\Database\Migrations\Migration;

/**
 * Compatibilidade: faturas e cobranças existentes ganham títulos, e recebimentos/pagamentos
 * já registrados viram baixas. Idempotente (também disponível em `php artisan financeiro:sincronizar`).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(Financeiro::class)->sincronizarLegado();
    }

    public function down(): void
    {
        // Os títulos migrados permanecem: apagá-los perderia o histórico financeiro.
    }
};
