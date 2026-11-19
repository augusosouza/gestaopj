# GestãoPJ — portal de faturamento e prestadores

Painel único para a empresa de suporte e consultoria acompanhar **o que tem a receber dos clientes**, **o que tem a pagar aos contratados PJ/MEI** e **quais notas ainda precisam ser conferidas**.

Esta é a **fase 1 (piloto)**: contratos, NFS-e por importação de XML (ou chave de acesso), pagamentos e o fechamento mensal. A emissão continua no Emissor Nacional (portal ou API); o sistema recebe os XMLs.

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

| Item | Bloqueia aprovação? |
|---|---|
| Emitente da nota = CNPJ do prestador | Sim |
| XML anexado (só chave não permite conferir valores) | Sim |
| Tomador = CNPJ da empresa do contrato | Sim |
| Valor do serviço = valor da cobrança | Sim |
| Competência da nota = competência da cobrança | Sim |
| Ambiente de produção (tpAmb = 1) | Sim |
| CNPJ embutido na chave de acesso | Aviso |
| Perfil fiscal do prestador confirmado pela contabilidade | Aviso |
| Chave já registrada (duplicidade) | Recusa o envio |

Com item bloqueante pendente, o financeiro só aprova **com justificativa**, registrada no histórico.

## Perfis de acesso

| Perfil | Pode |
|---|---|
| Empresa (admin) | Tudo, inclusive cadastros, contratos e acessos |
| Financeiro | Gerar competência, vincular notas emitidas, registrar recebimentos, aprovar/rejeitar/pagar cobranças |
| Contabilidade | Ver tudo, registrar e confirmar perfis fiscais, exportar fechamento |
| Prestador | Somente as próprias cobranças, notas e pagamentos |

## Rodando localmente (SQLite)

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan gestao:gerar-competencia 2026-10
php artisan serve
```

Acessos de demonstração (senha `piloto-2026`, **troque em produção**):
`admin@gestaopj.local`, `financeiro@gestaopj.local`, `contabilidade@gestaopj.local`, `prestador@gestaopj.local`.

Testes: `php artisan test` (28 testes, incluindo um mês fechado de ponta a ponta).

## Produção (VPS + Docker Compose + MariaDB)

```bash
cp .env.example .env
# edite: APP_ENV=production, APP_DEBUG=false, APP_URL, APP_KEY (php artisan key:generate --show),
#        DB_DATABASE=gestaopj, DB_USERNAME=gestaopj, DB_PASSWORD=<senha forte>
docker compose up -d --build
docker compose exec app php artisan db:seed   # só se quiser os dados de exemplo
```

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

## Próximas fases

- **Fase 2: emissão dentro do painel** pela API da SEFIN Nacional, em produção restrita primeiro. Cobrir emissão, rejeição, recuperação do XML e **consulta após timeout antes de reenviar** (evita nota duplicada). Depois, DANFSe pelo gerador da Receita (serviço Go interno) e consulta de notas recebidas via API do ADN.
- **Fase 3: cálculo de IBS/CBS** pelos motores da Receita (serviços Java internos), escolhidos pelo perfil fiscal vigente do CNPJ. Grava a versão do código e da base usada em cada cálculo. Confirmar antes a disponibilidade da base SQLite/Flyway exigida pela calculadora do Simples.
