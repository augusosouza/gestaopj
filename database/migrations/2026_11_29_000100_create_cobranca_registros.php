<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de cobrança (etapa 7 do Augi 3.0): contatos com o cliente, promessas de pagamento e
 * renegociações. Promessa NÃO é recebimento: o dinheiro só existe quando há baixa.
 * Registros não são editados nem apagados (trilha de cobrança).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobranca_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('titulo_id')->constrained('titulos')->cascadeOnDelete();
            $table->foreignId('parcela_id')->nullable()->constrained('parcelas')->nullOnDelete();
            $table->string('tipo', 14); // contato | promessa | renegociacao
            $table->string('canal', 12)->nullable(); // telefone | whatsapp | email | presencial | outro
            $table->dateTime('data');
            $table->text('mensagem')->nullable();
            $table->text('resposta')->nullable();
            $table->date('promessa_data')->nullable();
            $table->unsignedBigInteger('promessa_valor_centavos')->nullable();
            $table->json('detalhes')->nullable(); // renegociação: parcelas de/para
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organizacao_id', 'tipo', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobranca_registros');
    }
};
