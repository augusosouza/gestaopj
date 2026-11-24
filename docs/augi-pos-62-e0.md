# Pós-6.2 — publicação e recuperação

## 1. Base reaproveitada

O workflow de testes PHPUnit, o empacotador cPanel e a rotina `backup:restaurar` continuam sendo os pontos de entrada. A recuperação usa o mesmo cofre, o manifesto da cadeia incremental e a preparação de banco e arquivos em diretório privado.

## 2. Alterações

- `.github/workflows/tests.yml` executa a matriz de PHP nas atualizações da branch principal e pode ser chamado por outro workflow. A recuperação base e incremental também roda em MariaDB descartável.
- `.github/workflows/pacote-cpanel.yml` exige a conclusão da suíte antes do empacotamento, verifica o SHA alvo, o checksum do ZIP e o conteúdo mínimo, e rejeita arquivos conhecidos de configuração ou ativação da instalação.
- `Backups.php` compartilha a trava com a rotina de backup. A extração exige pasta vazia que não seja um link simbólico.
- `routes/console.php` mantém a manutenção ativa até o operador autorizar a reabertura.
- `BackupTest.php` cobre a trava, a extração em pasta ocupada e a manutenção após sucesso.

A entrega não exige migrations.

## 3. Testes executados

Testes de restauração de base e incremental, pacote base ausente, senha incorreta, pacote adulterado, extração sem alteração da instalação, backup e restauração concorrentes pelo mesmo mecanismo de trava, pasta ocupada e estado de manutenção.

## 4. Homologação

Conferir os resultados da suíte e do job MariaDB anexados ao workflow do pacote. O exercício usa infraestrutura descartável. A homologação da restauração no cPanel real depende de uma cópia isolada da instalação e de seus próprios backups. O dump e a substituição dos arquivos continuam sendo etapas separadas, portanto a validação pós-restauração e o ponto de recuperação independente seguem necessários.

## 5. Pendências

Confrontar esta entrega com a seção E0 do Roadmap Auditado Pós-6.2. A execução de adiantamentos e devoluções fica após esse confronto e a estabilização da publicação e da recuperação.

## 6. Implantação e reversão no cPanel

1. Preserve o pacote atual, o banco e os arquivos privados em cópia independente. Guarde a chave original em local seguro e confirme que o backup pode ser lido.
2. Confira o SHA256 do pacote novo. Faça a substituição dos arquivos da aplicação em manutenção, preservando `.env`, `storage` e o banco. Limpe e recarregue os caches com o PHP da instalação.
3. Confira o login e execute `php diagnostico.php`. Retome a operação após homologar o ambiente.
4. Para testar recuperação, use `php artisan backup:restaurar PACOTE --extrair=/pasta-privada-vazia`. Confira a estrutura e faça uma restauração completa primeiro em um banco e diretório de teste exclusivos.
5. Para uma recuperação real, suspenda agendadores e trabalhadores de fila, ative manutenção e execute `php artisan backup:restaurar PACOTE --confirmar`. Verifique o banco, a compatibilidade das migrations, o acesso a arquivos privados e os registros financeiros antes de `php artisan up`. Em caso de falha, mantenha a manutenção e recupere a cópia independente antes de retomar o serviço.
6. Para reverter apenas esta entrega, reaplique o código do pacote anterior em manutenção e limpe e recarregue os caches. Se uma recuperação real tiver substituído dados, a reversão exige o ponto independente de banco e arquivos do passo 1.

## Conferência com o Roadmap Auditado de 24/11/2026

A auditoria usou `188d1a9` como referência. A branch oficial é `ccr-daa1b2de-5vtuog`. As mudanças desta rodada complementam a entrega de publicação e recuperação:

- O pacote é montado para um rascunho que aponta ao SHA aprovado. `MIGRATIONS.txt` lista as mudanças desde a última release publicada e integra `SHA256SUMS.txt`.
- Em MariaDB/MySQL, a cópia do banco usa uma transação própria com isolamento `REPEATABLE READ`. Os arquivos incluídos no ZIP são relidos e comparados ao manifesto antes da publicação do backup.
- O workflow MariaDB cobre a cadeia base/incremental, tarifas embutidas, débitos separados na mesma data ou em outra data, estorno, competência fechada e baixas concorrentes. A suíte geral mantém os testes de isolamento multiempresa e de repetição de solicitações.
- A leitura do histórico disponível com Gitleaks 8.28.0, com valores suprimidos, retornou zero candidatos. É uma verificação automatizada limitada às referências obtidas e às regras da ferramenta.
- O repositório é público, o `composer.json` declara MIT e a API do GitHub não reconhece um arquivo de licença. A estratégia comercial e de licenciamento precisa de decisão do responsável. A visibilidade e a declaração de licença foram preservadas.

O snapshot do banco e os arquivos privados pertencem a sistemas distintos. A verificação do ZIP detecta divergência nos bytes arquivados, mas não comprova atomicidade do ponto de recuperação entre banco e arquivos. Para uma cópia de reversão, suspenda escritas, cron e filas durante o backup.

### Checklist complementar para o cPanel

- Compare o SHA da release e verifique `sha256sum -c SHA256SUMS.txt`. Revise `MIGRATIONS.txt`.
- Valide em cópia isolada com o mesmo PHP e a mesma família de banco. Teste SSL, cron, SMTP e a abertura de arquivos privados. Nunca execute a restauração de ensaio no banco ou diretório de arquivos de produção.
- Preserve a configuração, a `APP_KEY` e a cópia independente de banco e arquivos. Durante manutenção, atualize código privado e arquivos públicos, execute `php artisan migrate --force` quando houver migrations pendentes, sincronize o agendador com `php artisan schedule-monitor:sync` e limpe e reconstrua os caches da aplicação.
- Antes de reabrir, confira login, arquivos privados, baixa e conciliação em homologação, diagnóstico e tarefas agendadas. Retome cron e filas depois da liberação.
- Para reverter uma publicação que aplicou migrations, confirme compatibilidade do schema com o código anterior. Quando houver incompatibilidade, use a cópia independente e preserve o registro das operações posteriores para reconciliação. Esta rodada da E0 não acrescenta migration.
