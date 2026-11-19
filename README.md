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
| `admin@augi.local` / `financeiro@augi.local` | Empresa A |
| `admin.b@augi.local` | Empresa B |
| `prestador@augi.local` | Prestador nas empresas A e B (escolhe o contexto) |
| `revisor@augi.local` | Revisora do escritório (carteira: A e B) |
| `contabilidade@augi.local` | Analista do escritório (atribuído só à A) |

Testes: `php artisan test` (59 testes: mês fechado de ponta a ponta e o critério de aceite da entrega 1, com acesso cruzado entre empresas, revogações e recuperação de senha).

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

Configure o envio de e-mail (`MAIL_*` no `.env`). Convites e recuperação de senha dependem dele.

Coloque um proxy reverso com HTTPS (Caddy, Nginx ou Traefik) na frente da porta 8080. O serviço `agendador` gera a competência todo dia 1º às 06:00. Faça backup do volume `db` **e** do volume `storage` (onde ficam os XMLs).

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
| **3. Fechamento piloto** | 🟡 Exportação .zip e confirmação de perfil fiscal. ⏳ Falta: etapas (coleta, conferência, revisão, fechada), pendências com responsável e prazo, retrato versionado, reabertura justificada, carteira com prazos e indicadores. |
| 4 a 6 | ⏳ Integrações RTC/NFS-e/ADN, conciliação bancária, planejamento e revenda. |

**CNPJ alfanumérico**: aceito em todo o sistema (validação conforme IN RFB 2.229/2024, conferida com o exemplo oficial `12.ABC.345/01DE-35`). Ponto em aberto: a chave de acesso da NFS-e é numérica; como ela representa um emitente com CNPJ alfanumérico depende do leiaute oficial. A verificação "CNPJ contido na chave" é só um aviso e não bloqueia nada.

**2FA**: aplicativo autenticador (TOTP), com segredo criptografado no banco, código não reutilizável, 8 códigos de recuperação de uso único e desafio que expira em 5 minutos. Obrigatório para administradores e revisores (`GESTAO_EXIGIR_2FA`). Cada pessoa ativa o seu em *Segurança da conta*.

Desvio consciente: a especificação pede valores decimais com precisão definida. Aqui os valores em reais são **inteiros em centavos**, o que é exato para totais e conferência. Tributos com mais casas (alíquotas, fase 3) usarão colunas decimais próprias.

## Próximas fases

- **Fase 2: emissão dentro do painel** pela API da SEFIN Nacional, em produção restrita primeiro. Cobrir emissão, rejeição, recuperação do XML e **consulta após timeout antes de reenviar** (evita nota duplicada). Depois, DANFSe pelo gerador da Receita (serviço Go interno) e consulta de notas recebidas via API do ADN.
- **Fase 3: cálculo de IBS/CBS** pelos motores da Receita (serviços Java internos), escolhidos pelo perfil fiscal vigente do CNPJ. Grava a versão do código e da base usada em cada cálculo. Confirmar antes a disponibilidade da base SQLite/Flyway exigida pela calculadora do Simples.
