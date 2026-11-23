# Qualidade — teste de mutação

O teste de mutação altera o código de propósito (troca `>` por `>=`, remove uma validação, inverte um `if`…) e verifica se algum teste falha. Cobertura de linhas mostra que o código **rodou**; a mutação mostra que os testes **perceberiam um erro** nele.

## Escopo e resultado

| Escopo | Antes | Depois | Meta |
|---|---|---|---|
| `Compras` + `PosicaoFinanceira` (compras, cartões, faturas, arredondamentos, pagamentos, Meu Caixa) | 52% | **96%** | 80% |

320 mutações geradas · 304 detectadas · 6 ignoradas por configuração (equivalentes) · 10 não detectadas — **todas revisadas e equivalentes** (abaixo).

## Como rodar

```bash
composer mutacao          # = PAO_DISABLE=1 + pcov + Infection, mínimo 80%
```

- `PAO_DISABLE=1` é **obrigatório**: o pacote `laravel/pao` troca a saída do PHPUnit por JSON quando roda dentro de um agente, e o Infection passa a contar tudo como "detectado" (deu um falso 100% na auditoria).
- Requer pcov (ou xdebug) só na máquina de testes/CI. Nada disso vai para o cPanel (`require-dev`).

## CI (`.github/workflows/qualidade.yml`)

1. **Controle** — roda uma amostra de 10 mutações neutras (`--noop`, ~30 s); se alguma for "detectada", a medição está quebrada e o CI falha com a mensagem *Medição inválida*. Isso evita aprovar um número falso por incompatibilidade entre ferramentas.
2. **Medição** — falha se ficar abaixo de 80%.
3. Relatório HTML anexado à execução. Limite de 45 min; um push novo cancela a execução anterior do mesmo branch. No GitHub a medição completa leva cerca de 3× o tempo local (~6–8 min).

Duas falhas artificiais foram encontradas e corrigidas na estabilização: (1) a saída do PHPUnit alterada pelo `laravel/pao`; (2) testes de exportação rodando em paralelo apagavam o arquivo temporário um do outro — cada execução agora usa a sua pasta.

## Mutações excluídas por configuração (`infection.json5`)

| Mutador | Motivo |
|---|---|
| `Concat`, `ConcatOperandRemoval` | Texto de mensagem; não muda regra. As mensagens importantes são conferidas literalmente nos testes. |
| `CastInt`, `CastString`, `CastFloat` | Conversão sobre valor que já tem o tipo (soma de inteiros, texto vindo do formulário). |
| `ArrayItemRemoval` em `organizacao_id` | O escopo multiempresa (`DaOrganizacao`) preenche a empresa quando o campo é omitido. |

## As 10 não detectadas (revisadas — equivalentes)

| Onde | Mutação | Por que não muda o resultado |
|---|---|---|
| `Compras` itens | remove `array_values` | a lista só é percorrida com `foreach`; as chaves não importam |
| `Compras::cancelar` | `$t?->update` → `$t->update` | toda compra tem título (criado na mesma transação) |
| `Compras::faturaPara` | `vencimento > fechamento` → `>=` | dias iguais são proibidos no cadastro do cartão |
| `Compras::faturaPara` | `'organizacao_id' =>` → `>` (chave some) | o escopo multiempresa preenche |
| `Compras::pagarFatura` | `categoriaPadrao(...)?->id` → `->id` | a categoria de encargos sempre existe (é criada se faltar) |
| `PosicaoFinanceira::compromissos` | `$abertas ??=` → `=` | recarrega os mesmos dados (só custo) |
| `PosicaoFinanceira::compromissos` (2×) | `continue` → `break` | as parcelas vêm ordenadas por vencimento: depois da primeira fora do prazo, todas as seguintes também estão |
| `PosicaoFinanceira::compromissos` (2×) | chave de ordenação `0`/`1` → `-1`/`2` | só a ordem relativa importa |
