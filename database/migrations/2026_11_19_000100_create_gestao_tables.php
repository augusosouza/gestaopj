<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domínio da fase 1 (piloto): contratos, notas fiscais e pagamentos.
 *
 * Valores monetários são armazenados em centavos (inteiros) para evitar
 * erros de arredondamento em somas e na conferência de notas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CNPJs da própria empresa (prestadora do serviço ao cliente / tomadora dos contratados).
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('razao_social');
            $table->string('cnpj', 14)->unique();
            $table->string('inscricao_municipal')->nullable();
            $table->string('municipio_ibge', 7)->nullable();
            $table->timestamps();
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('razao_social');
            $table->string('documento', 14)->unique(); // CNPJ (14) ou CPF (11)
            $table->string('email')->nullable();
            $table->string('municipio_ibge', 7)->nullable();
            $table->timestamps();
        });

        Schema::create('prestadores', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('tipo', 3); // PJ, MEI, PF
            $table->string('documento', 14)->unique();
            $table->string('email')->nullable();
            $table->string('chave_pix')->nullable();
            $table->timestamps();
        });

        // Perfil fiscal por CNPJ e vigência, confirmado pela contabilidade.
        Schema::create('perfis_fiscais', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj', 14)->index();
            $table->string('regime', 40); // ver App\Enums\RegimeTributario
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_em')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();
        });

        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained()->restrictOnDelete();
            $table->string('numero')->unique();
            $table->string('descricao');
            $table->string('codigo_servico', 6)->nullable(); // cTribNac (LC 116)
            $table->unsignedBigInteger('valor_mensal_centavos');
            $table->unsignedTinyInteger('dia_vencimento')->default(10);
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->string('status', 20)->default('ativo'); // ativo, suspenso, encerrado
            $table->timestamps();
        });

        // Alocação de um contratado em um contrato, com seu custo mensal.
        Schema::create('alocacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prestador_id')->constrained('prestadores')->restrictOnDelete();
            $table->string('descricao')->nullable();
            $table->unsignedBigInteger('valor_mensal_centavos');
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->timestamps();
        });

        Schema::create('notas_fiscais', function (Blueprint $table) {
            $table->id();
            $table->string('direcao', 10); // emitida (pela empresa) | recebida (de prestador)
            $table->string('chave_acesso', 50)->unique();
            $table->string('numero')->nullable();
            $table->string('emitente_documento', 14)->index();
            $table->string('emitente_nome')->nullable();
            $table->string('tomador_documento', 14)->nullable()->index();
            $table->string('tomador_nome')->nullable();
            $table->date('competencia')->nullable();
            $table->dateTime('emitida_em')->nullable();
            $table->string('codigo_servico', 6)->nullable();
            $table->text('descricao_servico')->nullable();
            $table->unsignedBigInteger('valor_servicos_centavos')->nullable();
            $table->unsignedBigInteger('valor_liquido_centavos')->nullable();
            $table->string('ambiente', 1)->nullable(); // 1 = produção, 2 = produção restrita
            $table->string('origem', 10); // xml | chave
            $table->string('xml_path')->nullable();
            $table->string('xml_sha256', 64)->nullable();
            $table->foreignId('enviada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Contas a receber: uma fatura por contrato e competência.
        Schema::create('faturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained()->restrictOnDelete();
            $table->date('competencia');
            $table->unsignedBigInteger('valor_centavos');
            $table->date('vencimento');
            $table->string('status', 20)->default('aberta'); // aberta, emitida, recebida, cancelada
            $table->foreignId('nota_fiscal_id')->nullable()->unique()->constrained('notas_fiscais')->nullOnDelete();
            $table->unsignedBigInteger('valor_recebido_centavos')->nullable();
            $table->date('recebida_em')->nullable();
            $table->timestamps();
            $table->unique(['contrato_id', 'competencia']);
        });

        // Contas a pagar: uma cobrança por alocação e competência.
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alocacao_id')->constrained('alocacoes')->restrictOnDelete();
            $table->foreignId('prestador_id')->constrained('prestadores')->restrictOnDelete();
            $table->foreignId('contrato_id')->constrained()->restrictOnDelete();
            $table->date('competencia');
            $table->unsignedBigInteger('valor_centavos');
            // aguardando_nota, em_conferencia, aprovada, rejeitada, paga
            $table->string('status', 20)->default('aguardando_nota');
            $table->foreignId('nota_fiscal_id')->nullable()->unique()->constrained('notas_fiscais')->nullOnDelete();
            $table->json('conferencia')->nullable(); // resultado do checklist automático
            $table->text('motivo_rejeicao')->nullable();
            $table->foreignId('aprovada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovada_em')->nullable();
            $table->unsignedBigInteger('valor_pago_centavos')->nullable();
            $table->date('paga_em')->nullable();
            $table->timestamps();
            $table->unique(['alocacao_id', 'competencia']);
        });

        // Trilha de auditoria simples: quem fez o quê, quando.
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('acao', 60);
            $table->morphs('alvo');
            $table->json('dados')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('users', function (Blueprint $table) {
            // admin (empresa), financeiro, contabilidade, prestador
            $table->string('papel', 20)->default('prestador')->after('email');
            $table->foreignId('prestador_id')->nullable()->after('papel')->constrained('prestadores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prestador_id');
            $table->dropColumn('papel');
        });
        foreach (['eventos', 'cobrancas', 'faturas', 'notas_fiscais', 'alocacoes', 'contratos', 'perfis_fiscais', 'prestadores', 'clientes', 'empresas'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
