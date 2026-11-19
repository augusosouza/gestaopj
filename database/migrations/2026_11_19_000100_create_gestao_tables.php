<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fundação multiempresa + operação mensal.
 *
 * - Toda tabela operacional carrega organizacao_id: é a fronteira de isolamento
 *   entre empresas (ver App\Models\Concerns\DaOrganizacao).
 * - Acesso nasce de vínculos datados (usuário ↔ organização ↔ papel). O escritório
 *   contábil só enxerga a empresa enquanto houver autorização vigente dela.
 * - Valores monetários em centavos (inteiros): somas e conferências exatas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Gestão da plataforma. Não dá acesso ao conteúdo fiscal das empresas.
            $table->boolean('admin_plataforma')->default(false)->after('email');
        });

        // Empresas (clientes da plataforma) e escritórios contábeis.
        Schema::create('organizacoes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 12); // empresa | escritorio
            $table->string('razao_social');
            $table->string('cnpj', 14)->unique(); // texto: acomoda o CNPJ alfanumérico
            $table->string('inscricao_municipal')->nullable();
            $table->string('municipio_ibge', 7)->nullable();
            $table->timestamps();
        });

        Schema::create('prestadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('nome');
            $table->string('tipo', 3); // PJ, MEI, PF
            $table->string('documento', 14);
            $table->string('email')->nullable();
            $table->string('chave_pix')->nullable();
            $table->timestamps();
            $table->unique(['organizacao_id', 'documento']);
        });

        // Vínculo usuário ↔ organização, com papel, vigência e autor.
        Schema::create('vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('papel', 20); // ver App\Enums\Papel
            $table->foreignId('prestador_id')->nullable()->constrained('prestadores')->restrictOnDelete();
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('encerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'fim']);
        });

        // A empresa autoriza um escritório a acessar seus dados.
        Schema::create('autorizacoes_escritorio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('escritorio_id')->constrained('organizacoes')->restrictOnDelete();
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->foreignId('concedida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revogada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // O escritório atribui analistas às empresas da carteira.
        Schema::create('atribuicoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escritorio_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('encerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('razao_social');
            $table->string('documento', 14); // CNPJ (14) ou CPF (11)
            $table->string('email')->nullable();
            $table->string('municipio_ibge', 7)->nullable();
            $table->timestamps();
            $table->unique(['organizacao_id', 'documento']);
        });

        // Perfil fiscal por CNPJ e vigência, confirmado pelo escritório contábil.
        Schema::create('perfis_fiscais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('cnpj', 14);
            $table->string('regime', 40);
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_em')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();
            $table->index(['organizacao_id', 'cnpj']);
        });

        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained()->restrictOnDelete();
            $table->string('numero');
            $table->string('descricao');
            $table->string('codigo_servico', 6)->nullable(); // cTribNac
            $table->unsignedBigInteger('valor_mensal_centavos');
            $table->unsignedTinyInteger('dia_vencimento')->default(10);
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->string('status', 20)->default('ativo');
            $table->timestamps();
            $table->unique(['organizacao_id', 'numero']);
        });

        Schema::create('alocacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
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
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->string('direcao', 10); // emitida | recebida
            $table->string('chave_acesso', 50);
            $table->string('numero')->nullable();
            $table->string('emitente_documento', 14);
            $table->string('emitente_nome')->nullable();
            $table->string('tomador_documento', 14)->nullable();
            $table->string('tomador_nome')->nullable();
            $table->date('competencia')->nullable();
            $table->dateTime('emitida_em')->nullable();
            $table->string('codigo_servico', 6)->nullable();
            $table->text('descricao_servico')->nullable();
            $table->unsignedBigInteger('valor_servicos_centavos')->nullable();
            $table->unsignedBigInteger('valor_liquido_centavos')->nullable();
            $table->string('ambiente', 1)->nullable();
            $table->string('origem', 10); // xml | chave
            $table->string('xml_path')->nullable();
            $table->string('xml_sha256', 64)->nullable();
            $table->foreignId('enviada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Duplicidade por empresa: não revela a outra empresa que a nota existe.
            $table->unique(['organizacao_id', 'chave_acesso']);
        });

        Schema::create('faturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('contrato_id')->constrained()->restrictOnDelete();
            $table->date('competencia');
            $table->unsignedBigInteger('valor_centavos');
            $table->date('vencimento');
            $table->string('status', 20)->default('aberta');
            $table->foreignId('nota_fiscal_id')->nullable()->unique()->constrained('notas_fiscais')->nullOnDelete();
            $table->unsignedBigInteger('valor_recebido_centavos')->nullable();
            $table->date('recebida_em')->nullable();
            $table->timestamps();
            $table->unique(['contrato_id', 'competencia']);
        });

        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('alocacao_id')->constrained('alocacoes')->restrictOnDelete();
            $table->foreignId('prestador_id')->constrained('prestadores')->restrictOnDelete();
            $table->foreignId('contrato_id')->constrained()->restrictOnDelete();
            $table->date('competencia');
            $table->unsignedBigInteger('valor_centavos');
            $table->string('status', 20)->default('aguardando_nota');
            $table->foreignId('nota_fiscal_id')->nullable()->unique()->constrained('notas_fiscais')->nullOnDelete();
            $table->json('conferencia')->nullable();
            $table->text('motivo_rejeicao')->nullable();
            $table->foreignId('aprovada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovada_em')->nullable();
            $table->unsignedBigInteger('valor_pago_centavos')->nullable();
            $table->date('paga_em')->nullable();
            $table->timestamps();
            $table->unique(['alocacao_id', 'competencia']);
        });

        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->nullable()->constrained('organizacoes')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('acao', 60);
            $table->morphs('alvo');
            $table->json('dados')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organizacao_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['eventos', 'cobrancas', 'faturas', 'notas_fiscais', 'alocacoes', 'contratos', 'perfis_fiscais',
            'clientes', 'atribuicoes', 'autorizacoes_escritorio', 'vinculos', 'prestadores', 'organizacoes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('admin_plataforma'));
    }
};
