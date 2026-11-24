# Radar de desperdícios e acompanhamento de economia

Em **Inteligência → Sugestões → Oportunidades de economia**, compare dois meses completos. O radar mostra tarifas registradas, acréscimos e as maiores despesas de tarifa com links. Registre uma ação e sua referência para acompanhar meses posteriores. O histórico de ações usa a trilha de eventos da empresa.

O total registrado usa baixas ativas do mês da movimentação e a categoria Tarifas bancárias. A comparação ajustada usa somente tarifas de recebimento vinculadas, pelo mês do recebimento bruto, separadas por conta e modalidade e com pelo menos três operações por grupo em ambos os meses. A taxa de referência é a soma das tarifas vinculadas dividida pela soma dos valores brutos. O custo esperado aplica essa taxa ao volume bruto mais recente. A diferença entre custo esperado e observado é uma estimativa ajustada, sem atribuição causal e sem somar as mesmas tarifas duas vezes. O radar reflete os dados atuais, de modo que um estorno posterior pode mudar uma comparação histórica.

Em **Configurações → Inteligência**, o administrador pode ativar a orientação da OpenAI, cadastrar ou remover a credencial e definir a quantidade mensal de solicitações. Cada consulta exige consentimento explícito. A chave fica criptografada pela chave da aplicação e o provedor recebe apenas sinais agregados e um catálogo de ações. As quantidades monetárias ficam no Augi. O modelo prioriza ações desse catálogo e a aplicação valida os códigos. Solicitações e consumo informado pelo provedor ficam na auditoria. O limite conta tentativas e não substitui um orçamento monetário na OpenAI.

O fluxo reutiliza as baixas, o vínculo da tarifa, o histórico de eventos, Sugestões e Configurações de Inteligência. Foram acrescentados `RadarEconomia`, `IaRadar`, a view do radar e a migration `2026_12_11_000100_add_ia_radar_to_organizacoes.php`. A IA é opcional e o provedor não executa ações no sistema.

## Atualização e reversão

Faça backup da aplicação, do banco e dos arquivos privados. Preserve `.env`, `APP_KEY` e `storage`. Após atualizar os arquivos, execute `php artisan migrate --force` e recarregue os caches. Confira o radar e, quando autorizada, teste a orientação com uma credencial do próprio ambiente. Para reverter o código, reaplique o pacote anterior em manutenção e mantenha as colunas adicionais para preservar configuração e histórico. Se precisar remover a credencial do banco, desative e revogue o acesso no Augi e no provedor antes de reverter.

## Alertas no Financeiro e no Meu Caixa

O resumo do Painel Financeiro mostra os alertas dos próximos 30 dias usando a mesma projeção diária do Meu Caixa. O Meu Caixa acompanha o horizonte, o saldo das contas selecionadas e o cenário em análise. A seleção de contas controla o saldo de partida. Os compromissos previstos são da empresa ativa.

O alerta de concentração destaca o maior compromisso a pagar da projeção ou a maior baixa em dinheiro acumulada em um título da competência, conforme a tela. O critério inicial exige pelo menos R$ 1.000 e participação mínima de 25% no respectivo total. A indicação representa concentração financeira. O link abre o registro original para conferência. A simulação pode afetar o alerta de déficit, mas não entra no cálculo da concentração nem gera um lançamento.

A lista do Meu Caixa usa a data projetada e mostra o saldo ao fim de cada dia calculado pela série do gráfico. As parcelas vencidas conservam a data de vencimento no indicador de atraso.

A entrega reutiliza `Financeiro`, `PosicaoFinanceira`, o acesso ao Radar de Economia e os padrões de ação Blade. O novo componente `alertas-caixa` é compartilhado pelas duas telas. Esta revisão não adiciona migrations. No cPanel, reaplique o pacote mantendo a configuração e os arquivos privados e limpe o cache das views com `php artisan view:clear`. A reversão consiste em reaplicar os arquivos do pacote anterior e limpar o mesmo cache.
