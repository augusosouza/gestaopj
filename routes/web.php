<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\CompetenciaController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\FaturaController;
use App\Http\Controllers\NotaFiscalController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PerfilFiscalController;
use App\Http\Controllers\PrestadorController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', PainelController::class)->name('painel');

    // Todos os perfis (o controller restringe o prestador às próprias cobranças).
    Route::get('/cobrancas/{cobranca}', [CobrancaController::class, 'show'])->name('cobrancas.show');
    Route::post('/cobrancas/{cobranca}/nota', [CobrancaController::class, 'enviarNota'])->name('cobrancas.nota');
    Route::get('/notas/{nota}/xml', [NotaFiscalController::class, 'xml'])->name('notas.xml');

    // Equipe interna: leitura
    Route::middleware('papel:admin,financeiro,contabilidade')->group(function () {
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

    // Contabilidade
    Route::middleware('papel:admin,contabilidade')->group(function () {
        Route::post('/perfis-fiscais', [PerfilFiscalController::class, 'store'])->name('perfis.store');
        Route::post('/perfis-fiscais/{perfil}/confirmar', [PerfilFiscalController::class, 'confirmar'])->name('perfis.confirmar');
    });

    // Empresa (administração): cadastros e contratos
    Route::middleware('papel:admin')->group(function () {
        Route::resource('empresas', EmpresaController::class)->only(['index', 'store', 'edit', 'update']);
        Route::resource('clientes', ClienteController::class)->only(['index', 'store', 'edit', 'update']);
        Route::resource('prestadores', PrestadorController::class)->only(['index', 'store', 'edit', 'update'])
            ->parameters(['prestadores' => 'prestador']);
        Route::get('/contratos/novo', [ContratoController::class, 'create'])->name('contratos.create');
        Route::post('/contratos', [ContratoController::class, 'store'])->name('contratos.store');
        Route::get('/contratos/{contrato}/editar', [ContratoController::class, 'edit'])->name('contratos.edit');
        Route::put('/contratos/{contrato}', [ContratoController::class, 'update'])->name('contratos.update');
        Route::post('/contratos/{contrato}/alocacoes', [ContratoController::class, 'alocar'])->name('contratos.alocar');
        Route::patch('/alocacoes/{alocacao}/encerrar', [ContratoController::class, 'encerrarAlocacao'])->name('alocacoes.encerrar');
        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    });
});
