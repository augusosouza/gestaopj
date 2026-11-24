# Augi Gestão — portal de faturamento, prestadores e contabilidade

Painel único para a empresa de suporte e consultoria acompanhar **o que tem a receber dos clientes**, **o que tem a pagar aos contratados PJ/MEI** e **quais notas ainda precisam ser conferidas**.

Construído segundo a *Especificação funcional e técnica v1.0 (19/11/2026)*. Estado atual: **entrega 1 (fundação multiempresa) concluída** e parte das entregas 2 e 3 (veja a [aderência à especificação](#aderência-à-especificação)). A emissão continua no Emissor Nacional (portal ou API); o sistema recebe os XMLs.

## Multiempresa e isolamento

- **Organizações**: empresas e escritórios contábeis, identificados por ID interno; o CNPJ é guardado como texto (acomoda o CNPJ alfanumérico).
- **Vínculos**: pessoa ↔ organização ↔ papel, com início, fim e autor. Uma pessoa pode ter vários (ex.: o mesmo prestador atende duas empresas) e escolhe o **contexto ativo** após o login.
- **Escritório**: só acessa uma empresa enquanto houver **autorização vigente dela**. O revisor vê toda a carteira; o analista só as empresas **atribuídas** a ele.
- **Isolamento**: toda tabela operacional tem `organizacao_id`, e um escopo global filtra pela empresa do contexto. Uma requisição autenticada sem empresa escolhida não vê nenhum dado (falha fechada). O contexto é ativado **antes** do route model binding, então ID de outra empresa na URL, em download de XML ou em ação resulta em 404.
- **Revogação**: encerrar vínculo, autorização ou atribuição corta o acesso na próxima requisição (o contexto é revalidado a cada uma) e preserva a trilha.
- **Auditoria** (`eventos`): login, acesso do escritório a uma empresa, download de XML, exportação, criação/encerramento de vínculos, autorizações e atribuições, além das ações financeiras.
- **XMLs** ficam no disco privado, separados por organização (`nfse/{organizacao}/...`).

## O fechamento responde a quatro perguntas

| Pergunta | Onde vem o número |
|---|---|
| 1. Quanto faturei? | Soma das faturas da competência (exceto canceladas) |
| 2. Quanto recebi? | Soma dos recebimentos registrados nessas faturas |
| 3. Quanto devo aos prestadores? | Cobranças da competência ainda não pagas (separando o que já foi aprovado) |
| 4. Quais notas estão pendentes? | Faturas sem NFS-e + cobranças aguardando nota, em conferência ou rejeitadas |

Também mostra a margem bruta por contrato (faturado − custo dos prestadores alocados). Exemplo do seed: R$ 12.000,00 − R$ 7.500,00 = R$ 4.500,00 (37,5%).

## Fluxo mensal

```
dia 1º  gestao:gerar-competencia  →  1 fatura por contrato ativo + 1 cobrança por prestador alocado
        (idempotente; também pelo botão "Gerar competência")

A RECEBER                                  A PAGAR
Empresa emite NFS-e no Emissor Nacional    Prestador emite NFS-e (tomador = CNPJ da empresa)
→ envia o XML na fatura                    → envia XML (ou só a chave de 50 dígitos)
→ sistema confere emitente, tomador,       → conferência automática (abaixo)
  valor e competência (recusa se divergir) → financeiro aprova / rejeita com motivo
→ financeiro registra o recebimento        → financeiro registra o pagamento (sugere o líquido)

Contabilidade: confirma perfis fiscais e exporta o .zip do mês (CSVs + XMLs emitidos e recebidos)
```

### Conferência automática da nota do prestador

Cada item tem uma **natureza**, que define o que a aprovação permite:

| Natureza | Exemplos | Pode aprovar? |
|---|---|---|
| **Fiscal** (documento inválido) | Emitente não é o prestador, tomador não é a empresa, nota de teste | **Nunca**: rejeitar e pedir nova nota |
| **Comercial** (divergência) | Valor diferente (mostra a diferença), competência diferente | Só **administrador**, com justificativa |
| **Verificação** (não verificada) | Só a chave, sem XML | Com justificativa. A pendência "XML da nota pendente" continua no fechamento até o XML ser anexado, mesmo depois de paga |
| **Aviso** | CNPJ na chave, perfil fiscal não confirmado | Informativo |

A competência comparada é a **declarada na nota** (`dCompet`), não a data de emissão.

Notas com chave já registrada na empresa são recusadas no envio (duplicidade).

## Financeiro (FIN-01)

Cada data tem o seu lugar:

| Data | Onde fica |
|---|---|
| **Competência** (mês a que pertence) | Título |
| **Vencimento** (quando deve ser pago) | Parcela |
| **Liquidação** (quando foi pago, e em qual conta) | Movimento |

- **Títulos** a receber e a pagar, com categoria, contraparte e N parcelas mensais (os centavos da divisão vão para a última). Faturas e cobranças viram títulos **automaticamente e sem duplicar**.
- **Baixas** com valor, desconto, retenção e acréscimo. **Pagamento parcial** mantém o saldo em aberto. A fatura só fica "Recebida" e a cobrança "Paga" quando o saldo zera.
- **Estorno** é um movimento novo que anula a baixa. A baixa original **nunca é alterada nem apagada** (o sistema recusa). O saldo de datas passadas não muda depois de um estorno.
- **Travas:**
  - não baixa mais que o saldo;
  - não aceita data futura;
  - duplo envio do formulário não duplica;
  - não movimenta mês **fechado pelo contador**;
  - não estorna movimento conciliado (é preciso desfazer a conciliação antes);
  - não cancela título com baixas ativas.
- **Contas** com saldo inicial e saldo calculado por data. Não guarda senha nem acesso ao banco.
- **Extrato:** importação de OFX (padrão dos bancos) ou CSV (`data;descrição;valor`). Reimportar não duplica.
- **Conciliação:** sugere baixas de mesmo valor, sentido e conta, com até 5 dias de diferença. Dá para conciliar, desfazer ou marcar "sem correspondência".
- **Painel financeiro** (`/financeiro/painel`), em três abas:
  - **Resumo:** recebido × previsto e pago × previsto do mês (com barra de progresso), saldo em contas, vencidos e a lista **Ações necessárias** (extrato desatualizado, conciliação pendente, títulos vencidos), cada uma com link direto.
  - **Fluxo de caixa:** entradas, saídas e saldo semana a semana (12 semanas), em gráfico e em tabela.
  - **Despesas:** gastos do mês por categoria e maiores favorecidos.
- **Detalhe do lançamento**, em abas (Resumo, Parcelas, Pagamentos, Histórico). O resumo separa o **pago em dinheiro** de **descontos, retenções e acréscimos**; o formulário de baixa mostra o saldo da parcela após o registro.
- **Lembretes de extrato (FIN-ALERT-01)** por conta: frequência (semanal, quinzenal, mensal), dia, horário, responsável, antecedência e repetição. Enviar um arquivo **não** basta: vale o **período coberto** pelo extrato (DTSTART/DTEND do OFX, datas do CSV). Avisos separados para *extrato desatualizado* e *conciliação pendente*; dispensa com motivo e prazo (até 90 dias), registrada na auditoria. Avisos chegam em **Notificações** (`php artisan financeiro:lembretes`, agendado de hora em hora).
- **Compatibilidade:** `php artisan financeiro:sincronizar` (também executado na migração) cria títulos para faturas e cobranças antigas e converte os pagamentos já registrados em baixas. É idempotente.
- **Permissões:** a empresa opera (admin, financeiro); o escritório só consulta; o prestador não acessa.

## Financeiro 2.0 — compras, cartões, caixa e decisões

**Regra de ouro:** uma obrigação é **um** título com parcelas. Nenhum módulo cria um segundo registro para a mesma dívida.

- **Compras** (`Financeiro → Compras`): fornecedor, itens (o valor passa a ser a soma deles), desconto, juros, entrada, parcelas, nota (tipo, número, chave), garantia. A compra gera **um** título a pagar; a entrada é a parcela 1 e pode ser registrada como já paga. Cancelar só sem pagamentos (com pagamentos, estorne antes).
- **Cartões corporativos**: limite, fechamento, vencimento, conta que paga e responsável (sem número completo, CVV ou senha). Compra no cartão: cada parcela vai para a fatura certa (compra no dia do fechamento ou depois entra na seguinte) e vence com ela.
  - **Pagar a fatura** quita as parcelas das compras — a despesa **não** é lançada de novo. Parcela de cartão não pode ser paga avulsa.
  - **Encargos** (juros, IOF, multa) viram um título próprio em "Juros e encargos financeiros".
  - Limite disponível = limite − parcelas não pagas.
- **Transferências entre contas**: mexem só nos saldos (não são receita nem despesa). Tarifa vira despesa em "Tarifas bancárias". Imutáveis: estorno é uma transferência inversa. Conciliam com o extrato.
- **Meu Caixa** (`/financeiro/meu-caixa`): *o que posso fazer com o dinheiro de hoje sem comprometer os próximos vencimentos?*
  - Projeção **dia a dia** por data de vencimento (7/15/30/60/90 dias). Um recebimento depois de um pagamento não "cobre" o pagamento: o sistema aponta o dia em que falta dinheiro.
  - **Livre para decidir** = menor saldo previsto no período.
  - Pagamentos vencidos entram hoje; recebimentos vencidos ficam de fora por padrão (opção para incluir).
  - **Simulação** de gasto ou entrada, sem gravar nada.
  - **Saldo confirmado pelo banco**: o saldo do OFX (LEDGERBAL) comparado ao registrado no Augi naquela data.
- **Agenda financeira**: próximos vencimentos (com atrasados) e visão de mês; faturas de cartão aparecem uma vez.
- **Inadimplência**: vencidos por cliente e faixa (1–7, 8–15, 16–30, 31–60, +60 dias); marca quem atrasou os três últimos vencimentos.
- **Sugestões** (`Inteligência → Sugestões`), por regras: falta de caixa projetada, cliente com atraso, atraso recorrente, recebimento parcial, lançamento sem categoria, possível pagamento duplicado, fatura 30% acima da média, comprometimento com parcelamentos, extrato e conciliação. Cada uma tem motivo, valores e ação; pode ser adiada (3/7 dias) ou dispensada, com auditoria.
- **Assistente sem tokens** (`Inteligência → Assistente`): perguntas como "Quanto tenho disponível?", "O que vence amanhã?", "Quanto gastei com software este mês?", "Como estará meu caixa?". Um interpretador de regras escolhe uma consulta pronta (o texto nunca vira SQL) e responde com os **mesmos serviços das telas**, mostrando a origem do número. "Quero registrar uma compra de R$ 2.400 em seis parcelas" abre o formulário preenchido para revisão — nada é gravado sem confirmação. Funciona sem nenhuma chave de IA.
- **Permissões**: empresa (admin, financeiro) opera; escritório consulta; prestador não vê nada disso.

**Ainda não feito** (próximas etapas): orçamentos e metas, centros de custo e rateio, subcategorias e etiquetas, aprovação por alçada, relatórios/DRE gerencial e exportação, filtros salvos e personalização do painel, prévia antes de importar extrato, conciliação de uma linha com várias baixas, pagamento parcial e parcelamento de fatura de cartão, devolução parcial de compra, anexos (comprovantes), lembretes de cobrança por e-mail/WhatsApp, portal do pagador, integração bancária (boletos/Pix) e IA generativa opcional.

## Financeiro 2.1 — estabilização

- **Gráficos** em componentes reutilizáveis (`<x-grafico.linha|barras|colunas>`, SVG no servidor, sem JavaScript), com as cores aprovadas por tipo de valor — receitas (esmeralda), despesas (coral), saldos (azul), pendências (âmbar), projeções (roxo, tracejado), comparativos (ciano) — validadas para daltonismo nos modos claro e escuro. Ver `docs/graficos.md`.
- **Exportação XLSX/CSV** (Spatie Simple Excel) de lançamentos (com os filtros da tela), compras, inadimplência e Meu Caixa. CSV no padrão brasileiro. Arquivo temporário privado, apagado após o envio; exportação auditada.
- **Anexos** (comprovantes, notas, boletos) em lançamentos e compras: disco privado por empresa, tipo conferido pelo conteúdo do arquivo, sha256, auditoria; bloqueados em mês fechado pelo contador. Decisão contra a Media Library em `docs/anexos-comparativo.md`.
- **Saúde do sistema** (Administração Augi): Laravel Health (banco, cache, agendador, disco, backup recente) e Schedule Monitor (histórico de cada rotina). Falhas viram notificação do admin da plataforma. Após cada atualização: `php artisan schedule-monitor:sync`.
- **Extratos OFX**: casos de teste no formato real dos bancos e `php artisan ofx:anonimizar` para incluir arquivos reais sem dados pessoais (`tests/fixtures/ofx/LEIA-ME.md`).
- **Qualidade**: teste de mutação (Infection) no CI com controle contra medição falsa; 96% nos serviços de Compras e Meu Caixa (meta 80%). Ver `docs/qualidade.md`.
- **PHP 8.3** mantido; o workflow de testes também roda em 8.4/8.5 para antecipar a futura atualização.

## Central de vencimentos e Google Agenda (FIN-REM-01)

- **Contas a pagar** (`/financeiro/contas-a-pagar`): indicadores (em aberto, vence em 7 dias, atrasadas, pago no mês) e abas Visão geral, Em aberto, Parcelamentos, Calendário e Pagas. Filtros por período, categoria, fornecedor, conta, valor e situação; atalhos de próximos 7/15/30 dias; **pagamento rápido** (inclusive parcial) direto na lista; alerta quando os vencimentos superam o caixa previsto.
- **Lembretes de vencimento**: 15, 10, 5 e 1 dia antes e no dia, no horário escolhido (horário de Brasília). Padrão por usuário para contas a pagar, a receber e faturas de cartão; ajuste por lançamento na aba **Lembretes**. Avisos internos sem repetição; param quando a conta é quitada; o pagamento parcial mantém os avisos do saldo.
- **Alterar vencimento** (renegociação) com motivo e auditoria; lembretes e eventos são recalculados.
- **Google Agenda** opcional: OAuth por usuário e por empresa, agenda separada ou existente, um evento por parcela (ou por fatura), até 5 lembretes, atualização do mesmo evento, título discreto por padrão. Ver `docs/google-agenda.md`.

## Augi 3.0 — recebimentos, planejamento e relatórios

- **Contas a receber** (`/financeiro/recebimentos`): a mesma central do Contas a Pagar, com abas Visão geral, Em aberto, Atrasados, Recebidos, Cobranças, Renegociações e Calendário. **Histórico de cobrança** (contato, canal, mensagem, resposta), **promessas de pagamento** (cumprida/não cumprida calculada pelas baixas reais — promessa nunca é recebimento), **renegociação** de vencimentos auditada e **régua de cobrança** interna (3, 7 e 15 dias após o vencimento).
- **Planejamento** (`/financeiro/planejamento`): subcategorias (até 3 níveis), centros de custo, **rateio** com soma exata (`Dinheiro::ratear`, maior resto), **orçamentos** mensais por categoria ou centro (orçado, comprometido, realizado, disponível; alerta em 90%), comparativo mês a mês.
- **Relatórios** (`/financeiro/relatorios`): DRE gerencial (competência ou caixa; linha da DRE configurável por categoria), fluxo de caixa realizado, previsto × realizado, receitas e despesas por categoria, fornecedores, clientes, contratos (margem), centros de custo e cartões. Exportação Excel/CSV.
- Situação de cada item do plano mestre: `docs/augi-3.0-situacao.md`.

## Navegação e configurações

- **Menu por grupos** (Operação, Financeiro — com seções Visão geral, Movimentações, Recebimentos, Pagamentos, Bancos e análises —, Fiscal, Inteligência, Contabilidade, Administração, Administração Augi), montado conforme o papel em `App\Support\Menu`. O grupo da página atual abre sozinho; os demais lembram a escolha no navegador.
- **Padrão de tela:** trilha (breadcrumb) + título + ações (`<x-cabecalho>`) e abas (`<x-abas>`).
- **Central de configurações** (`/configuracoes`, admin e financeiro) com busca: Minha conta, Empresa, Financeiro, Fiscal, Equipe e acessos. A identidade da plataforma e os backups ficam à parte, em **Administração Augi**.
- **Notificações** internas com contador de não lidas; abrir uma notificação troca para a empresa certa, se você tiver acesso a ela.

## Componentes da Receita Federal

Componentes de código aberto publicados pela Receita no GitHub, que rodam **sem certificado digital** e como serviços internos (sem porta exposta, sem enviar dados para fora). Cada um é compilado do código-fonte num **commit fixo**: atualizar é uma decisão explícita.

| Componente | Situação | Uso no sistema |
|---|---|---|
| [Gerador de DANFSe](https://github.com/ReceitaFederal/calculadora-rtc-gerador-danfse) 1.0.0 (`8d79e6b`), Go, MIT | ✅ Integrado | Link **PDF (DANFSe)** em cada nota com XML. O PDF é gerado uma vez, guardado ao lado do XML e marcado com a versão do componente |

Configuração: `DANFSE_URL` (no Docker Compose já vem `http://danfse:8080`). Vazio desliga o recurso. Erros do componente aparecem como mensagem clara e não quebram a tela.

## Área do contador

Empresa e escritório trabalham na **mesma tela de competência** (`/contabil/AAAA-MM`), cada um com as próprias ações:

| Quem | Faz |
|---|---|
| **Empresa** (admin, financeiro) | Envia documentos do mês (extrato, folha, guias, comprovantes, notas de compra; PDF, XML, planilha, OFX, imagem ou ZIP, até 10 MB), responde as solicitações com texto ou anexo e avisa "concluí o envio" |
| **Analista** | Vê os dados do mês (faturado, recebido, custos, notas), baixa documentos, pede o que falta (com prazo), aceita ou devolve respostas e conduz coleta → conferência → revisão |
| **Revisor** | Tudo do analista, mais **fechar** e **reabrir** (reabrir exige justificativa) |

- **Fechar** exige a etapa "Em revisão" e nenhuma solicitação pendente. Grava uma **versão imutável**: pacote .zip com planilhas, XMLs das notas, documentos enviados, solicitações e um LEIA-ME com o SHA-256 de cada documento. O hash do pacote fica registrado.
- **Reabrir** volta para revisão e preserva a versão anterior. O fechamento seguinte gera a v2, a v3 e assim por diante.
- Competência fechada: nada é enviado nem alterado.
- **Carteira do escritório:** situação de cada empresa no mês (etapa, documentos, pedidos abertos, respostas a conferir), com filtros "aguardando a empresa", "aguardando o escritório", "prontas para revisão" e "fechadas". "Abrir" leva direto à competência.
- Pedidos abertos do contador aparecem nas pendências do painel da empresa.
- Arquivos ficam no disco privado, separados por empresa (`contabil/{empresa}/{AAAA-MM}/`). Todo envio, download, mudança de etapa, fechamento e reabertura vai para a auditoria.

## Configurações da plataforma

Em **Plataforma → Configurações** (`/plataforma/configuracoes`), o **administrador da plataforma** altera, sem reiniciar nada:

- **nome do sistema:** aparece no logotipo em texto (a primeira palavra na cor de destaque), no título das abas, nos e-mails e no aplicativo autenticador de quem ativar o 2FA depois;
- **slogan:** aparece no login;
- **desenvolvido por:** aparece no rodapé do login.

O valor salvo prevalece sobre o `APP_NAME` do `.env`. Cada alteração fica no histórico com o valor antigo e o novo. O administrador da plataforma é um perfil **global**, separado do administrador de cada empresa, e exige 2FA. Para conceder ou remover:

```bash
php artisan gestao:admin-plataforma voce@empresa.com.br
php artisan gestao:admin-plataforma voce@empresa.com.br --remover
```

## Papéis

| Papel | Onde | Pode |
|---|---|---|
| Administrador | Empresa | Cadastros, contratos, acessos, autorizar/revogar escritório, além de tudo do financeiro |
| Financeiro | Empresa | Gerar competência, vincular notas emitidas, registrar recebimentos, aprovar/rejeitar/pagar cobranças |
| Prestador | Empresa | Somente as próprias cobranças, notas e pagamentos |
| Analista contábil | Escritório | Empresas atribuídas: consultar, registrar e confirmar perfis fiscais, exportar fechamento |
| Revisor | Escritório | Toda a carteira autorizada, além de atribuir analistas e gerir a equipe do escritório |

## Rodando localmente (SQLite)

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan gestao:gerar-competencia 2026-10
php artisan serve
```

Acessos de demonstração (senha `piloto-2026`, **troque em produção**). O seed cria duas empresas (A e B) e um escritório:

| E-mail | Acesso |
|---|---|
| `admin@augi.local` | Empresa A + **administrador da plataforma** |
| `financeiro@augi.local` | Empresa A |
| `admin.b@augi.local` | Empresa B |
| `prestador@augi.local` | Prestador nas empresas A e B (escolhe o contexto) |
| `revisor@augi.local` | Revisora do escritório (carteira: A e B) |
| `contabilidade@augi.local` | Analista do escritório (atribuído só à A) |

Testes: `php artisan test` (212 testes, incluindo os critérios de aceite do Financeiro 2.0 — mês fechado de ponta a ponta e o critério de aceite da entrega 1, com acesso cruzado entre empresas, revogações e recuperação de senha; lembretes bancários; e navegação por papel, que abre todos os links do menu de Financeiro, Administrador, Contador e Revisor).

## Produção (VPS + Docker Compose + MariaDB)

```bash
cp .env.example .env
# edite: APP_ENV=production, APP_DEBUG=false, APP_URL, APP_KEY (php artisan key:generate --show),
#        DB_DATABASE=augi, DB_USERNAME=augi, DB_PASSWORD=<senha forte>
docker compose up -d --build
# primeira empresa e primeiro escritório (o admin recebe um link para definir a senha):
docker compose exec app php artisan gestao:nova-organizacao empresa 11.222.333/0001-81 "Minha Empresa Ltda" voce@empresa.com.br "Seu Nome"
docker compose exec app php artisan gestao:nova-organizacao escritorio 33.444.555/0001-81 "Escritório X" contador@escritorio.com.br "Contador"
```

Configure o envio de e-mail (`MAIL_*` no `.env`): convites, recuperação de senha e lembretes dependem dele. Google Agenda (opcional): `GOOGLE_*` no `.env`, ver `docs/google-agenda.md`. A imagem já traz as extensões necessárias; a cada subida o container aplica as migrations, sincroniza o Schedule Monitor e recria os caches.

Coloque um proxy reverso com HTTPS (Caddy, Nginx ou Traefik) na frente da porta 8080. O serviço `agendador` roda todas as rotinas (competência do dia 1º, lembretes, Google Agenda, backups, saúde). Faça backup do volume `db` **e** do volume `storage` (onde ficam os XMLs).

## Instalação em cPanel (hospedagem compartilhada)

### Instalador pelo navegador

**Pacote pronto para upload.** Em **Releases**, baixe o ZIP de produção e `ativar-instalador.html`. Extraia o ZIP, abra o arquivo HTML localmente em um navegador moderno e gere um código exclusivo. Faça upload apenas de `instalador.sha256` para `augi/storage/app/`, dentro da pasta privada. Guarde o código exibido para informar no assistente. O gerador roda localmente e o ZIP de produção é distribuído sem código de ativação. A ativação não se aplica a instalações que já possuem `.env`.

**Alternativa para gerar o próprio ZIP.**

1. Em uma máquina com PHP 8.3+, Composer, Git, tar e zip, gere o pacote a partir de um commit que contenha o instalador: `sh scripts/pacote-cpanel.sh /caminho/privado/pacotes`. Cada pacote vem com um código de ativação separado. Guarde o código em local privado e gere um pacote próprio para cada instalação.
2. No painel da hospedagem, crie um banco MySQL 8.0+ ou MariaDB 10.6+ **vazio** e conceda ao usuário da aplicação os privilégios nesse banco. O servidor que recebe o ZIP não precisa de Git nem Composer.
3. Extraia o ZIP em uma pasta privada. Para domínio dedicado, aponte a raiz pública para `augi/public`. Para uma URL como `https://seu.dominio/gestaopj`, siga a configuração de subdiretório abaixo.
4. Abra `/instalar.php` na raiz da URL pública do Augi. Confira os requisitos na tela e informe o código, o banco e as credenciais iniciais. A URL sugerida é obtida pelo endereço de acesso. Confirme o domínio e o prefixo antes de continuar.
5. Ative a tarefa de cron exibida e confira o primeiro resultado em **Administração Augi → Saúde do sistema**. Configure o SMTP no `.env` antes de usar convites ou recuperação de senha. A instalação começa com o envio de e-mails desativado (`MAIL_MAILER=array`). Após configurar SMTP, rode `php artisan optimize` com o PHP da aplicação para habilitar o cache.
6. Se uma etapa falhar, tente novamente com os mesmos dados. Se uma migração interrompida deixar tabelas parciais, use um novo banco vazio e uma nova extração do pacote em outra pasta. Preserve a pasta e o banco anteriores até conferir os dados. Para atualizar instalações existentes, siga o procedimento de atualização abaixo.

### Publicação em subdiretório sem mudar o site principal

O exemplo abaixo mantém o Augi em `https://seu.dominio/gestaopj`. Descubra a raiz do site em **Domínios** no cPanel ou em **Hosting Settings** no Plesk. Prepare a pasta privada antes de copiar os arquivos públicos.

| Local | Conteúdo |
|---|---|
| `/home/USUARIO/augi` | Pacote completo extraído, incluindo `artisan`, `storage/`, `vendor/` e `public/`. Escolha uma pasta fora da raiz pública de todos os sites. |
| `<raiz do site>/gestaopj` | Cópia **somente do conteúdo** de `/home/USUARIO/augi/public/`. Inclua os arquivos ocultos `.htaccess` e `.augi-root.example`. |

1. Na pasta pública `gestaopj`, copie `.augi-root.example` como `.augi-root.php` e configure o caminho absoluto da pasta privada. O mesmo valor pode ser fornecido ao PHP por `AUGI_ROOT` na configuração do servidor. Essa opção é da hospedagem, pois precisa funcionar antes de carregar o `.env`.
2. Se o site principal redireciona todas as URLs para seu próprio aplicativo, exclua `/gestaopj` da regra abrangente do site pai. Mantenha as regras do Augi no `.htaccess` da pasta `gestaopj`. Se a hospedagem usar Nginx sem Apache, configure o roteamento equivalente no servidor, preservando o prefixo no `SCRIPT_NAME` do PHP e bloqueando arquivos ocultos.
3. Acesse `https://seu.dominio/gestaopj/instalar.php`. Após a instalação, o endereço de login será `https://seu.dominio/gestaopj/login`. Em instalações manuais ou migradas, use `APP_URL=https://seu.dominio/gestaopj` e `SESSION_PATH=/gestaopj`.
4. Ao atualizar, sincronize também os arquivos públicos copiados. Preserve o `.augi-root.php` do destino e o `.env` da pasta privada.

No Gerenciador de Arquivos, habilite a exibição de arquivos ocultos. Se a hospedagem usa CageFS ou `open_basedir`, a pasta privada precisa estar acessível ao mesmo usuário que executa o PHP. No Plesk, a mesma estrutura funciona quando Apache processa as regras do site e o pool PHP tem acesso às duas pastas. Na VPS com Docker, a configuração padrão publica `public/` na raiz. O proxy precisa fornecer HTTPS e preservar o prefixo quando o Augi for publicado em subdiretório. Ajustes de proxy confiável pertencem à implantação.

### Pasta única com .htaccess no cPanel

Em Apache 2.4 com suporte a `.htaccess`, o pacote também pode ficar inteiro em um subdiretório do site, como `<raiz do site>/gestaopj`. Essa alternativa depende de o servidor aplicar o arquivo `.htaccess` da **raiz do pacote** a todas as requisições. Um proxy que sirva arquivos diretamente precisa respeitar a mesma separação.

1. Use o ZIP atualizado. Coloque `artisan`, `public/`, `storage/`, `vendor/` e o **.htaccess da raiz** lado a lado em `gestaopj/`. O arquivo `public/.htaccess` continua dentro de `public/`.
2. Antes de enviar a ativação ou inserir credenciais, confronte duas URLs no navegador. `/gestaopj/css/instalador.css` deve carregar o CSS. `/gestaopj/composer.json` deve devolver 403 ou 404. Se o arquivo interno abrir, retire o pacote dessa pasta pública e use a separação física descrita acima.
3. Envie `instalador.sha256` para `gestaopj/storage/app/` e abra `/gestaopj/instalar.php`, sem `/public`. Neste modo o PHP encontra a aplicação na pasta pai de `public/`, e o endereço é sugerido pelo instalador. Preserve os blocos de seleção de PHP já gerados pelo cPanel.
4. Se a raiz do site encaminhar todas as rotas a outro aplicativo, exclua `/gestaopj` dessa regra. Se Apache devolver 500 ao ler as diretivas ou se a hospedagem ignorar `.htaccess`, peça ao provedor a configuração de diretório ou use o modo com arquivos privados fora da raiz pública.

A opção com separação física continua indicada quando existem vhosts, aliases ou servidores estáticos que possam servir a mesma pasta por outro caminho, pois cada caminho público precisa aplicar a proteção.

### Migração entre hospedagens

1. Homologue a restauração em um ambiente isolado, usando uma cópia do banco, dos arquivos privados e da configuração necessária para a recuperação. Guarde a `APP_KEY`, as senhas de backup e os dados do destino remoto em local protegido.
2. Na janela de migração, ative manutenção na origem e pause cron e workers. Faça uma cópia final do banco e do armazenamento privado. Em Docker, inclua os dois volumes. Mantenha uma cópia fora do servidor de origem.
3. Instale no destino a mesma versão do código e carregue a configuração privada. Restaure banco e arquivos. Preserve a `APP_KEY` original para continuar lendo os tokens OAuth, as credenciais SMTP e outros dados criptografados. Use o procedimento de restauração, pois o instalador inicial exige um banco vazio.
4. Ajuste `APP_URL`, `SESSION_PATH`, credenciais do banco, SMTP, armazenamento e cron. Se o endereço mudar, revise o callback registrado no Google e `GOOGLE_REDIRECT_URI`, se definido. Confira também qualquer `ASSET_URL`. Limpe caches copiados, aplique migrações necessárias para a versão, sincronize o agendador e recrie os caches.
5. Confira login, download de arquivo privado, SMTP, tarefas agendadas e a capacidade de gerar e restaurar um backup. Troque o tráfego após essas verificações. Mantenha a origem em manutenção até o encerramento da migração.

### Instalação manual

Não precisa de Docker, Node nem acesso root. Roteiro completo, na ordem:

1. **Requisitos da hospedagem:**
   - PHP **8.3+** (MultiPHP Manager) com as extensões `pdo_mysql`, `mbstring`, `intl`, `openssl`, `curl`, `fileinfo` (anexos), `sodium` (backups criptografados), `zip`, `dom`, `simplexml`, `xmlreader` e `xmlwriter` (planilhas Excel/CSV e leitura de OFX/XML de NFS-e) — confira em *Select PHP Version → Extensions*;
   - MySQL 8 ou MariaDB 10.6+;
   - Cron Jobs; Terminal SSH é muito recomendado (sem ele, os comandos `php artisan` precisam virar tarefas cron de uma vez só).
2. **Banco:** em *MySQL Databases*, crie o banco, o usuário (senha forte) e dê **todos os privilégios** do usuário no banco.
3. **Código:** fora de `public_html` (ex.: `/home/USUARIO/augi`), via *Git Version Control* (clone do repositório) ou upload de um zip que já inclua `vendor/`. Com Terminal: `composer install --no-dev --optimize-autoloader`.
4. **Domínio:** em *Domains*, aponte a raiz do domínio/subdomínio para **`/home/USUARIO/augi/public`**. Ative o SSL (*SSL/TLS Status → AutoSSL*) — sem HTTPS, o login do Google Agenda não funciona.
5. **`.env`** (copie de `.env.example`):
   - `APP_NAME="Augi Gestão"`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://seu.dominio`
   - `DB_CONNECTION=mysql` (ou `mariadb`), `DB_HOST=localhost`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
   - `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` (uma conta de *Email Accounts* do próprio cPanel serve) — convites, recuperação de senha e lembretes dependem disso
   - `DANFSE_URL` vazio: o gerador da Receita é um serviço à parte e não roda em hospedagem compartilhada
   - Opcional, Google Agenda: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=https://seu.dominio/integracoes/google/retorno` (passo a passo em `docs/google-agenda.md`; o segredo fica **só** no `.env`, nunca no banco)
6. **Preparação** (Terminal, dentro da pasta do projeto):
   ```bash
   php artisan key:generate --force
   php artisan migrate --force
   php artisan schedule-monitor:sync
   php artisan optimize
   php artisan gestao:nova-organizacao empresa 11.222.333/0001-81 "Minha Empresa Ltda" voce@empresa.com.br "Seu Nome"
   ```
   O último comando cria a primeira empresa e envia ao e-mail informado o link para definir a senha. Não rode `--seed` em produção (ele cria os usuários de demonstração).
7. **Cron Jobs**, frequência "a cada minuto", **uma única linha** (use o caminho do PHP 8.3 que o cPanel mostrar, ex. `/usr/local/bin/php` ou `/opt/cpanel/ea-php83/root/usr/bin/php`):
   `* * * * * /usr/local/bin/php /home/USUARIO/augi/artisan schedule:run >> /dev/null 2>&1`
   Essa linha dispara tudo: competência do dia 1º, lembretes de vencimento, sincronização do Google Agenda, backups, verificação semanal, limpeza de exportações e os testes de saúde.
8. **Permissões:** `storage/` e `bootstrap/cache/` graváveis pelo usuário da conta (755 nas pastas, 644 nos arquivos; nunca 777).
9. **Conferência:** entre como administrador da plataforma em *Administração Augi → Saúde do sistema*: banco, cache, agendador e disco devem aparecer em verde poucos minutos depois de o cron começar a rodar. Depois, em *Plataforma → Backups*, configure o destino e a senha dos backups e gere o primeiro manualmente.

**Atualizar uma instalação existente:** baixe o código novo (Git *Update from Remote* ou upload), depois `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan schedule-monitor:sync` e `php artisan optimize`. As migrations só acrescentam; faça um backup antes.

### Configurar e-mail pelo painel

Para permitir alterações em **Administração Augi → E-mail e SMTP**, defina `GESTAO_SMTP_PAINEL_HOST` com o servidor SMTP autorizado no ambiente e recarregue o cache de configuração. O administrador global escolhe porta 587 com STARTTLS obrigatório ou 465 com TLS implícito, configura o remetente e pode enviar um teste para o próprio e-mail. A senha do SMTP é criptografada com a `APP_KEY`. Preserve essa chave na migração ou restauração do banco. Ao desativar o SMTP do painel, voltam a valer as configurações `MAIL_*` da implantação.

### Proteções HTTP em produção

Mantenha o domínio apontado para `public/`. O Laravel envia CSP em modo de observação, desabilita o cache de respostas dinâmicas e mantém CORS sem rotas autorizadas por padrão. Arquivos estáticos seguem a configuração do servidor. A CSP ainda permite a execução dos scripts inline existentes enquanto registra violações no console do navegador.

Redirecione HTTP para HTTPS no servidor ou proxy. Em produção, o cookie de sessão é Secure por padrão. Habilite `GESTAO_HSTS=true` no Laravel só depois de homologar HTTPS e o reconhecimento do protocolo pela aplicação. Se o proxy já envia HSTS, deixe essa opção desativada no Laravel.

## Backup (Plataforma → Backups)

**Completo:** banco de dados e todos os arquivos privados de todas as empresas (XMLs, PDFs, documentos, fechamentos e extratos). Feito em **PHP puro**, então funciona igual em cPanel e em container.

| Item | Como funciona |
|---|---|
| **Base** | Banco e todos os arquivos. Uma nova base a cada N dias (padrão: 7) |
| **Incremental** | Nos horários configurados (padrão: 06:00, 13:00 e 20:00). Banco completo e **só os arquivos novos ou alterados**, mais a lista dos removidos |
| **Restauração** | De **qualquer ponto**: cada pacote traz um manifesto com todos os arquivos daquele momento e em qual pacote está cada um. Usa só o que está no destino |
| **Destinos** | Amazon S3, **Google Cloud Storage** (chave HMAC) e compatíveis com S3 (Cloudflare R2, Backblaze B2, Wasabi, MinIO) |
| **Criptografia** | libsodium (Argon2id + XChaCha20-Poly1305), aplicada **antes** de enviar. Detecta senha errada e também pacote adulterado. A senha nova não invalida os backups antigos |
| **Integridade** | Confere o tamanho no destino após o envio. A verificação semanal baixa o último pacote, confere o SHA-256 e abre o manifesto |
| **Retenção** | Mantém as N bases mais recentes (padrão: 8) com seus incrementais e remove as cadeias antigas |
| **Tela** | Destino, credenciais (guardadas criptografadas e nunca exibidas), horários, retenção, botões "Testar conexão", "Executar agora" e "Executar base completa", histórico com falhas e alerta de atraso, e a **folha de recuperação** (exige a senha da conta) |

**Comandos:**

| Comando | Para quê |
|---|---|
| `php artisan backup:executar [--base]` | Executa um backup agora |
| `php artisan backup:listar` | Lista os pacotes no destino |
| `php artisan backup:verificar` | Confere o último pacote |
| `php artisan backup:restaurar [pacote] --confirmar` | Substitui o banco e os arquivos; põe o sistema em manutenção durante o processo |
| `php artisan backup:restaurar --extrair=/pasta` | Só extrai para conferência, sem alterar o sistema |

**Perda total do servidor:**
1. Instale o sistema novamente.
2. Coloque as linhas `BACKUP_*` da folha de recuperação no `.env`.
3. Rode `migrate`, `backup:listar` e `backup:restaurar --confirmar`.

Antes de restaurar, preserve uma cópia independente do banco e dos arquivos atuais e confira o pacote com `backup:restaurar --extrair=/pasta-privada`. A restauração prepara o banco e verifica toda a cadeia de arquivos antes de começar a substituir dados. Se a restauração falhar depois de ativar a manutenção, ela permanece ativa. Confirme a integridade do banco e dos arquivos antes de executar `php artisan up`.

**Validado de ponta a ponta** em MariaDB 10.11 com destino S3: base, incremental, exclusão de uma tabela inteira, restauração e conferência das 67 relações entre tabelas.

## Decisões de projeto

- **Valores em centavos (inteiros)**: nenhuma conta passa por `float`, então "R$ 7.500,00 = R$ 7.500,00" na conferência é exato.
- **Competência = 1º dia do mês**; vencimento da fatura cai no mês seguinte, no dia definido no contrato.
- **XML é a fonte de verdade**. A chave sozinha é aceita para não travar o prestador, mas a conferência fica pendente.
- **Segurança do XML**: DOCTYPE/entidades são recusados (XXE), sem acesso à rede no parser, limite de tamanho configurável (`GESTAO_XML_MAX_KB`).
- **Trilha de auditoria** (`eventos`): envio de nota, aprovação (com justificativa), rejeição, pagamento, recebimento e perfis fiscais.
- **Prestador PF** está cadastrável, mas fora do fluxo de NFS-e: precisa de etapa própria definida com a contabilidade (RPA, INSS/IRRF).

## Pontos a validar antes de usar em produção

1. **Leiaute do XML**: o parser segue a estrutura da NFS-e nacional (`NFSe/infNFSe` + `DPS/infDPS`). Teste com **XMLs reais** da sua empresa e do prestador antes do primeiro fechamento, pois um campo em caminho diferente vira divergência na conferência.
2. **Composição da chave de acesso**: a decomposição (município, CNPJ, número) é usada só como aviso. O dígito verificador não é validado.
3. **Assinatura digital** do XML não é verificada nesta fase.
4. **Código de serviço (cTribNac)** de suporte/consultoria: confirmar com a contabilidade.

## Aderência à especificação

| Entrega | Situação |
|---|---|
| **1. Fundação** | ✅ Organizações, vínculos datados, contexto ativo, escritório com autorização e atribuição, isolamento por empresa (inclusive URL, arquivo e exportação), login, recuperação de acesso, convites, auditoria, armazenamento privado, **2FA (TOTP) obrigatório para administradores e revisores**, **CNPJ alfanumérico**. ⏳ Falta: painel do administrador da plataforma. |
| **2. Operação mensal** | 🟡 Contratos, recorrência idempotente, demonstrativo do prestador, importação de XML com duplicidade por chave e hash, divergências, recebimento e pagamento. ⏳ Falta: medição (horas, plantões, adicionais aprovados), revisões contratuais com vigência, parcelas, baixa parcial e estorno, contestação pelo prestador, nota dividida entre contratos. |
| **3. Fechamento piloto** | ✅ Etapas (coleta, conferência, revisão, fechada), solicitações com prazo e resposta item a item, documentos da empresa, fechamento com versão imutável e hashes, reabertura justificada, carteira com situação por empresa. ⏳ Falta: checklist configurável por escritório, comparação entre versões, contas contábeis e layout de exportação por software contábil, indicadores de tempo de fechamento. |
| 4 a 6 | ⏳ Componentes da Receita (calculadoras RTC e gerador de DANFSe), emissão pela API da NFS-e, ADN, conciliação bancária, planejamento e revenda. |

**CNPJ alfanumérico**: aceito em todo o sistema (validação conforme IN RFB 2.229/2024, conferida com o exemplo oficial `12.ABC.345/01DE-35`). Ponto em aberto: a chave de acesso da NFS-e é numérica; como ela representa um emitente com CNPJ alfanumérico depende do leiaute oficial. A verificação "CNPJ contido na chave" é só um aviso e não bloqueia nada.

**2FA**: aplicativo autenticador (TOTP), com segredo criptografado no banco, código não reutilizável, 8 códigos de recuperação de uso único e desafio que expira em 5 minutos. Obrigatório para administradores e revisores (`GESTAO_EXIGIR_2FA`). Cada pessoa ativa o seu em *Segurança da conta*.

Desvio consciente: a especificação pede valores decimais com precisão definida. Aqui os valores em reais são **inteiros em centavos**, o que é exato para totais e conferência. Tributos com mais casas (alíquotas, fase 3) usarão colunas decimais próprias.

## Próximas fases

- **Fase 2: emissão dentro do painel** pela API da SEFIN Nacional, em produção restrita primeiro. Cobrir emissão, rejeição, recuperação do XML e **consulta após timeout antes de reenviar** (evita nota duplicada). Depois, DANFSe pelo gerador da Receita (serviço Go interno) e consulta de notas recebidas via API do ADN.
- **Fase 3: cálculo de IBS/CBS** pelos motores da Receita (serviços Java internos), escolhidos pelo perfil fiscal vigente do CNPJ. Grava a versão do código e da base usada em cada cálculo. Confirmar antes a disponibilidade da base SQLite/Flyway exigida pela calculadora do Simples.

## Instalação como aplicativo (PWA)

O navegador precisa acessar a instalação por HTTPS. Na parte inferior do sistema, use **Instalar Augi Gestão** quando o navegador oferecer essa opção. No Safari do iPhone ou iPad, use **Compartilhar → Adicionar à Tela de Início**.

Mantenha `manifest.webmanifest`, `service-worker.js` e a pasta `pwa/` junto ao `index.php` público. O manifesto e o service worker resolvem o escopo a partir da própria URL e funcionam na raiz do domínio ou em um subdiretório, inclusive no pacote cPanel em pasta única.

O cache do service worker guarda apenas a página genérica de falta de conexão. Consultas e operações dependem do servidor. Ao alterar essa página, atualize a versão do cache em `service-worker.js`. Uma versão nova aguarda o fechamento das janelas que ainda usam a anterior.

### Diagnóstico do instalador

Na pasta do Augi, execute `php diagnostico.php` com o PHP CLI escolhido para o site. O diagnóstico roda antes do primeiro acesso e verifica versão, extensões, dependências, diretórios e arquivos públicos. Após a instalação, também verifica conexão com o banco, chave de criptografia, configuração HTTPS e cookies, tabelas da versão, bloqueio da ativação inicial e presença da configuração SMTP. Ele exibe o estado das verificações sem imprimir credenciais.

Faça o teste real de entrega de e-mail na Administração Augi. Consulte a Saúde do sistema para validar cron, fila e backup. A criação da primeira empresa e do administrador continua disponível no instalador Web protegido pelo código de ativação.

### Busca global e visões salvas

Acesse **Buscar** no cabeçalho ou use **Ctrl+K** (Windows/Linux) ou **⌘K** (macOS). A pesquisa consulta diretamente os registros da empresa ativa e abre os destinos existentes. Os resultados são limitados a cinco por grupo e os termos da busca global são enviados pelo formulário, fora da URL. A busca usa as permissões do vínculo ativo.

Em **Clientes** e **Faturas**, aplique os filtros e use **Minhas visões** para salvar uma combinação com nome, reabri-la ou removê-la. As visões são pessoais por empresa. Em Faturas, a competência faz parte da visão. Atualize o banco com `php artisan migrate --force` antes de usar essas telas em uma instalação existente.


A busca global também encontra notas pelo número, chave ou emitente/tomador, e movimentações pelo identificador do extrato ou descrição. Os resultados levam à nota filtrada ou à conciliação da conta. Abra **Ferramentas** nos atalhos da busca para validar CPF, CNPJ, CEP e e-mail ou simular parcelas mensais.

Administradores e usuários financeiros podem compartilhar visões com a equipe autorizada da empresa. As visões compartilhadas aceitam filtros estruturados e são revogáveis pelo responsável. O compartilhamento não concede acesso a registros.

Em **Faturas**, administradores e usuários financeiros podem selecionar registros, conferir a prévia e confirmar a exportação XLSX. O limite é de 100 faturas por operação, e a confirmação expira após dez minutos. A exportação é registrada na auditoria.

### Conferências operacionais

No Fechamento do mês, use **Conferir faturamento** para revisar contratos, pendências e valores antes de gerar a competência. A prévia é somente de leitura. Se os registros que determinam a geração mudarem, abra a prévia novamente.

Em Contas a Pagar e Contas a Receber, o fluxo de baixa rápida mostra a conta, data, valor e saldo restante antes da confirmação. No Meu Caixa, as ações **Entender entradas** e **Entender saídas** abrem a composição do indicador no mesmo período. Simulações aparecem na projeção e ficam fora desses totais.

A triagem de Notificações permite filtrar, agrupar e adiar lembretes financeiros elegíveis por 24 horas. Para atualizar a instalação, faça backup e execute `php artisan migrate --force` após atualizar os arquivos.
