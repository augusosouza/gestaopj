<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-REM-01 — Central de Vencimentos e Google Agenda.
 *
 * - lembrete_configs: quando avisar (dias antes, horário, canais). titulo_id nulo = padrão
 *   do usuário para o tipo (pagar | receber | cartao); com titulo_id = ajuste daquela obrigação.
 * - google_conexoes: autorização OAuth de UM usuário em UMA empresa (tokens criptografados).
 * - google_eventos: qual evento do Google representa cada parcela/fatura (evita duplicidade
 *   e permite atualizar o mesmo evento quando o vencimento muda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lembrete_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 10); // pagar | receber | cartao
            $table->foreignId('titulo_id')->nullable()->constrained('titulos')->cascadeOnDelete();
            $table->boolean('ativo')->default(true);
            $table->json('dias'); // ex.: [15, 10, 5, 1, 0]
            $table->string('hora', 5)->default('09:00');
            $table->json('canais_google')->nullable(); // ["popup", "email"]
            $table->timestamps();
            $table->unique(['organizacao_id', 'user_id', 'tipo', 'titulo_id'], 'lembrete_configs_unico');
        });

        Schema::create('google_conexoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email_google')->nullable();
            $table->text('access_token')->nullable();   // criptografado (cast encrypted)
            $table->text('refresh_token')->nullable();  // criptografado
            $table->timestamp('token_expira_em')->nullable();
            $table->string('escopos', 500)->nullable();
            $table->string('modo', 12)->default('dedicada'); // dedicada | existente
            $table->string('calendar_id')->nullable();
            $table->string('calendar_nome')->nullable();
            $table->json('sincronizar')->nullable(); // {"pagar":true,"receber":false,"cartao":true}
            $table->boolean('titulos_discretos')->default(true);
            $table->string('ao_quitar', 10)->default('remover'); // remover | marcar
            $table->string('status', 12)->default('ativa'); // ativa | revogada | erro
            $table->text('ultimo_erro')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamp('pausada_ate')->nullable(); // limite de requisições: espera antes de tentar de novo
            $table->timestamps();
            $table->unique(['organizacao_id', 'user_id']);
        });

        Schema::create('google_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('google_conexao_id')->constrained('google_conexoes')->cascadeOnDelete();
            $table->string('alvo', 10); // parcela | fatura
            $table->unsignedBigInteger('alvo_id');
            $table->string('event_id');
            $table->string('hash', 64); // estado enviado (data, lembretes, título): só reenvia se mudar
            $table->string('estado', 10)->default('ativo'); // ativo | quitado
            $table->timestamp('sincronizado_em')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();
            $table->unique(['google_conexao_id', 'alvo', 'alvo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_eventos');
        Schema::dropIfExists('google_conexoes');
        Schema::dropIfExists('lembrete_configs');
    }
};
