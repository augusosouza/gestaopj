# Auditoria de dependências — Financeiro 2.1

**Data:** 23/11/2026 · **Base:** commit `790a5fa` (Financeiro 2.0, 130 testes) · **Alvo de produção:** PHP 8.3 em cPanel (sem Node, sem root, sem `proc_open` garantido).

Cada biblioteca foi **resolvida de verdade** contra o nosso `composer.json`/`composer.lock` com a plataforma travada em PHP 8.3.6 (`composer require --no-install`, numa cópia isolada). Onde dava para provar, rodamos uma prova de conceito contra os nossos dados de teste. Nada foi instalado no projeto.

## Resumo

| Biblioteca | Veredito | Por quê, em uma linha |
|---|---|---|
| Spatie Simple Excel | **Adotar** | 2 pacotes MIT; exportou 20 mil linhas em 0,5 s com +2,4 MB de memória; sem tabela nova. |
| Spatie Laravel Health | **Adotar (adaptado)** | 4 pacotes; trocar a verificação de disco (usa `df`, que o cPanel pode bloquear) por uma nossa. |
| Spatie Schedule Monitor | **Adotar** | 3 pacotes; registra início/fim/falha de cada rotina (lembretes, backups). 2 tabelas. |
| Infection | **Adotar (só desenvolvimento/CI)** | Rodado de verdade: os testes do Financeiro 2.0 detectam **52%** das mutações em Compras e Meu Caixa. Achou lacunas reais (seção 8). |
| Spatie Media Library | **Adaptar** | Funciona em PHP 8.3 (linha 11), mas o padrão é disco público e a tabela `media` não tem `organizacao_id`. Exige modelo próprio. A próxima versão (12) pedirá PHP 8.4. |
| Laravel Pennant | **Adiar** | Bom e oficial (1 pacote), mas ainda não há módulo piloto para ligar/desligar. Adotar no primeiro piloto. |
| Brick Money | **Adiar** | Correto, mas é 0.x: a API de rateio mudou entre versões (vimos isso na prova). Divide centavos na 1ª parcela; o Augi põe na última. |
| Spatie Query Builder | **Descartar por ora** | 7.3.6+ exige PHP 8.4 (só a 7.3.5 serve). Nossos filtros de situação são calculados (saldo), não colunas. Trocaria as URLs atuais. |
| Chart.js | **Descartar (padronizar o que temos)** | Nossos gráficos são SVG no servidor, acessíveis e testados. Criar componentes Blade resolve a padronização sem ~200 KB de JS. |
| Filament | **Descartar por ora** | 34 pacotes (Livewire incluso) para 2 telas de Administração Augi; tema próprio exige build com Node; segundo sistema de layout e de autenticação. |
| OFX Parser (jacques / asgrim) | **Descartar** | Última versão compatível é de 2015; **falhou nos 4 arquivos** de teste, inclusive no formato SGML dos bancos. O nosso leu os 4. |

**Resultado:** 4 adoções, 1 adaptação, 2 adiamentos, 4 descartes. Código novo a manter é pequeno; nenhuma troca do núcleo financeiro.

---

## 1. Spatie Simple Excel — adotar

- **Versão resolvida:** 3.11.0 (MIT), puxa `openspout/openspout` 5.3.0 (MIT). Nada mais muda no lock além de atualizações de correção já pendentes (guzzle, phpdotenv).
- **Requisitos:** PHP 8.3, `ext-zip`, `ext-xmlreader`, `ext-dom` — comuns no cPanel; **conferir no servidor** (`php -m`).
- **Prova:** XLSX com 20.000 linhas em 474 ms, 410 KB, pico de +2,4 MB (escrita em fluxo). Releitura correta de acentos e valores.
- **Migrations:** nenhuma.
- **Onde entra:** exportação de lançamentos, compras, inadimplência, fluxo de caixa e DRE. Exportar sempre a partir dos **mesmos serviços das telas** (mesmos números) e registrar em `eventos` (já fazemos isso no fechamento).
- **Cuidado:** valores saem como número (não texto) e datas como data; colunas com CNPJ como texto (para não perder zeros à esquerda).

## 2. Spatie Laravel Health — adotar, adaptado

- **Versão:** 1.42.0 (MIT) + `spatie/enum`, `spatie/regex`, `spatie/laravel-package-tools`.
- **Adaptação necessária:** a verificação de disco usada (`UsedDiskSpaceCheck`) chama `df` por processo externo; em cPanel com `proc_open` desativado ela falha. Substituir por uma verificação nossa com `disk_free_space()`.
- **Verificações úteis:** banco, agendador vivo (heartbeat), modo debug desligado, cache/config otimizados, último backup recente (apontando para a nossa pasta de backups), fila.
- **Migrations:** opcional (histórico em tabela); pode guardar o último resultado em cache/arquivo.
- **Onde entra:** página em **Administração Augi → Saúde do sistema** e aviso para o administrador da plataforma.

## 3. Spatie Schedule Monitor — adotar

- **Versão:** 4.3.3 (MIT) + `lorisleiva/cron-translator`.
- **O que dá:** registro de início, fim, falha e "não rodou" de cada tarefa agendada (`financeiro:lembretes`, backups base e incrementais, sincronizações).
- **Migrations:** 2 tabelas (`monitored_scheduled_tasks`, `monitored_scheduled_task_log_items`); limpeza com `model:prune`.
- **Operação:** rodar `schedule-monitor:sync` a cada deploy. Funciona com o cron único do cPanel.

## 4. Infection — adotar só no desenvolvimento/CI

- **Versão:** 0.35.12 (BSD-3) + 17 pacotes, todos `require-dev` (não vão para o cPanel).
- **Requisito:** driver de cobertura (pcov ou xdebug) na máquina de testes/CI.
- **Uso proposto:** rodar nos serviços críticos (Financeiro, Compras, PosicaoFinanceira, Fechamento) e exigir um MSI mínimo no CI. Resultado real na seção 8.

## 5. Spatie Media Library — adaptar

- **Versão resolvida:** 11.23.14 (MIT), +7 pacotes (`spatie/image`, `image-optimizer`, `zipstream`…). A linha **12 (em desenvolvimento) exigirá PHP 8.4** — mesma situação do Query Builder no futuro.
- **Requisitos:** `ext-exif`, `ext-fileinfo`; GD ou Imagick só se gerarmos miniaturas.
- **O que não podemos aceitar do padrão:**
  1. disco `public` → usar disco **privado** (`local`, já usado para XMLs e documentos do contador);
  2. tabela `media` sem `organizacao_id` → **modelo de mídia próprio** (`config('media-library.media_model')`) com a coluna e o escopo `DaOrganizacao`, para manter o isolamento e o 404 entre empresas;
  3. URLs públicas → download só por rota autenticada, com auditoria (como já fazemos com XML e documentos).
- **Migrations:** tabela `media` + `organizacao_id` e índice.
- **Backup:** os arquivos ficam em `storage/app/private`, que o backup já cobre.
- **Alternativa a comparar na prova de conceito:** estender o padrão que já existe em `DocumentoContabil` (upload privado, sha256, download auditado) para uma tabela `anexos` polimórfica. Se a prova mostrar que só usaremos upload/download (sem miniaturas, coleções e conversões), o modelo próprio fica menor do que a cola com a biblioteca.

## 6. Laravel Pennant — adiar

- **Versão:** 1.28.0 (MIT), 1 pacote, tabela `features`.
- **Quando adotar:** no primeiro módulo que for liberado só para empresas piloto (ex.: assistente, integração Itaú). O escopo deve ser a **organização**, não o usuário.

## 7. Brick Money — adiar

- **Versão:** 0.15.2 (MIT) + `brick/math` 1.0.0.
- **Prova:** rateio 600,00 em 50/30/20 = 300 + 180 + 120 (exato); 100,00 em 3 = 33,34 + 33,33 + 33,33; 10 mil rateios em ~1,1 s sem `bcmath`/`gmp`.
- **Por que adiar:**
  - **é 0.x**: na prova, `allocate()` e `split()` exigiram um parâmetro novo de modo — a assinatura mudou entre versões recentes;
  - o modo disponível coloca a sobra de centavos na **primeira** parcela; o Augi coloca na **última** (já está em teste e no comportamento visível);
  - o rateio de centro de custo precisa de ~20 linhas (maior resto) dentro de `App\Support\Dinheiro`, testadas como o resto.
- **Reavaliar** quando houver juros compostos, multimoeda ou a 1.0.

## 8. Infection — resultado real

Executado numa cópia isolada do projeto, com pcov, sobre `app/Services/Compras.php` e `app/Services/PosicaoFinanceira.php`, usando os testes de `Financeiro2Test`.

**Armadilha encontrada (e contornada):** a primeira rodada deu "100%". Uma rodada de controle (`--noop`, mutações que não mudam nada) também deu 100% — ou seja, o número era falso. Causa: o pacote `laravel/pao` (já no projeto) troca a saída do PHPUnit por JSON quando roda dentro de um agente, e o Infection não entende esse formato. Com `PAO_DISABLE=1`, o controle deu 0% (correto). **Esta variável precisa estar no CI.**

| Arquivo | Mutações | Detectadas | Escaparam |
|---|---|---|---|
| Compras.php + PosicaoFinanceira.php | 382 | 202 | 180 (123 em Compras, 57 no Meu Caixa) |

**MSI (cobertura por mutação): 52%** — a cobertura de linhas é 100%, mas metade das alterações no código não faz nenhum teste falhar.

O que escapou, por importância:

1. **Validações sem teste** (18 casos): remover regras como "desconto maior que o valor", "entrada maior que o total", "cartão inativo", "pagamento sem conta" não quebra nenhum teste. As regras existem e funcionam, mas um ajuste futuro poderia apagá-las sem aviso.
2. **Quantidade fracionada e arredondamento dos itens**: trocar `round` por `floor`/`ceil` ou ignorar a vírgula em "1,5" passa despercebido — falta um teste com quantidade decimal.
3. **Meu Caixa**: conversões de tipo e o tratamento de recebimento vencido quando incluído na projeção têm cobertura fraca (57 mutações).
4. **Textos de mensagens** (≈40 casos, `Concat`): pouco relevantes; podem ser excluídos da métrica.

**Proposta:** escrever os testes que faltam para os itens 1–3 (≈1 dia), excluir mutadores de texto e exigir **MSI mínimo de 80%** nos serviços financeiros no CI.

## 9. Spatie Query Builder — descartar por ora

- **Fato confirmado no Packagist:** 7.3.6 a 7.3.9 exigem PHP 8.4; a última para 8.3 é a **7.3.5** (03/09/2026). Ficar preso nela significa não receber correções.
- **Encaixe ruim:** os filtros que mais importam (situação: aberto, parcial, vencido) dependem do **saldo calculado** das baixas, não de uma coluna — a biblioteca exigiria filtros customizados para cada um.
- **Mudaria as URLs** (`?filter[situacao]=…`), que hoje são usadas pelas sugestões, pela agenda e pelo assistente.
- **Proposta:** filtros salvos com uma tabela simples (`visoes_salvas`: usuário, organização, tela, query string). Rever a biblioteca se o servidor for para PHP 8.4.

## 10. Chart.js — descartar a dependência e padronizar os componentes

- Os gráficos atuais (painel e Meu Caixa) são **SVG gerados no servidor**: funcionam sem JavaScript, imprimem bem, têm texto alternativo e tabela equivalente, e já têm o mesmo visual.
- Chart.js (MIT) traria ~200 KB de JS e desenho em `<canvas>` (sem texto para leitores de tela).
- **Proposta:** extrair `<x-grafico-linha>` e `<x-grafico-barras>` (hoje o código do gráfico se repete em duas telas). Reavaliar Chart.js só se precisarmos de zoom, séries empilhadas interativas ou atualização em tempo real.
- **Paleta:** a mensagem cita "verde receitas, coral despesas, azul saldos, âmbar pendências, roxo projeções". Neste projeto a paleta aprovada foi **preto, vermelho e branco**, com vermelho reservado para valor negativo/alerta. Precisa de decisão antes de mexer.

## 11. Filament — descartar por ora

- 5.12.0 (MIT) resolve em PHP 8.3, mas traz **34 pacotes**, incluindo Livewire 4.
- **Custos:** segundo sistema de layout, de autenticação e de navegação; tema próprio exige compilar CSS (Tailwind) com Node, que o cPanel não tem; o isolamento por empresa precisaria ser refeito no "multi-tenancy" do Filament.
- **Benefício hoje:** pequeno — a Administração Augi tem 2 telas e a Central de Configurações já existe.
- Reavaliar se a administração da plataforma crescer (planos, cobrança do SaaS, revendas).

## 12. OFX Parser — descartar

- `jacques/ofxparser` não tem versão instalável em PHP 8 (a última exige PHP 5.6/7.0).
- `asgrim/ofxparser` instala só a **1.1.1, de 2015**. Resultado nos nossos 4 arquivos:

| Arquivo | Biblioteca | Parser do Augi |
|---|---|---|
| SGML com cabeçalho, CP-1252, `&` sem escape (formato comum de banco) | erro de XML | 3 lançamentos, acentos certos |
| Mesmo arquivo sem `&` | erro (`reset()` em `null`) | 3 lançamentos |
| Mínimo (usado nos nossos testes) | erro de data | 1 lançamento |
| Sem movimento, só período e saldo | erro | período lido (cobertura do extrato) |

- O nosso importador já lê período (DTSTART/DTEND), saldo do banco (LEDGERBAL), valores com vírgula e evita duplicidade. **Próximo passo de verdade:** testar com arquivos reais exportados do Itaú (e de outros bancos que vocês usam).

## 13. Sistemas completos (só referência)

- **InvoiceShelf** e **Firefly III** são AGPL-3.0: copiar código para um SaaS obriga a abrir o código do serviço. Usar só como referência de fluxo e tela.
- **Invoice Ninja** usa Elastic License 2.0 (não é open source permissiva): idem.

## 14. Impacto nas migrations, se tudo o que foi aprovado entrar

| Biblioteca | Tabelas |
|---|---|
| Media Library (adaptada) | `media` (+ `organizacao_id`) — ou `anexos`, se o modelo próprio vencer |
| Schedule Monitor | `monitored_scheduled_tasks`, `monitored_scheduled_task_log_items` |
| Health | nenhuma (ou 1 de histórico, opcional) |
| Pennant (quando entrar) | `features` |
| Demais | nenhuma |

Nenhuma alteração em `titulos`, `parcelas`, `movimentos` ou nas tabelas do Financeiro 2.0.

## 15. Decisões pendentes

1. **PHP 8.4 no servidor?** Destrava Query Builder atual e a futura Media Library 12. Verificar no cPanel da hospedagem (MultiPHP Manager).
2. **Paleta dos gráficos:** manter preto/vermelho/branco ou adotar a paleta colorida?
3. **Anexos:** Media Library adaptada ou modelo próprio? (decidir pela prova de conceito)
4. **Arquivos OFX reais** do Itaú para validar o importador.
