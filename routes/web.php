<?php

use App\Http\Controllers\AlteracaoPixController;
use App\Http\Controllers\AnexoController;
use App\Http\Controllers\Auth\DoisFatoresController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SenhaController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CaixaController;
use App\Http\Controllers\CartaoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClienteRelacionamentoController;
use App\Http\Controllers\CobrancaClienteController;
use App\Http\Controllers\CobrancaController;
use App\Http\Controllers\CompetenciaController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ConfiguracoesController;
use App\Http\Controllers\ConhecimentoController;
use App\Http\Controllers\ContabilController;
use App\Http\Controllers\ContasPagarController;
use App\Http\Controllers\ContextoController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\CorreioPlataformaController;
use App\Http\Controllers\EscritorioController;
use App\Http\Controllers\ExportacaoController;
use App\Http\Controllers\FaturaController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\GoogleAgendaController;
use App\Http\Controllers\GovernancaController;
use App\Http\Controllers\InteligenciaController;
use App\Http\Controllers\LembreteController;
use App\Http\Controllers\NotaFiscalController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\OrganizacaoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PerfilFiscalController;
use App\Http\Controllers\PlanejamentoController;
use App\Http\Controllers\PlataformaController;
use App\Http\Controllers\PrestadorController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\SaudeController;
use App\Http\Controllers\TransferenciaController;
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

    // Notificações do usuário (todas as empresas em que atua)
    Route::middleware('contexto:opcional')->group(function () {
        Route::get('/ajuda', [ConhecimentoController::class, 'index'])->name('conhecimento.index');
        Route::get('/ajuda/{slug}', [ConhecimentoController::class, 'artigo'])
            ->where('slug', '[a-z0-9-]+')->name('conhecimento.artigo');

        Route::get('/notificacoes', [NotificacaoController::class, 'index'])->name('notificacoes.index');
        Route::get('/notificacoes/{notificacao}', [NotificacaoController::class, 'abrir'])->whereNumber('notificacao')->name('notificacoes.abrir');
        Route::post('/notificacoes/lidas', [NotificacaoController::class, 'marcarLidas'])->name('notificacoes.lidas');
    });

    // Segurança da conta: contexto opcional (mostra o menu, mas abre mesmo para quem precisa ativar o 2FA)
    Route::middleware('contexto:opcional')->group(function () {
        Route::get('/seguranca', [DoisFatoresController::class, 'seguranca'])->name('seguranca');
        Route::post('/seguranca/2fa', [DoisFatoresController::class, 'iniciar'])->name('2fa.iniciar');
        Route::post('/seguranca/2fa/confirmar', [DoisFatoresController::class, 'confirmar'])->name('2fa.confirmar');
        Route::post('/seguranca/2fa/codigos', [DoisFatoresController::class, 'novosCodigos'])->name('2fa.codigos');
        Route::delete('/seguranca/2fa', [DoisFatoresController::class, 'desativar'])->name('2fa.desativar');
    });

    // Administração da plataforma (global, independe de empresa)
    Route::middleware(['contexto:opcional', 'plataforma'])->group(function () {
        Route::get('/plataforma/configuracoes', [PlataformaController::class, 'configuracoes'])->name('plataforma.configuracoes');
        Route::put('/plataforma/configuracoes', [PlataformaController::class, 'salvar'])->name('plataforma.salvar');
        Route::get('/plataforma/email', [CorreioPlataformaController::class, 'index'])->name('plataforma.smtp');
        Route::put('/plataforma/email', [CorreioPlataformaController::class, 'salvar'])->middleware('throttle:8,1')->name('plataforma.smtp.salvar');
        Route::post('/plataforma/email/teste', [CorreioPlataformaController::class, 'testar'])->middleware('throttle:3,1')->name('plataforma.smtp.testar');
        Route::get('/plataforma/backups', [BackupController::class, 'index'])->name('plataforma.backups');
        Route::get('/plataforma/saude', [SaudeController::class, 'index'])->name('plataforma.saude');
        Route::post('/plataforma/saude/verificar', [SaudeController::class, 'verificar'])->middleware('throttle:6,1')->name('plataforma.saude.verificar');
        Route::put('/plataforma/backups', [BackupController::class, 'salvar'])->name('plataforma.backups.salvar');
        Route::post('/plataforma/backups/testar', [BackupController::class, 'testar'])->middleware('throttle:10,1')->name('plataforma.backups.testar');
        Route::post('/plataforma/backups/executar', [BackupController::class, 'executarAgora'])->name('plataforma.backups.executar');
        Route::post('/plataforma/backups/folha', [BackupController::class, 'folha'])->middleware('throttle:5,1')->name('plataforma.backups.folha');
    });

    // Contexto ativo, empresa opcional (carteira do escritório e gestão da equipe)
    Route::middleware('contexto')->group(function () {
        Route::get('/carteira', [ContextoController::class, 'carteira'])->name('carteira');
        Route::post('/carteira/sair', [ContextoController::class, 'sairDaEmpresa'])->name('carteira.sair');
        Route::post('/carteira/{empresa}', [ContextoController::class, 'entrar'])->name('carteira.entrar');

        Route::middleware('papel:admin,revisor')->group(function () {
            Route::get('/acessos', [UsuarioController::class, 'index'])->name('usuarios.index');
            Route::get('/acessos/{vinculo}/simular', [UsuarioController::class, 'simular'])->name('usuarios.simular');
            Route::post('/acessos', [UsuarioController::class, 'store'])->name('usuarios.store');
            Route::patch('/acessos/{vinculo}/papel', [UsuarioController::class, 'atualizarPapel'])->middleware('throttle:12,1')->name('usuarios.papel');
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
            Route::get('/financeiro/painel', [FinanceiroController::class, 'painel'])->name('financeiro.painel');
            Route::get('/financeiro/titulos/{titulo}', [FinanceiroController::class, 'show'])->name('financeiro.titulo');
            Route::get('/financeiro/contas', [FinanceiroController::class, 'contas'])->name('financeiro.contas');
            Route::get('/financeiro/contas/{conta}/conciliacao', [FinanceiroController::class, 'conciliacao'])->name('financeiro.conciliacao');
            Route::get('/financeiro/contas/{conta}', [FinanceiroController::class, 'conta'])->name('financeiro.conta');
            Route::get('/financeiro/categorias', [FinanceiroController::class, 'categorias'])->name('financeiro.categorias');
            // Financeiro 2.0
            Route::get('/financeiro/planejamento', [PlanejamentoController::class, 'index'])->name('planejamento.index');
            Route::get('/financeiro/relatorios', [RelatorioController::class, 'index'])->name('relatorios.index');
            Route::get('/financeiro/relatorios/{slug}', [RelatorioController::class, 'show'])->name('relatorios.show');
            Route::get('/financeiro/relatorios/{slug}/exportar', [RelatorioController::class, 'exportar'])->middleware('throttle:20,1')->name('relatorios.exportar');
            Route::get('/financeiro/contas-a-pagar', [ContasPagarController::class, 'index'])->name('contas_pagar.index');
            Route::get('/financeiro/recebimentos', [ContasPagarController::class, 'index'])->defaults('tipo', 'receber')->name('contas_receber.index');
            Route::get('/financeiro/meu-caixa', [CaixaController::class, 'caixa'])->name('financeiro.caixa');
            Route::get('/financeiro/agenda', [CaixaController::class, 'agenda'])->name('financeiro.agenda');
            Route::get('/financeiro/recebimentos/inadimplencia', [CaixaController::class, 'inadimplencia'])->name('financeiro.inadimplencia');
            Route::get('/financeiro/compras', [CompraController::class, 'index'])->name('compras.index');
            Route::get('/financeiro/compras/{compra}', [CompraController::class, 'show'])->whereNumber('compra')->name('compras.show');
            Route::get('/financeiro/cartoes', [CartaoController::class, 'index'])->name('cartoes.index');
            Route::get('/financeiro/cartoes/{cartao}', [CartaoController::class, 'show'])->whereNumber('cartao')->name('cartoes.show');
            Route::get('/financeiro/cartoes/faturas/{faturaCartao}', [CartaoController::class, 'fatura'])->name('cartoes.fatura');
            Route::get('/financeiro/transferencias', [TransferenciaController::class, 'index'])->name('transferencias.index');
            Route::get('/inteligencia/assistente', [InteligenciaController::class, 'assistente'])->name('inteligencia.assistente');
            Route::get('/inteligencia/sugestoes', [InteligenciaController::class, 'sugestoes'])->name('inteligencia.sugestoes');
            Route::get('/financeiro/anexos/{anexo}', [AnexoController::class, 'baixar'])->name('anexos.baixar');
            Route::get('/financeiro/exportar/{relatorio}', ExportacaoController::class)->middleware('throttle:20,1')->name('financeiro.exportar');
        });
        Route::middleware('papel:admin,financeiro')->group(function () {
            Route::get('/configuracoes', [ConfiguracoesController::class, 'index'])->name('configuracoes');
            Route::get('/configuracoes/inteligencia', [ConfiguracoesController::class, 'inteligencia'])->name('configuracoes.inteligencia');
            Route::put('/configuracoes/inteligencia', [ConfiguracoesController::class, 'salvarInteligencia'])->middleware('papel:admin')->name('configuracoes.inteligencia.salvar');
            Route::get('/configuracoes/financeiro/lembretes', [ConfiguracoesController::class, 'lembretes'])->name('configuracoes.lembretes');
            Route::get('/financeiro/novo', [FinanceiroController::class, 'create'])->name('financeiro.novo');
            Route::post('/financeiro', [FinanceiroController::class, 'store'])->name('financeiro.store');
            Route::post('/financeiro/parcelas/{parcela}/baixa', [FinanceiroController::class, 'baixar'])->middleware('throttle:60,1')->name('financeiro.baixar');
            Route::post('/financeiro/movimentos/{movimento}/estorno', [FinanceiroController::class, 'estornar'])->name('financeiro.estornar');
            Route::post('/financeiro/titulos/{titulo}/cancelar', [FinanceiroController::class, 'cancelar'])->name('financeiro.cancelar');
            Route::post('/financeiro/contas', [FinanceiroController::class, 'salvarConta'])->name('financeiro.conta_salvar');
            Route::patch('/financeiro/contas/{conta}', [FinanceiroController::class, 'alternarConta'])->name('financeiro.conta_alternar');
            Route::post('/financeiro/contas/{conta}/extrato', [FinanceiroController::class, 'importar'])->name('financeiro.importar');
            Route::put('/financeiro/contas/{conta}/lembrete', [FinanceiroController::class, 'salvarLembrete'])->name('financeiro.conta_lembrete');
            Route::post('/financeiro/contas/{conta}/lembrete/dispensa', [FinanceiroController::class, 'dispensarLembrete'])->name('financeiro.conta_dispensar');
            Route::post('/financeiro/extrato/{linha}/conciliar', [FinanceiroController::class, 'conciliar'])->name('financeiro.conciliar');
            Route::post('/financeiro/extrato/{linha}/desconciliar', [FinanceiroController::class, 'desconciliar'])->name('financeiro.desconciliar');
            Route::post('/financeiro/extrato/{linha}/ignorar', [FinanceiroController::class, 'ignorar'])->name('financeiro.ignorar');
            Route::post('/financeiro/categorias', [FinanceiroController::class, 'salvarCategoria'])->name('financeiro.categoria_salvar');
            Route::patch('/financeiro/categorias/{categoria}', [FinanceiroController::class, 'alternarCategoria'])->name('financeiro.categoria_alternar');
            Route::put('/financeiro/categorias/{categoria}/dre', [FinanceiroController::class, 'grupoDreCategoria'])->name('financeiro.categoria_dre');
            Route::post('/financeiro/extrato/{linha}/conciliar-transferencia', [FinanceiroController::class, 'conciliarTransferencia'])->name('financeiro.conciliar_transferencia');
            // Financeiro 2.0
            Route::get('/financeiro/compras/nova', [CompraController::class, 'create'])->name('compras.create');
            Route::post('/financeiro/compras', [CompraController::class, 'store'])->name('compras.store');
            Route::post('/financeiro/compras/{compra}/cancelar', [CompraController::class, 'cancelar'])->name('compras.cancelar');
            Route::post('/financeiro/cartoes', [CartaoController::class, 'store'])->name('cartoes.store');
            Route::patch('/financeiro/cartoes/{cartao}', [CartaoController::class, 'atualizar'])->name('cartoes.atualizar');
            Route::post('/financeiro/cartoes/faturas/{faturaCartao}/pagar', [CartaoController::class, 'pagar'])->middleware('throttle:30,1')->name('cartoes.pagar');
            Route::post('/financeiro/transferencias', [TransferenciaController::class, 'store'])->name('transferencias.store');
            Route::post('/financeiro/transferencias/{transferencia}/estorno', [TransferenciaController::class, 'estornar'])->name('transferencias.estornar');
            Route::post('/financeiro/titulos/{titulo}/cobranca', [CobrancaClienteController::class, 'registrar'])->name('cobranca.registrar');
            Route::post('/financeiro/titulos/{titulo}/renegociar', [CobrancaClienteController::class, 'renegociar'])->name('cobranca.renegociar');
            Route::post('/financeiro/planejamento/orcamentos', [PlanejamentoController::class, 'salvarOrcamento'])->name('planejamento.orcamento');
            Route::post('/financeiro/planejamento/orcamentos/copiar', [PlanejamentoController::class, 'copiar'])->name('planejamento.copiar');
            Route::post('/financeiro/planejamento/centros', [PlanejamentoController::class, 'salvarCentro'])->name('planejamento.centro');
            Route::patch('/financeiro/planejamento/centros/{centro}', [PlanejamentoController::class, 'alternarCentro'])->name('planejamento.centro_alternar');
            Route::put('/financeiro/titulos/{titulo}/rateio', [PlanejamentoController::class, 'ratear'])->name('planejamento.ratear');
            Route::put('/financeiro/parcelas/{parcela}/vencimento', [LembreteController::class, 'alterarVencimento'])->name('financeiro.vencimento');
            Route::put('/financeiro/titulos/{titulo}/lembretes', [LembreteController::class, 'salvarTitulo'])->name('lembretes.titulo');
            Route::post('/financeiro/titulos/{titulo}/google', [LembreteController::class, 'adicionarGoogle'])->middleware('throttle:20,1')->name('lembretes.google');
            Route::get('/configuracoes/lembretes-de-vencimento', [LembreteController::class, 'padroes'])->name('configuracoes.vencimentos');
            Route::put('/configuracoes/lembretes-de-vencimento/{tipo}', [LembreteController::class, 'salvarPadrao'])->name('configuracoes.vencimentos.salvar');
            Route::get('/integracoes/google', [GoogleAgendaController::class, 'index'])->name('integracoes.google');
            Route::post('/integracoes/google/conectar', [GoogleAgendaController::class, 'conectar'])->name('integracoes.google.conectar');
            Route::get('/integracoes/google/retorno', [GoogleAgendaController::class, 'retorno'])->name('integracoes.google.retorno');
            Route::put('/integracoes/google', [GoogleAgendaController::class, 'salvar'])->name('integracoes.google.salvar');
            Route::post('/integracoes/google/sincronizar', [GoogleAgendaController::class, 'sincronizar'])->middleware('throttle:6,1')->name('integracoes.google.sincronizar');
            Route::delete('/integracoes/google', [GoogleAgendaController::class, 'desconectar'])->name('integracoes.google.desconectar');
            Route::post('/financeiro/anexos/{tipo}/{id}', [AnexoController::class, 'store'])->whereIn('tipo', ['titulo', 'compra'])->whereNumber('id')->middleware('throttle:30,1')->name('anexos.store');
            Route::delete('/financeiro/anexos/{anexo}', [AnexoController::class, 'remover'])->name('anexos.remover');
            Route::post('/inteligencia/sugestoes/dispensar', [InteligenciaController::class, 'dispensar'])->name('inteligencia.dispensar');
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
            Route::get('/governanca', [GovernancaController::class, 'index'])->name('governanca.index');
            Route::get('/empresa', [OrganizacaoController::class, 'show'])->name('organizacao.show');
            Route::put('/empresa', [OrganizacaoController::class, 'update'])->name('organizacao.update');
            Route::post('/empresa/escritorios', [OrganizacaoController::class, 'autorizarEscritorio'])->name('organizacao.autorizar');
            Route::patch('/empresa/escritorios/{autorizacao}/revogar', [OrganizacaoController::class, 'revogarEscritorio'])->name('organizacao.revogar');
            Route::resource('clientes', ClienteController::class)->except('destroy');
            Route::get('clientes/{cliente}/historico', [ClienteController::class, 'historico'])->name('clientes.historico');
            Route::get('clientes/{cliente}/cadastro/{tipo}/{item?}', [ClienteRelacionamentoController::class, 'form'])
                ->whereIn('tipo', ['contato', 'endereco'])->whereNumber('item')->name('clientes.relacionamentos.form');
            Route::post('clientes/{cliente}/cadastro/{tipo}/{item?}', [ClienteRelacionamentoController::class, 'salvar'])
                ->whereIn('tipo', ['contato', 'endereco'])->whereNumber('item')->name('clientes.relacionamentos.salvar');
            Route::post('/prestadores/{prestador}/pix', [AlteracaoPixController::class, 'solicitar'])
                ->middleware('throttle:8,1')->name('prestadores.pix.solicitar');
            Route::patch('/prestadores/{prestador}/pix/{solicitacao}', [AlteracaoPixController::class, 'decidir'])
                ->whereNumber('solicitacao')->middleware('throttle:8,1')->name('prestadores.pix.decidir');
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
