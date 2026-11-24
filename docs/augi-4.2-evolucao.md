# Augi Gestão 4.2

Base avaliada no GitHub: `3d7e76b`. A classificação abaixo descreve o código antes deste ciclo. “Parcial” indica infraestrutura reaproveitável e lacunas funcionais ainda abertas.

| Requisito | Situação na base | Infraestrutura e lacuna principal |
|---|---|---|
| GOV-01 | Parcial | Auditoria de identidade, SMTP e papéis. Faltavam visão conjunta, aprovação e reversão de configurações. |
| GOV-02 | Parcial | Perfis fiscais, contratos e vínculos têm vigência. Configurações gerais ainda precisam de versionamento efetivo por data. |
| SEC-01 | Novo | As rotas já usam contexto e papéis. Faltava consultar a decisão para outro vínculo sem efetuar login ou executar a ação. |
| SEC-02 | Novo | Vínculos e revogação podem alimentar campanhas de revisão. Falta o fluxo periódico com responsáveis e evidências. |
| SEC-03 | Parcial | Vínculos expiram pela vigência. Faltam concessões por recurso e ação com aprovação e prazo. |
| SEC-04 | Parcial | Auditoria financeira e baixas existem. A chave Pix do prestador era alterada diretamente e faltavam revisão e alertas. |
| SEC-05 | Parcial | TOTP, sessões, auditoria e alertas de saúde existem. Faltam agregação de sinais e gestão de incidentes. |
| SEC-06 | Parcial | Contexto revalidado, escopos e testes de duas empresas. Cada novo cache, job ou integração precisa de verificação própria. |
| OPS-01 | Parcial | Saúde do sistema, agendador, backup e teste SMTP. Faltam diagnóstico unificado de filas, integrações, TLS e DNS. |
| OPS-02 | Parcial | Seed fictício e configuração de teste. Falta operação de homologação isolada com controles de saída. |
| AUT-01 e AUT-02 | Novos | Rotinas agendadas existentes são implementadas em código. Faltam catálogo de regras, prévia e motor controlado. |
| COM-01 | Novo | SMTP com envio de teste pode ser reaproveitado. Falta laboratório com HTML sanitizado e versões. |
| COM-02 e COM-03 | Parciais | Há configuração SMTP e validação de e-mails. Faltam confirmações do provedor, remetentes por empresa e supressão. |
| COM-04 | Novo | Históricos de cobrança existem. Falta integração com provedor de recebimento e associação validada. |
| IA-01 | Parcial | Assistente local por regras, com ativação por empresa. O envio a IA generativa exige política de dados antes da integração. |
| IA-02, IA-03 e IA-04 | Novos | Roteamento, custos e prompts dependem da escolha e autorização de provedores e do modelo de consumo. |
| FIN-01 e FIN-02 | Parciais | Há indicadores, projeções e simulação Meu Caixa. Faltam métricas gerenciais adicionais e cenários persistidos à parte. |
| EMP-01 e EMP-02 | Parciais | Compras, prestadores, contratos e lembretes existem. Faltam visões dedicadas de fornecedores e renovações. |
| CONT-01 | Parcial | Solicitações, prazos, anexos e versões imutáveis existem. Falta completar a gestão de prioridade e responsabilidade. |
| BR-01 | Parcial | OFX e documentos fiscais já existem. Novos conectores bancários dependem de homologação e contrato. |
| SAAS-01 e SAAS-02 | Novos | Há administração global e preferências por empresa. Faltam catálogo comercial, limites e liberação gradual. |
| API-01 | Novo | Falta API de parceiros com credenciais, escopos e auditoria. |
| LGPD-01 | Novo | Autorizações e armazenamento privado servem de base. Faltam inventário, retenção e fluxo de direitos de titulares. |
| DEV-01 | Parcial | GitHub Actions, testes e saúde existem. Falta painel de versão implantada e consolidar verificações de qualidade. |

## Primeira entrega do ciclo 1

- **SEC-01.** Em Administração → Equipe e acessos → Simular acesso, compare as regras de papel, vigência, empresa autorizada e 2FA para bancos, financeiro, equipe e contabilidade. Os resultados se referem ao acesso à ação. Validações de uma operação concreta continuam na rota de destino.
- **SEC-04.** Em Operação → Prestadores → Dados de pagamento, uma alteração ou remoção de Pix para um prestador existente exige senha, motivo e decisão de outro administrador. O solicitante pode cancelar. A chave anterior permanece em uso durante a revisão. As chaves do pedido ficam criptografadas com a chave da aplicação. O histórico central contém eventos e responsáveis, sem as chaves.
- **GOV-01.** Em Administração → Governança, consulte mudanças de dados de pagamento, equipe e configurações da empresa. A aprovação da entrega atual se aplica à chave Pix do prestador. Aprovação genérica e reversão de configurações continuam previstas.

A aplicação da chave aprovada é imediata. Prazo de segurança, revisão do Pix inicial e detecção heurística de pagamentos suspeitos são etapas futuras. O fluxo registra e sinaliza mudanças cadastrais; pagamentos continuam sendo realizados fora do Augi. Execute as migrações em homologação antes de ativar este código em produção. Preserve a chave de criptografia da instalação nos procedimentos de backup e restauração.
