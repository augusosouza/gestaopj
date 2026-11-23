<?php

use App\Http\Controllers\Auth\DoisFatoresController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SenhaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\CompetenciaController;
use App\Http\Controllers\ContabilController;
use App\Http\Controllers\ContextoController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\EscritorioController;
use App\Http\Controllers\FaturaController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\NotaFiscalController;
use App\Http\Controllers\OrganizacaoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PerfilFiscalController;
use App\Http\Controllers\PlataformaController;
use App\Http\Controllers\PrestadorController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/esqueci-senha', [SenhaController::class, 'pedir'])->name('password.request');
    Route::post('/esqueci-senha', [SenhaController::class, 'enviar'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/redefinir-senha/{token}', [SenhaController::class, 'formulario'])->name('password.reset');
    Route::post('/redefinir-senha', [SenhaController::class, 'redefinir'])->name('password.update');
    Route::get('/verificacao', [DoisFatoresController::class, 'desafio'])->name('2fa.desafio');
    Route::post('/verificacao', [DoisFatoresController::class, 'verificar'])->middleware('throttle:20,1')->name('2fa.verificar');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Escolha do contexto (antes de existir um ativo)
    Route::get('/contexto', [ContextoController::class, 'escolher'])->name('contexto.escolher');
    Route::post('/contexto', [ContextoController::class, 'selecionar'])->name('contexto.selecionar');

    // Segurança da conta (fora do contexto, para não bloquear quem precisa ativar o 2FA)
    Route::get('/seguranca', [DoisFatoresController::class, 'seguranca'])->name('seguranca');
    Route::post('/seguranca/2fa', [DoisFatoresController::class, 'iniciar'])->name('2fa.iniciar');
    Route::post('/seguranca/2fa/confirmar', [DoisFatoresController::class, 'confirmar'])->name('2fa.confirmar');
    Route::post('/seguranca/2fa/codigos', [DoisFatoresController::class, 'novosCodigos'])->name('2fa.codigos');
    Route::delete('/seguranca/2fa', [DoisFatoresController::class, 'desativar'])->name('2fa.desativar');

    // Administração da plataforma (global, independe de empresa)
    Route::middleware('plataforma')->group(function () {
        Route::get('/plataforma/configuracoes', [PlataformaController::class, 'configuracoes'])->name('plataforma.configuracoes');
        Route::put('/plataforma/configuracoes', [PlataformaController::class, 'salvar'])->name('plataforma.salvar');
    });

    // Contexto ativo, empresa opcional (carteira do escritório e gestão da equipe)
    Route::middleware('contexto')->group(function () {
        Route::get('/carteira', [ContextoController::class, 'carteira'])->name('carteira');
        Route::post('/carteira/sair', [ContextoController::class, 'sairDaEmpresa'])->name('carteira.sair');
        Route::post('/carteira/{empresa}', [ContextoController::class, 'entrar'])->name('carteira.entrar');

        Route::middleware('papel:admin,revisor')->group(function () {
            Route::get('/acessos', [UsuarioController::class, 'index'])->name('usuarios.index');
            Route::post('/acessos', [UsuarioController::class, 'store'])->name('usuarios.store');
            Route::patch('/acessos/{vinculo}/encerrar', [UsuarioController::class, 'encerrar'])->name('usuarios.encerrar');
        });
        Route::middleware('papel:revisor')->group(function () {
            Route::get('/escritorio/atribuicoes', [EscritorioController::class, 'atribuicoes'])->name('escritorio.atribuicoes');
            Route::post('/escritorio/atribuicoes', [EscritorioController::class, 'atribuir'])->name('escritorio.atribuir');
            Route::patch('/escritorio/atribuicoes/{atribuicao}/encerrar', [EscritorioController::class, 'encerrar'])->name('escritorio.encerrar');
        });
    });

    // Contexto com empresa: todos os dados abaixo são filtrados por ela.
    Route::middleware('contexto:empresa')->group(function () {
        Route::get('/', PainelController::class)->name('painel');

        // Todos os papéis (o controller restringe o prestador às próprias cobranças).
        Route::get('/cobrancas/{cobranca}', [CobrancaController::class, 'show'])->name('cobrancas.show');
        Route::post('/cobrancas/{cobranca}/nota', [CobrancaController::class, 'enviarNota'])->name('cobrancas.nota');
        Route::post('/cobrancas/{cobranca}/xml', [CobrancaController::class, 'anexarXml'])->middleware('papel:admin,financeiro,prestador')->name('cobrancas.xml');
        Route::get('/notas/{nota}/xml', [NotaFiscalController::class, 'xml'])->name('notas.xml');
        Route::get('/notas/{nota}/danfse', [NotaFiscalController::class, 'danfse'])->middleware('throttle:30,1')->name('notas.danfse');

        // Leitura interna: empresa e escritório
        Route::middleware('papel:admin,financeiro,analista,revisor')->group(function () {
            Route::get('/faturas', [FaturaController::class, 'index'])->name('faturas.index');
            Route::get('/faturas/{fatura}', [FaturaController::class, 'show'])->name('faturas.show');
            Route::get('/cobrancas', [CobrancaController::class, 'index'])->name('cobrancas.index');
            Route::get('/notas', [NotaFiscalController::class, 'index'])->name('notas.index');
            Route::get('/contratos', [ContratoController::class, 'index'])->name('contratos.index');
            Route::get('/contratos/{contrato}', [ContratoController::class, 'show'])->name('contratos.show')->whereNumber('contrato');
            Route::get('/perfis-fiscais', [PerfilFiscalController::class, 'index'])->name('perfis.index');
            Route::get('/fechamento/exportar', [CompetenciaController::class, 'exportar'])->name('fechamento.exportar');
        });

        // Financeiro
        Route::middleware('papel:admin,financeiro')->group(function () {
            Route::post('/competencias/gerar', [CompetenciaController::class, 'gerar'])->name('competencias.gerar');
            Route::post('/faturas/{fatura}/nota', [FaturaController::class, 'vincularNota'])->name('faturas.nota');
            Route::post('/faturas/{fatura}/recebimento', [FaturaController::class, 'receber'])->name('faturas.receber');
            Route::post('/cobrancas/{cobranca}/aprovar', [CobrancaController::class, 'aprovar'])->name('cobrancas.aprovar');
            Route::post('/cobrancas/{cobranca}/rejeitar', [CobrancaController::class, 'rejeitar'])->name('cobrancas.rejeitar');
            Route::post('/cobrancas/{cobranca}/pagamento', [CobrancaController::class, 'pagar'])->name('cobrancas.pagar');
        });

        // Perfil fiscal: empresa registra, contador confirma.
        Route::post('/perfis-fiscais', [PerfilFiscalController::class, 'store'])->middleware('papel:admin,analista,revisor')->name('perfis.store');
        Route::post('/perfis-fiscais/{perfil}/confirmar', [PerfilFiscalController::class, 'confirmar'])->middleware('papel:analista,revisor')->name('perfis.confirmar');

        // Financeiro: empresa opera; escritório consulta.
        Route::middleware('papel:admin,financeiro,analista,revisor')->group(function () {
            Route::get('/financeiro', [FinanceiroController::class, 'index'])->name('financeiro.index');
            Route::get('/financeiro/titulos/{titulo}', [FinanceiroController::class, 'show'])->name('financeiro.titulo');
            Route::get('/financeiro/contas', [FinanceiroController::class, 'contas'])->name('financeiro.contas');
            Route::get('/financeiro/contas/{conta}/conciliacao', [FinanceiroController::class, 'conciliacao'])->name('financeiro.conciliacao');
            Route::get('/financeiro/categorias', [FinanceiroController::class, 'categorias'])->name('financeiro.categorias');
        });
        Route::middleware('papel:admin,financeiro')->group(function () {
            Route::get('/financeiro/novo', [FinanceiroController::class, 'create'])->name('financeiro.novo');
            Route::post('/financeiro', [FinanceiroController::class, 'store'])->name('financeiro.store');
            Route::post('/financeiro/parcelas/{parcela}/baixa', [FinanceiroController::class, 'baixar'])->middleware('throttle:60,1')->name('financeiro.baixar');
            Route::post('/financeiro/movimentos/{movimento}/estorno', [FinanceiroController::class, 'estornar'])->name('financeiro.estornar');
            Route::post('/financeiro/titulos/{titulo}/cancelar', [FinanceiroController::class, 'cancelar'])->name('financeiro.cancelar');
            Route::post('/financeiro/contas', [FinanceiroController::class, 'salvarConta'])->name('financeiro.conta_salvar');
            Route::patch('/financeiro/contas/{conta}', [FinanceiroController::class, 'alternarConta'])->name('financeiro.conta_alternar');
            Route::post('/financeiro/contas/{conta}/extrato', [FinanceiroController::class, 'importar'])->name('financeiro.importar');
            Route::post('/financeiro/extrato/{linha}/conciliar', [FinanceiroController::class, 'conciliar'])->name('financeiro.conciliar');
            Route::post('/financeiro/extrato/{linha}/desconciliar', [FinanceiroController::class, 'desconciliar'])->name('financeiro.desconciliar');
            Route::post('/financeiro/extrato/{linha}/ignorar', [FinanceiroController::class, 'ignorar'])->name('financeiro.ignorar');
            Route::post('/financeiro/categorias', [FinanceiroController::class, 'salvarCategoria'])->name('financeiro.categoria_salvar');
            Route::patch('/financeiro/categorias/{categoria}', [FinanceiroController::class, 'alternarCategoria'])->name('financeiro.categoria_alternar');
        });

        // Área do contador: empresa envia, escritório confere e fecha.
        Route::middleware('papel:admin,financeiro,analista,revisor')->group(function () {
            Route::get('/contabil', [ContabilController::class, 'index'])->name('contabil.index');
            Route::get('/contabil/documentos/{documento}', [ContabilController::class, 'baixarDocumento'])->name('contabil.documento');
            Route::get('/contabil/versoes/{versao}', [ContabilController::class, 'baixarVersao'])->name('contabil.versao');
            Route::get('/contabil/{mes}', [ContabilController::class, 'show'])->where('mes', '\d{4}-\d{2}')->name('contabil.show');
        });
        Route::middleware('papel:admin,financeiro')->group(function () {
            Route::post('/contabil/{mes}/documentos', [ContabilController::class, 'enviarDocumento'])->where('mes', '\d{4}-\d{2}')->name('contabil.enviar_documento');
            Route::post('/contabil/{mes}/enviar', [ContabilController::class, 'enviar'])->where('mes', '\d{4}-\d{2}')->name('contabil.enviar');
            Route::post('/contabil/solicitacoes/{solicitacao}/responder', [ContabilController::class, 'responder'])->name('contabil.responder');
        });
        Route::middleware('papel:analista,revisor')->group(function () {
            Route::post('/contabil/{mes}/solicitacoes', [ContabilController::class, 'solicitar'])->where('mes', '\d{4}-\d{2}')->name('contabil.solicitar');
            Route::post('/contabil/solicitacoes/{solicitacao}/aceitar', [ContabilController::class, 'aceitar'])->name('contabil.aceitar');
            Route::post('/contabil/solicitacoes/{solicitacao}/devolver', [ContabilController::class, 'devolver'])->name('contabil.devolver');
            Route::post('/contabil/solicitacoes/{solicitacao}/cancelar', [ContabilController::class, 'cancelar'])->name('contabil.cancelar');
            Route::post('/contabil/{mes}/etapa', [ContabilController::class, 'etapa'])->where('mes', '\d{4}-\d{2}')->name('contabil.etapa');
        });
        Route::middleware('papel:revisor')->group(function () {
            Route::post('/contabil/{mes}/fechar', [ContabilController::class, 'fechar'])->where('mes', '\d{4}-\d{2}')->name('contabil.fechar');
            Route::post('/contabil/{mes}/reabrir', [ContabilController::class, 'reabrir'])->where('mes', '\d{4}-\d{2}')->name('contabil.reabrir');
        });

        // Administração da empresa
        Route::middleware('papel:admin')->group(function () {
            Route::get('/empresa', [OrganizacaoController::class, 'show'])->name('organizacao.show');
            Route::put('/empresa', [OrganizacaoController::class, 'update'])->name('organizacao.update');
            Route::post('/empresa/escritorios', [OrganizacaoController::class, 'autorizarEscritorio'])->name('organizacao.autorizar');
            Route::patch('/empresa/escritorios/{autorizacao}/revogar', [OrganizacaoController::class, 'revogarEscritorio'])->name('organizacao.revogar');
            Route::resource('clientes', ClienteController::class)->only(['index', 'store', 'edit', 'update']);
            Route::resource('prestadores', PrestadorController::class)->only(['index', 'store', 'edit', 'update'])
                ->parameters(['prestadores' => 'prestador']);
            Route::get('/contratos/novo', [ContratoController::class, 'create'])->name('contratos.create');
            Route::post('/contratos', [ContratoController::class, 'store'])->name('contratos.store');
            Route::get('/contratos/{contrato}/editar', [ContratoController::class, 'edit'])->name('contratos.edit');
            Route::put('/contratos/{contrato}', [ContratoController::class, 'update'])->name('contratos.update');
            Route::post('/contratos/{contrato}/alocacoes', [ContratoController::class, 'alocar'])->name('contratos.alocar');
            Route::patch('/alocacoes/{alocacao}/encerrar', [ContratoController::class, 'encerrarAlocacao'])->name('alocacoes.encerrar');
        });
    });
});
