<?php

$guia = static fn (string $categoria, string $titulo, string $resumo, string $quem, ?string $tela, string $termos, array $passos, ?string $destino = null): array => compact('categoria', 'titulo', 'resumo', 'quem', 'tela', 'termos', 'passos', 'destino');

$interno = 'Administrador, Financeiro e contabilidade com acesso à empresa. Alterações operacionais exigem o papel indicado em cada passo.';
$financeiro = 'Administrador ou Financeiro da empresa ativa.';
$admin = 'Administrador da empresa ativa.';
$global = 'Administrador da plataforma, em Administração Augi.';

return [
    'categorias' => [
        'inicio' => 'Primeiros passos',
        'equipe' => 'Empresa e equipe',
        'operacao' => 'Operação e fiscal',
        'financeiro' => 'Financeiro',
        'bancos' => 'Bancos e conciliação',
        'contabilidade' => 'Contabilidade',
        'ajustes' => 'Configurações e inteligência',
        'plataforma' => 'Administração da plataforma',
    ],
    'destaques' => ['cadastrar-empresa', 'criar-usuario', 'trocar-empresa', 'registrar-pagamento'],
    'relacoes' => [
        'cadastrar-empresa' => ['criar-usuario', 'trocar-empresa', 'proteger-conta', 'smtp'],
        'criar-usuario' => ['alterar-permissoes', 'trocar-empresa', 'proteger-conta', 'smtp'],
        'registrar-pagamento' => ['lancamentos', 'extratos', 'lembretes-vencimento'],
    ],
    'artigos' => [
        'ficha-cliente' => $guia('operacao', 'Como consultar a ficha e o histórico de um cliente', 'Encontre contatos, endereços, contratos, faturas e parcelas a receber do cliente.', $admin, 'clientes.index', 'histórico log auditoria endereço contato cobrança cliente', [
            'Abra Operação → Clientes, pesquise por nome ou CPF/CNPJ e selecione o cliente.',
            'Na ficha, adicione contatos e endereços com a finalidade correspondente. Use Editar para corrigir ou inativar um registro.',
            'Consulte Contratos, Faturas e Parcelas a receber. Os links levam aos registros financeiros de origem.',
            'Abra Histórico para conferir a ação, data, responsável e os campos alterados quando registrados.',
        ]),
        'instalar-aplicativo' => $guia('inicio', 'Como instalar o Augi Gestão', 'Abra o Augi pelo ícone da tela inicial ou em uma janela própria.', 'Qualquer usuário com navegador compatível e acesso por HTTPS.', null, 'pwa aplicativo atalho android iphone safari desktop', [
            'Abra o sistema e procure Instalar Augi Gestão na parte inferior da página.',
            'No iPhone ou iPad, abra pelo Safari e use Compartilhar → Adicionar à Tela de Início. Em outros navegadores, procure a opção de instalação no menu ou na barra de endereço.',
            'Use uma conexão com a internet para consultar dados e concluir operações. Se cair durante uma operação, consulte o histórico antes de repetir o envio.',
            'Quando aparecer um aviso de atualização, salve o trabalho e feche todas as abas e janelas do Augi antes de abrir novamente.',
        ]),
        'cadastrar-empresa' => $guia('inicio', 'Como adicionar uma empresa', 'Entenda o cadastro da primeira empresa e a inclusão de outras organizações na instalação.', 'Responsável pela instalação ou operador com acesso ao terminal da aplicação.', null, 'nova organização criar empresa cnpj razão social', [
            'Na primeira instalação em cPanel, abra /instalar.php no domínio HTTPS. Com o código de ativação, informe o banco vazio, o CNPJ, a razão social e os dados do primeiro administrador.',
            'Em uma instalação já em uso, solicite à equipe de implantação o cadastro da nova empresa. O formulário Administração → Empresa edita a organização ativa.',
            'No terminal da instalação, execute php artisan gestao:nova-organizacao empresa CNPJ "Razão Social" administrador@exemplo.com "Nome do administrador", substituindo os dados de exemplo. Para cadastrar um escritório contábil, use o tipo escritorio.',
            'Confirme a entrega do link de definição de senha com o responsável. Quem já tem uma conta pode entrar com a senha existente e selecionar o novo vínculo em Trocar.',
        ]),
        'trocar-empresa' => $guia('inicio', 'Como trocar de empresa ou papel', 'Selecione o vínculo correto antes de consultar ou alterar informações.', 'Qualquer usuário com mais de um vínculo. Escritórios acessam clientes pela carteira.', 'contexto.escolher', 'contexto organização mudar empresa', [
            'No menu, clique em Trocar ao lado do papel atual ou volte à seleção de contexto.',
            'Escolha a empresa ou o escritório e o papel que deseja usar.',
            'Se você usa um vínculo de escritório, selecione a empresa autorizada na Carteira. Confira o nome e o papel exibidos no menu antes de continuar.',
            'Se a empresa não aparecer, peça ao responsável para conferir a vigência do vínculo e, no escritório, a autorização e a atribuição do analista.',
        ]),
        'proteger-conta' => $guia('inicio', 'Como proteger a conta com 2FA', 'Configure um autenticador e guarde códigos de recuperação de uso único.', 'Qualquer usuário. A política pode exigir 2FA de administradores e revisores.', 'seguranca', 'senha qr code totp recuperação authenticator login', [
            'Clique no seu nome e abra Segurança da conta. Escolha Ativar verificação.',
            'Leia o QR Code com um aplicativo autenticador e confirme com o código exibido no aparelho.',
            'Guarde os códigos de recuperação em local seguro. Em um novo login, confirme o código do autenticador ou use um código de recuperação disponível.',
            'Para recuperar a senha, use Esqueci a senha na tela de login. O envio depende do e-mail configurado na plataforma.',
        ]),
        'notificacoes' => $guia('inicio', 'Como acompanhar notificações', 'Localize avisos sobre pendências e abra o registro que precisa de atenção.', 'Qualquer usuário autenticado.', 'notificacoes.index', 'sino alertas avisos lembretes', [
            'Abra Notificações no menu e confira os avisos do seu acesso.',
            'Abra o aviso para acompanhar a origem. Se precisar, confira o contexto da empresa antes de executar a ação.',
            'Use a opção de marcar como lidas quando terminar de revisar os avisos.',
        ]),
        'criar-usuario' => $guia('equipe', 'Como criar usuário e conceder acesso', 'Convide uma pessoa, escolha o papel e vincule o acesso à organização certa.', 'Administrador da empresa ou Revisor do escritório.', 'usuarios.index', 'cadastro convidar convite colaborador equipe criar usuario novo usuario', [
            'Confira a organização ativa e abra Administração → Equipe e acessos.',
            'Em Convidar pessoa, informe nome e e-mail. Selecione o papel em função das tarefas que a pessoa vai executar.',
            'Ao escolher Prestador, associe o acesso a um prestador já cadastrado na empresa. Depois, selecione Conceder acesso.',
            'Quem já tem conta usará o mesmo login. Uma conta nova precisa do link de definição de senha. Se o envio não for confirmado, verifique o SMTP e peça à pessoa para usar Esqueci a senha.',
        ]),
        'alterar-permissoes' => $guia('equipe', 'Como alterar permissões ou encerrar acessos', 'Ajuste o papel de um integrante e consulte o vínculo anterior no histórico.', 'Administrador da empresa ou Revisor do escritório, dentro da organização que administra.', null, 'trocar papel revogar desativar usuário autorização equipe', [
            'Em Equipe e acessos, encontre a pessoa pelo nome ou e-mail e filtre por Vigentes.',
            'Abra Alterar papel, escolha o novo papel e confirme a operação com sua senha. Se o papel for Prestador, selecione o cadastro correspondente.',
            'Para retirar o acesso, abra Encerrar acesso e confirme o encerramento. O vínculo anterior continua no histórico.',
            'Para verificar o resultado, filtre por Encerrados ou Vigentes. O sistema bloqueia a alteração do próprio acesso.',
        ], 'usuarios.index'),
        'simular-acesso' => $guia('equipe', 'Como simular o acesso de uma pessoa', 'Confira qual ação um vínculo pode acessar e por qual motivo.', 'Administrador da empresa ou Revisor do escritório.', null, 'permissão simulador papel autorização', [
            'Em Equipe e acessos, encontre a pessoa e clique em Simular acesso no vínculo a avaliar.',
            'Escolha um recurso e confira o resultado de cada ação. No escritório, selecione uma empresa autorizada na Carteira para avaliar operações daquela empresa.',
            'Use o motivo da decisão para identificar contexto ausente, vínculo encerrado, exigência de 2FA ou papel insuficiente.',
        ], 'usuarios.index'),
        'governanca' => $guia('equipe', 'Como consultar alterações na Governança', 'Confira mudanças de acessos, dados de pagamento e configurações.', $admin, 'governanca.index', 'auditoria histórico antifraude governança', [
            'Abra Administração → Governança e filtre pelo tipo de alteração.',
            'Consulte o responsável, a data e o resultado. Para revisar uma alteração Pix, abra o cadastro do prestador pelo histórico.',
        ]),
        'alterar-pix-prestador' => $guia('operacao', 'Como solicitar e revisar a alteração de uma chave Pix', 'Proteja alterações nos dados de pagamento de prestadores.', $admin, null, 'antifraude chave pix aprovação favorecido', [
            'Em Operação → Prestadores, edite o cadastro e abra Dados de pagamento.',
            'Informe a chave proposta, o motivo e sua senha. Outro administrador receberá uma notificação e deve conferir a mudança antes de aprovar ou rejeitar.',
            'Confira a decisão no cadastro ou na Governança. Durante a revisão, a chave atual permanece em uso. A nova chave passa a valer após a aprovação.',
        ], 'prestadores.index'),
        'editar-empresa' => $guia('equipe', 'Como editar os dados da empresa', 'Atualize os dados cadastrais da organização ativa.', $admin, 'organizacao.show', 'razão social município cnpj inscrição municipal cadastro', [
            'No menu Administração, abra Empresa.',
            'Em Dados cadastrais, revise a razão social, a inscrição municipal e o município conforme os dados da empresa.',
            'Clique em Salvar e confira a mensagem de confirmação. Para cadastrar outra organização, consulte Como adicionar uma empresa.',
        ]),
        'cadastrar-clientes' => $guia('operacao', 'Como cadastrar clientes', 'Registre os tomadores que serão usados em contratos e faturamento.', $admin, 'clientes.index', 'cliente tomador consumidor cpf cnpj', [
            'Abra Operação → Clientes. Em Novo cliente, informe o nome ou a razão social e os dados solicitados.',
            'Salve e localize o cadastro na lista antes de associá-lo a um contrato.',
            'Para corrigir um cadastro, localize o cliente e use a ação Editar.',
        ]),
        'cadastrar-prestadores' => $guia('operacao', 'Como cadastrar prestadores', 'Cadastre pessoas ou empresas prestadoras para vincular contratos e acessos.', $admin, 'prestadores.index', 'prestador fornecedor pj cpf cnpj pix', [
            'Abra Operação → Prestadores e use Novo prestador.',
            'Informe o tipo, o documento e os dados de contato e pagamento aplicáveis. Confira os dados de pagamento antes de salvar.',
            'Use o cadastro para alocar o prestador em contratos ou conceder um acesso com papel Prestador.',
        ]),
        'contratos' => $guia('operacao', 'Como criar contratos e alocar prestadores', 'Configure a receita recorrente e os custos dos prestadores por vigência.', 'Administrador para criar, editar e alocar. Demais papéis internos autorizados podem consultar.', 'contratos.index', 'contrato cliente alocação recorrência vigência mensalidade', [
            'Cadastre o cliente e os prestadores. Em Operação → Contratos, abra Novo contrato.',
            'Informe cliente, descrição, vigência e condições de faturamento. Salve e abra o contrato.',
            'Em Prestadores alocados, use Alocar prestador, defina o custo e a data de início. Use Encerrar para registrar o fim da alocação.',
            'Confira as faturas geradas no detalhe do contrato. O período de vigência determina quando a recorrência se aplica.',
        ]),
        'faturamento' => $guia('operacao', 'Como gerar faturamento e vincular NFS-e', 'Acompanhe a competência, a nota emitida e o recebimento da fatura.', $interno, 'faturas.index', 'competência faturamento fatura contrato nfs-e xml receita', [
            'Abra Operação → Faturamento e NFS-e e selecione a competência.',
            'Administrador ou Financeiro pode gerar a competência quando necessário. Revise as faturas produzidas a partir dos contratos ativos.',
            'Abra a fatura e use a opção de vincular a nota emitida, conferindo chave, XML e divergências apresentadas.',
            'Quando o valor for recebido de fato, registre o recebimento e confira o título financeiro vinculado.',
        ]),
        'prestador-nota' => $guia('operacao', 'Como enviar nota e acompanhar pagamento', 'Envie a nota da competência e consulte a situação da sua cobrança.', 'Prestador, na empresa à qual seu cadastro está vinculado.', null, 'portal prestador enviar nota chave xml recebimento pagamento', [
            'Entre com o papel Prestador e abra Minhas cobranças.',
            'Escolha a cobrança da competência. Confira o demonstrativo e use o formulário de envio da nota.',
            'Informe a chave e anexe o XML quando disponível. Se já informou a chave, use a ação de anexar XML na cobrança, quando habilitada.',
            'Acompanhe a conferência e consulte o pagamento no mesmo detalhe.',
        ]),
        'pagamentos-prestadores' => $guia('operacao', 'Como conferir e pagar cobranças de prestadores', 'Revise a nota, registre a decisão e acompanhe o pagamento.', 'Administrador ou Financeiro para aprovar, rejeitar e registrar pagamento. Contabilidade autorizada pode consultar.', 'cobrancas.index', 'prestador aprovação rejeição xml nota pagar', [
            'Abra Operação → Pagamentos a prestadores e selecione a competência e a cobrança.',
            'Confira o demonstrativo da competência, a nota e as divergências. Aprove ou rejeite conforme a conferência e informe o motivo quando solicitado.',
            'Depois do pagamento bancário, registre os dados do pagamento no Augi. Verifique a situação final e o título correspondente.',
        ]),
        'documentos-fiscais' => $guia('operacao', 'Como consultar XMLs e documentos fiscais', 'Encontre notas vinculadas e baixe os documentos disponíveis.', $interno, 'notas.index', 'notas xml nfse danfse pdf fiscal', [
            'Abra Fiscal → Notas fiscais e filtre a competência ou a direção da nota.',
            'Confira o emissor, a chave e a situação de conferência. Use a ação de download do XML quando houver arquivo.',
            'Quando o gerador estiver configurado, use PDF (DANFSe) para obter a representação da nota.',
        ]),
        'perfis-fiscais' => $guia('operacao', 'Como registrar e confirmar perfis fiscais', 'Registre o regime tributário associado ao CNPJ e ao período de vigência.', 'Administrador, Analista contábil ou Revisor para registrar. A confirmação é da contabilidade.', 'perfis.index', 'regime simples lucro presumido ibs cbs vigência', [
            'Abra Fiscal → Perfis fiscais e consulte os perfis do CNPJ.',
            'Informe o regime e a vigência no formulário. Revise a informação com o responsável contábil.',
            'O Analista ou Revisor confirma o perfil no próprio módulo, após a conferência.',
        ]),
        'painel-financeiro' => $guia('financeiro', 'Como interpretar o painel financeiro', 'Veja realizado, previsto e pendências do período com acesso às origens.', $interno, 'financeiro.painel', 'dashboard indicadores saldo receitas despesas visão geral', [
            'Abra Financeiro → Painel financeiro e selecione o período.',
            'Compare os valores previstos com os efetivamente pagos e recebidos. Confira as contas bancárias e as pendências.',
            'Abra os indicadores e ações disponíveis para consultar os títulos ou as contas que compõem cada número.',
        ]),
        'lancamentos' => $guia('financeiro', 'Como criar um lançamento financeiro', 'Registre uma obrigação a pagar ou a receber com vencimentos e classificação.', $financeiro, 'financeiro.index', 'lançar título despesa receita competência parcela rateio', [
            'Abra Financeiro → Lançamentos e selecione Novo lançamento.',
            'Escolha a pagar ou a receber. Informe descrição, valor, competência, vencimento e classificação. Configure parcelas ou rateio quando aplicável.',
            'Salve e abra o título para conferir parcelas e saldo. Use a área de anexos do lançamento para guardar comprovantes ou documentos.',
            'Use a competência para o período gerencial e o vencimento para a data em que cada parcela deve ser liquidada.',
        ]),
        'registrar-pagamento' => $guia('financeiro', 'Como registrar pagamento, baixa parcial ou estorno', 'Acompanhe a obrigação e registre a movimentação efetivamente realizada.', $financeiro, 'contas_pagar.index', 'baixar pagar pagamento estornar comprovante contas a pagar', [
            'Abra Financeiro → Contas a pagar. Em Em aberto ou Vencidas, localize a obrigação pelo fornecedor, vencimento, competência ou centro de custo. A listagem é paginada e o total cobre o filtro inteiro.',
            'Na parcela, selecione Pagar para abrir o painel de baixa rápida. Confira a obrigação e o saldo. Informe a conta, a data e o valor pago, e confirme o registro.',
            'Se o pagamento foi parcial, acompanhe o saldo que continua em aberto. Use Pagas ou o histórico do título para conferir as baixas.',
            'Se registrou um pagamento incorretamente, abra o movimento no título e use Estornar quando disponível, com a justificativa solicitada.',
        ]),
        'recebimentos' => $guia('financeiro', 'Como registrar recebimentos e acompanhar cobranças', 'Controle valores previstos, baixas, contatos, promessas e renegociações.', $financeiro, 'contas_receber.index', 'contas a receber cobrança promessa renegociação cliente atraso', [
            'Abra Financeiro → Contas a receber. Use os filtros de cliente, vencimento, competência e centro de custo para encontrar o título. A aba Recebidos mostra as baixas por data de recebimento.',
            'Depois da entrada do dinheiro, use Receber na parcela para abrir o painel de baixa rápida. Informe a conta, a data e o valor efetivamente recebido. Confira o saldo restante.',
            'Para acompanhar atrasos, consulte Inadimplência ou abra a área de cobrança do título. Registre contatos, promessas e renegociações conforme a situação.',
            'Uma promessa continua em acompanhamento até que o recebimento seja registrado.',
        ]),
        'inadimplencia' => $guia('financeiro', 'Como consultar a inadimplência', 'Identifique saldos vencidos e priorize os contatos com clientes.', $interno, 'financeiro.inadimplencia', 'cliente atrasado vencido régua cobrança', [
            'Abra Financeiro → Inadimplência e consulte os clientes e faixas de atraso.',
            'Abra o cliente ou título para conferir o saldo vencido e as parcelas de origem.',
            'Administrador ou Financeiro pode registrar a ação de cobrança no título e acompanhar os próximos passos em Contas a receber.',
        ]),
        'compras' => $guia('financeiro', 'Como cadastrar uma compra', 'Registre itens, fornecedor, forma de pagamento e compromissos gerados.', $financeiro, 'compras.index', 'comprar itens fornecedor parcelas garantia', [
            'Abra Financeiro → Compras e selecione Nova compra.',
            'Informe fornecedor, data, itens e forma de pagamento. Para compra parcelada, confira o primeiro vencimento ou o cartão escolhido.',
            'Salve e examine o detalhe da compra. Consulte as obrigações geradas em Contas a pagar ou na fatura do cartão, de acordo com a forma de pagamento.',
        ]),
        'cartoes' => $guia('financeiro', 'Como cadastrar cartão e pagar a fatura', 'Acompanhe compras, limite e vencimentos das faturas.', $financeiro, 'cartoes.index', 'cartão crédito fatura limite fechamento vencimento', [
            'Em Financeiro → Cartões, abra Cadastrar cartão. Informe identificação, limite, dia de fechamento e dia de vencimento.',
            'Ao cadastrar uma compra, escolha o cartão para vincular as parcelas às faturas correspondentes.',
            'Abra a fatura e revise as compras que compõem o saldo. Depois de pagar no banco, use Registrar pagamento da fatura e confirme a conta e a data.',
        ]),
        'caixa' => $guia('financeiro', 'Como usar Meu Caixa', 'Leia a projeção de saldo e explore entradas, saídas e cenários.', $interno, 'financeiro.caixa', 'fluxo saldo confirmado projeção simulação livre para decidir', [
            'Abra Financeiro → Meu Caixa e selecione o horizonte de projeção.',
            'Compare o saldo atual com as entradas e saídas previstas. Abra os dias críticos para entender as obrigações de origem.',
            'Use a simulação para testar uma hipótese. O efeito da hipótese aparece na projeção e não cria um lançamento.',
        ]),
        'agenda' => $guia('financeiro', 'Como usar a agenda financeira', 'Consulte vencimentos por data e abra os compromissos de origem.', $interno, 'financeiro.agenda', 'calendário agenda vencimento data lembrete', [
            'Abra Financeiro → Agenda e navegue até o mês desejado.',
            'Selecione a data e abra os compromissos para conferir parcela, vencimento e origem.',
            'Para receber avisos, consulte Configurações → Lembretes de vencimento. A sincronização externa é configurada em Google Agenda.',
        ]),
        'planejamento' => $guia('financeiro', 'Como planejar orçamento, centros de custo e rateio', 'Classifique lançamentos e compare o orçamento com o executado.', $interno, 'planejamento.index', 'orçamento centro de custo rateio previsto realizado', [
            'Abra Financeiro → Planejamento para consultar orçamentos e centros de custo.',
            'Administrador ou Financeiro pode cadastrar centros e cadastrar orçamentos para o período e a classificação desejada.',
            'Ao registrar o lançamento, informe o centro ou o rateio quando aplicável. Volte ao planejamento para revisar previsto e realizado.',
        ]),
        'categorias' => $guia('financeiro', 'Como organizar categorias de receitas e despesas', 'Use categorias para classificação gerencial e composição da DRE.', $interno, 'financeiro.categorias', 'categoria subcategoria plano de contas dre', [
            'Abra Financeiro → Categorias e consulte a estrutura atual.',
            'Administrador ou Financeiro pode criar uma categoria de receita ou despesa e indicar a categoria superior, quando aplicável.',
            'Para a DRE gerencial, confira o grupo associado à categoria. Revise o efeito da classificação nos relatórios do período.',
        ]),
        'relatorios' => $guia('financeiro', 'Como consultar e exportar relatórios', 'Filtre o período e baixe as análises gerenciais disponíveis.', $interno, 'relatorios.index', 'excel xlsx csv dre relatório exportar gerencial', [
            'Abra Financeiro → Relatórios e escolha o relatório pelo assunto.',
            'Aplique os filtros do período e confira se o relatório utiliza competência, vencimento ou liquidação.',
            'Use a opção de exportação disponível e trate o arquivo baixado como documento interno da empresa.',
        ]),
        'contas-bancarias' => $guia('bancos', 'Como adicionar uma conta bancária', 'Crie a conta operacional e informe o saldo inicial na data correta.', $financeiro, 'financeiro.contas', 'banco conta saldo inicial conta corrente caixa', [
            'Abra Financeiro → Contas e bancos e use o formulário de cadastro.',
            'Informe nome, dados solicitados e o saldo inicial na data indicada. Confira o saldo no extrato antes de salvar.',
            'Abra a conta criada para acompanhar o saldo calculado, o último extrato e a conciliação.',
        ]),
        'extratos' => $guia('bancos', 'Como importar e conciliar extratos', 'Compare os movimentos do banco com as baixas registradas no Augi.', $financeiro, null, 'ofx csv extrato importar conciliar conciliação linhas duplicadas', [
            'Abra Financeiro → Contas e bancos e selecione a conta correspondente ao extrato.',
            'Use o formulário de importação para enviar o arquivo no formato aceito pela tela. Confira o período coberto e as linhas importadas.',
            'Abra a conciliação, selecione um movimento e confira valor, data e conta antes de associá-lo à baixa ou à transferência correspondente.',
            'Se associar o movimento errado, use a opção de desconciliar. Confira as linhas pendentes após a revisão.',
        ], 'financeiro.contas'),
        'transferencias' => $guia('bancos', 'Como registrar transferência entre contas', 'Registre a saída e a entrada entre contas da mesma empresa.', $financeiro, 'transferencias.index', 'transferência pix interna bancos conta origem destino', [
            'Abra Financeiro → Transferências.',
            'Selecione a conta de origem e a conta de destino. Informe o valor e a data da operação efetiva.',
            'Confirme o registro e confira os dois lados da movimentação nas contas. Faça a conciliação de cada extrato quando os movimentos estiverem disponíveis.',
        ]),
        'lembretes-bancarios' => $guia('bancos', 'Como configurar lembretes de extrato', 'Defina frequência, antecedência e responsável por atualizar a conta.', $financeiro, 'configuracoes.lembretes', 'cron extrato vencido lembrete responsável conciliação', [
            'Abra Configurações → Lembretes bancários e selecione a conta.',
            'Escolha a frequência e informe os parâmetros de horário e responsabilidade oferecidos pela tela da conta.',
            'Salve e revise a situação. O sistema diferencia extrato desatualizado de movimentações ainda pendentes de conciliação.',
        ]),
        'autorizar-escritorio' => $guia('contabilidade', 'Como autorizar um escritório contábil', 'Vincule à empresa um escritório já cadastrado na plataforma.', $admin, null, 'contador revogar autorizar escritório contábil acesso', [
            'Abra Administração → Empresa e localize Escritório contábil.',
            'Selecione o escritório cadastrado, confira os dados apresentados e clique em Autorizar.',
            'Confira a autorização na lista. Use Revogar se o escritório deixar de prestar atendimento à empresa.',
        ], 'organizacao.show'),
        'carteira' => $guia('contabilidade', 'Como acessar a carteira contábil', 'Escolha uma empresa autorizada e confira o contexto de atendimento.', 'Analista contábil ou Revisor com acesso vigente ao escritório.', 'carteira', 'contador cliente carteira trocar empresa escritório', [
            'Escolha o vínculo do escritório e abra Carteira.',
            'Selecione uma empresa acessível. Analistas veem a carteira conforme as atribuições do escritório.',
            'Confirme o cliente no menu e abra Fechamento contábil. Volte à carteira quando precisar trocar de empresa.',
        ]),
        'atribuicoes' => $guia('contabilidade', 'Como atribuir empresas a analistas', 'Controle quem do escritório pode atender cada empresa autorizada.', 'Revisor do escritório.', 'escritorio.atribuicoes', 'analista revisor empresa clientes permissão escritório', [
            'No contexto do escritório, abra Contabilidade → Atribuições.',
            'Selecione o analista e a empresa autorizada e registre a atribuição.',
            'Revise a lista e encerre a atribuição quando o analista deixar de atender aquele cliente.',
        ]),
        'fechamento' => $guia('contabilidade', 'Como enviar documentos e fechar a competência', 'Acompanhe coleta, conferência, revisão e versões de fechamento.', 'Equipe da empresa envia e responde. A contabilidade revisa; o Revisor fecha ou reabre.', 'contabil.index', 'documentos contador fechamento competência solicitações versões pendências', [
            'Abra Contabilidade e selecione a competência. Na empresa, envie os documentos e use Concluí o envio deste mês quando terminar.',
            'A contabilidade pode solicitar informações. A empresa responde no próprio item; o contador aceita ou devolve com orientação.',
            'O Revisor pode fechar a competência na etapa de revisão, depois de resolver as pendências. Confira a versão gerada e o histórico.',
            'Para revisar um período já fechado, o Revisor deve reabrir a competência e justificar a operação.',
        ]),
        'preferencias' => $guia('ajustes', 'Como encontrar uma configuração', 'Use a busca para localizar ajustes pessoais, da empresa ou da plataforma.', $financeiro, 'configuracoes', 'ajustes buscar configuração empresa conta administração', [
            'Abra Configurações no menu.',
            'Digite um nome ou termo associado ao ajuste, como extrato, categoria, inteligência ou SMTP.',
            'Abra o resultado e confira o escopo. Ajustes de plataforma são globais e aparecem para o administrador global.',
        ]),
        'inteligencia' => $guia('ajustes', 'Como configurar assistente, sugestões e tokens', 'Controle a disponibilidade da inteligência financeira por empresa.', 'Administrador da empresa altera. Financeiro pode consultar as preferências.', 'configuracoes.inteligencia', 'ia tokens chaves inteligência modelo configuração', [
            'Abra Configurações → Assistente, sugestões e tokens.',
            'Ative ou desative Assistente financeiro e Sugestões automáticas separadamente e salve as preferências da empresa.',
            'Consulte a seção Motor e dados. O motor atual usa regras do Augi e os dados da empresa ativa, sem configuração de provedor ou tokens externos.',
        ]),
        'assistente' => $guia('ajustes', 'Como consultar o assistente financeiro', 'Faça perguntas sobre caixa, pagamentos, recebimentos e orçamento.', 'Papéis internos autorizados, com o assistente habilitado para a empresa.', 'inteligencia.assistente', 'ia pergunta caixa hoje saldo assistente', [
            'Abra Inteligência → Assistente.',
            'Selecione uma pergunta sugerida ou escreva uma pergunta sobre um assunto coberto.',
            'Confira período, valores e links de origem antes de tomar uma decisão. As operações são executadas nas telas financeiras correspondentes.',
        ]),
        'sugestoes' => $guia('ajustes', 'Como revisar sugestões automáticas', 'Investigue alertas de caixa, atraso, classificação e orçamento.', 'Papéis internos autorizados podem consultar. Administrador ou Financeiro pode adiar ou dispensar.', 'inteligencia.sugestoes', 'alerta financeiro dispensar adiar recomendação sugestões', [
            'Abra Inteligência → Sugestões e filtre por tipo quando necessário.',
            'Leia o motivo e use a ação sugerida para abrir a tela de origem.',
            'Se optar por adiar ou dispensar, use a ação correspondente. Uma mudança na situação pode gerar uma nova sugestão.',
        ]),
        'lembretes-vencimento' => $guia('ajustes', 'Como configurar lembretes de vencimento', 'Escolha os avisos de pagamentos, recebimentos e faturas.', $financeiro, 'configuracoes.vencimentos', 'lembrete notificação vencimento parcelas cartão horário', [
            'Abra Configurações → Lembretes de vencimento.',
            'Escolha o tipo de compromisso, os dias de aviso e o horário. Salve o padrão do tipo.',
            'Para ajustar uma obrigação específica, abra a área de lembretes no respectivo lançamento e confirme a vigência com o saldo em aberto.',
        ]),
        'google-agenda' => $guia('ajustes', 'Como conectar o Google Agenda', 'Sincronize vencimentos na agenda escolhida e acompanhe falhas de conexão.', $financeiro, 'integracoes.google', 'calendar oauth google integração agenda sincronizar', [
            'Abra Configurações → Google Agenda. Se a integração estiver habilitada na instalação, escolha uma agenda dedicada ou existente.',
            'Autorize a conta Google e volte ao Augi. Selecione os compromissos a sincronizar e configure títulos discretos para preservar a privacidade.',
            'Consulte o último resultado e use Sincronizar agora se necessário. Em uma troca de calendário, o destino muda após a limpeza dos eventos anteriores.',
            'Se a conexão estiver interrompida, tente autorizar a mesma conta novamente. Os lembretes internos continuam disponíveis.',
        ]),
        'identidade' => $guia('plataforma', 'Como alterar o título e a identidade do sistema', 'Atualize nome, slogan e assinatura para todas as organizações.', $global, 'plataforma.configuracoes', 'título marca cadastro nome slogan assinatura', [
            'Abra Administração Augi → Identidade.',
            'Edite o nome do sistema, o slogan ou o responsável institucional. Confira a prévia enquanto digita.',
            'Salve a identidade e consulte o histórico. O nome global é usado no acesso e no título das páginas.',
        ]),
        'smtp' => $guia('plataforma', 'Como configurar SMTP e testar e-mail', 'Configure o envio de convites e recuperação de senha da plataforma.', $global, 'plataforma.smtp', 'email e-mail smtp tokens senha aplicativo remetente 465 587', [
            'Peça à equipe de implantação para definir GESTAO_SMTP_PAINEL_HOST com o servidor autorizado e recarregar a configuração.',
            'Em Administração Augi → E-mail e SMTP, escolha a porta, preencha o remetente e informe o usuário e a senha de aplicativo quando exigidos.',
            'Ative a configuração do painel e confirme o salvamento com sua senha do Augi. A senha SMTP armazenada fica protegida e não aparece no formulário.',
            'Use Enviar e-mail de teste para enviar ao próprio e-mail. Confira o resultado no painel e a chegada à caixa de entrada.',
        ]),
        'migrar-instalacao' => $guia('plataforma', 'Como migrar a instalação do Augi', 'Transfira o banco, os documentos privados e as chaves para outra hospedagem.', 'Responsável pela infraestrutura, com acesso ao banco, aos arquivos e ao agendador.', null, 'cpanel plesk vps hospedagem domínio subdiretório portabilidade backup app key', [
            'Teste a restauração em um ambiente isolado. Guarde uma cópia verificável do banco e dos arquivos privados, junto com as chaves necessárias para decifrar os backups.',
            'Na janela de mudança, coloque a origem em manutenção, pause o cron e os processos de fila e obtenha uma cópia final.',
            'Restaure em uma base dedicada no destino. Preserve a APP_KEY, atualize a URL, o caminho da sessão e as credenciais da hospedagem. Configure o callback do Google se o endereço mudar.',
            'Valide login, anexos, agendador, e-mail e backup no destino antes de desativar a origem. Consulte a seção de portabilidade no README da versão implantada.',
        ]),
        'backups' => $guia('plataforma', 'Como configurar e acompanhar backups', 'Defina destino e horários e verifique a capacidade de recuperação.', $global, 'plataforma.backups', 'backup restaurar retenção cópia s3 senha cron', [
            'Em Administração Augi → Backups, configure o destino, os horários, a retenção e a senha de criptografia.',
            'Use o teste de conexão. Solicite um backup e confira a conclusão no histórico e o funcionamento do agendador.',
            'Guarde a folha de recuperação e a chave da instalação em local seguro. A restauração é uma operação de infraestrutura, feita pelo terminal por pessoal autorizado.',
            'Antes de restaurar a produção, valide um pacote e toda a cadeia em ambiente de recuperação.',
        ]),
        'saude' => $guia('plataforma', 'Como verificar a saúde do sistema', 'Acompanhe banco, cache, cron, armazenamento e backup.', $global, 'plataforma.saude', 'status uptime falha saúde cron rotina espaço disco', [
            'Abra Administração Augi → Saúde do sistema e confira os resultados recentes.',
            'Use Verificar agora para atualizar os indicadores disponíveis.',
            'Se uma rotina estiver atrasada ou falhar, informe a equipe de implantação com o horário e o nome da rotina. Evite compartilhar credenciais nos relatos.',
        ]),
    ],
];
