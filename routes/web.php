<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SenhaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\CompetenciaController;
use App\Http\Controllers\ContextoController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\EscritorioController;
use App\Http\Controllers\FaturaController;
use App\Http\Controllers\NotaFiscalController;
use App\Http\Controllers\OrganizacaoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PerfilFiscalController;
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
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Escolha do contexto (antes de existir um ativo)
    Route::get('/contexto', [ContextoController::class, 'escolher'])->name('contexto.escolher');
    Route::post('/contexto', [ContextoController::class, 'selecionar'])->name('contexto.selecionar');

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
        Route::get('/notas/{nota}/xml', [NotaFiscalController::class, 'xml'])->name('notas.xml');

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
