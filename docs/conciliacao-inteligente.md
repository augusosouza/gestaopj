# Conciliação inteligente I1

Em **Financeiro → Contas e bancos → Conciliação**, selecione uma linha. A aba **Correspondências** usa as sugestões e ações financeiras já existentes. A aba **Análise inteligente** executa as regras a seguir sobre os registros da empresa ativa.

- **Possível duplicidade** — mesma descrição, sem distinguir maiúsculas, mesmo valor e sentido na mesma conta, em até sete dias. São exibidos até cinco registros candidatos. Uma correspondência exige conferência humana.
- **Tarifa vinculada** — o Augi mostra valor bruto, tarifa e a taxa sobre o bruto. A referência é a mediana das taxas de até 60 recebimentos anteriores, nos últimos 90 dias, da mesma conta e na mesma modalidade de conciliação da tarifa. A comparação exige pelo menos três recebimentos e desconsidera os estornados. Mudanças no tipo de operação e nas condições do contrato podem explicar variações.

Os cálculos são locais. Abrir a análise não altera movimentos e a confirmação permanece no fluxo de conciliação. A listagem carrega 25 linhas por página e calcula as correspondências somente para a linha selecionada.

## Arquivos e atualização

A entrega altera `FinanceiroController`, a view de conciliação e o CSS compartilhado, e acrescenta `AnaliseConciliacao`, duas views parciais e cobertura em `FinanceiroTest`. Sem migration. Em cPanel, preserve `.env`, banco e arquivos privados, publique os arquivos e recarregue o cache das views. Para reverter, reaplique o pacote anterior e recarregue o cache.

O radar de desperdício, o acompanhamento de economia e o provedor generativo são etapas futuras.
