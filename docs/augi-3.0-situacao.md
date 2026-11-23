# Augi Gestão 3.0 — situação do plano mestre

**Atualizado em:** 23/11/2026 · **Testes:** 212 passando · **Teste de mutação** (Compras + Meu Caixa): 96,82%, verificado no CI do GitHub.

Legenda: ✅ feito · 🟡 parcial · ⬜ falta · ⏸ depende de terceiros/decisão

## Ordem de entregas (item 23)

| Etapa | Situação |
|---|---|
| 1. Testes financeiros e casos extremos | ✅ 212 testes; mutação 96,82% (o plano cita 130 testes e 52,9%: números da auditoria anterior) |
| 2. Menus, abas, telas e cores | ✅ menu hierárquico, abas e cabeçalho padrão, componentes de gráfico; cores: ver "Paleta" abaixo |
| 3. Contas a Pagar | ✅ Visão geral, Em aberto, **Vencidas**, Parceladas, Pagas, Calendário; indicadores total aberto, vencido, **vence hoje**, 7 e **30 dias**, pago no mês |
| 4. Lembretes internos | ✅ 15/10/5/1/0 dias, horário, sem repetição, param ao quitar |
| 5. Google Agenda (OAuth) | ✅ ⏸ credenciais do Google Cloud e verificação do app |
| 6. Anexos privados | ✅ |
| 7. Recebimentos e cobranças | ✅ **nesta entrega** |
| 8. Centros de custo, rateios, orçamentos | ✅ **nesta entrega** |
| 9. Relatórios e DRE gerencial | ✅ **nesta entrega** |
| 10. Filtros salvos e personalização do painel | ⬜ |
| 11. Assistente e sugestões | 🟡 novas perguntas (orçamento, extratos) e sugestão de orçamento; falta "Central de ações" unificada no Início |
| 12. Integrações bancárias e pagamentos | ⏸ Itaú (boleto/Pix) depende de contrato e credenciais |

## Detalhe por seção

| Seção | Situação | Observação |
|---|---|---|
| 2.1 Contas a pagar (Conta Azul) | ✅ | ações rápidas (pagar, parcial), filtros, abas |
| 2.2 Dashboard (Controlle) | 🟡 | painel, Meu Caixa e cards clicáveis; **falta personalizar quais indicadores aparecem** |
| 2.3 Planejamento (Granatum) | ✅ | orçamentos, centros, rateio, comparativo, previsto × realizado |
| 2.4 Central bancária (Nibo) | 🟡 | contas, extratos, conciliação, lembretes; **faltam prévia antes de importar e conciliar 1 movimento com vários títulos** |
| 2.5 Recebimentos (Asaas) | ✅ | central, atrasos por faixa, contatos, promessas, renegociação, régua interna; ⏸ envio externo (e-mail/WhatsApp) e Pix/boleto |
| 2.6 Integração (Omie) | ✅ | compra → parcelas; contrato → títulos; fatura sem duplicar; origem rastreável |
| 2.7 Operacional (GestãoClick) | 🟡 | busca, filtros, exportação, histórico; **faltam filtros salvos, paginação em todas as listas e ações em lote** |
| 3 Menu | ✅ | Financeiro com seções; Inteligência; Administração Augi separada |
| 5 Painel | 🟡 | "Livre para decidir" conservador já existe; **falta saldo mínimo de segurança configurável** |
| 7 Cadastro de conta a pagar | 🟡 | tem centro de custo, competência, vencimento, forma, parcelas, documentos; **faltam campos de juros/multa previstos e responsável no cadastro** (hoje entram como acréscimo na baixa) |
| 9 Compras | ✅ | itens, entrada, juros, nota, garantia, centro de custo; ⬜ parcelas de valores diferentes |
| 10 Cartões | 🟡 | ⬜ pagamento parcial e parcelamento de fatura, estornos/devoluções |
| 11 Cobranças | ✅ | ver 2.5 |
| 12 Conciliação | 🟡 | ver 2.4 |
| 13 Lembretes de extrato | 🟡 | semanal/quinzenal/mensal; ⬜ diária e personalizada |
| 14 Categorias/centros/orçamentos | ✅ | subcategorias (3 níveis), linha da DRE por categoria |
| 15 Meu Caixa | 🟡 | simulação existe; ⬜ cenário "vencidos entram em 7/15/30 dias" |
| 17 Central de ações | 🟡 | Sugestões e "Ações necessárias" existem separadas; ⬜ unificar no Início |
| 18 Configurações | 🟡 | central com busca; ⬜ abas Recebimentos, Contabilidade, Inteligência, Segurança (sessões) |
| 19 Relatórios | ✅ | 10 relatórios + 4 atalhos; exportação Excel/CSV |
| 20 Compatibilidade BR | 🟡 | CNPJ alfanumérico, OFX, BRL, datas; ⬜ CEP/municípios no cadastro, CNAB |

## Paleta (item 6) — decisão pendente

Os códigos propostos foram conferidos com o validador de acessibilidade de cores (simulação de daltonismo + contraste):

| Par | Distância com daltonismo | Mínimo |
|---|---|---|
| Receitas `#16A085` × despesas `#EF767A` | **3,1** (protanopia) | 6 (ideal 8) |
| Saldos `#3B82F6` × projeções `#8B5CF6` | **1,3** (deuteranopia) | 6 |
| Comparativos `#06B6D4` × receitas `#16A085` | 11,8 sem daltonismo | 15 |

Resultado: **reprovados** — para ~8% dos homens, receitas e despesas ficam praticamente da mesma cor. As cores em uso hoje são das **mesmas famílias** (esmeralda, coral, azul, âmbar, roxo, ciano) e passam em todas as checagens (ver `docs/graficos.md`). Mantidas até decisão.

O vermelho institucional proposto (`#E54857`) já é o do modo escuro; no claro usamos `#b8232b` (mais contraste com o branco: 6,4:1 contra 4,1:1).
