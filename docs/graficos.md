# Gráficos e cores por tipo de valor

A **identidade** do Augi continua preto, branco e vermelho. As cores abaixo só servem para identificar o **tipo de valor** em gráficos e indicadores. O número em si continua na cor do texto.

| Tipo | Variável CSS | Claro | Escuro |
|---|---|---|---|
| Receitas | `--c-receita` | `#1c9a6f` (esmeralda) | `#09804e` |
| Despesas | `--c-despesa` | `#e7560e` (coral) | `#da430a` |
| Saldos | `--c-saldo` | `#3556a4` (azul) | `#3657c4` |
| Pendências | `--c-pendencia` | `#e99c1f` (âmbar) | `#bd8b27` |
| Projeções | `--c-projecao` | `#a364c9` (roxo) | `#b466c8` |
| Comparativos | `--c-comparativo` | `#0fb1d9` (ciano) | `#00a3c7` |

Vermelho (`--bad`) não é série: marca valor negativo e alerta (ponto abaixo de zero, linha de zero tracejada).

## Validação (daltonismo, contraste, separação)

Os tons foram escolhidos por busca automática dentro de cada família de cor aprovada e conferidos com o validador de paleta (simulação de protanopia/deuteranopia, distância OKLab, contraste WCAG):

- **Claro:** todas as checagens passam **comparando todos os pares** (pior par sob daltonismo ΔE 9,2; visão normal 15,1). Âmbar e ciano ficam abaixo de 3:1 de contraste com o fundo branco → sempre com rótulo direto ou tabela ao lado.
- **Escuro:** passa na ordem em que as séries aparecem juntas (comparativo, receita, despesa, saldo, projeção, pendência). Receitas × despesas ficam na faixa mínima aceitável (ΔE 7,6 sob protanopia), legal **somente com codificação dupla**: legenda + posição fixa no grupo + valores na dica + tabela. Os componentes já fazem isso.
- Projeções usam **linha tracejada** além da cor (não dependem só do roxo).

## Componentes (Blade, SVG no servidor, sem JavaScript)

- `<x-grafico.linha>` — uma série; pontos com dica; rótulo direto do valor final e do ponto inicial; linha de zero quando há valor negativo.
- `<x-grafico.barras>` — barras horizontais de uma série com o valor escrito.
- `<x-grafico.colunas>` — até 3 séries agrupadas por período, com legenda.
- Indicadores: `class="card serie-receita"` (fio colorido no topo; a barra de progresso dentro do card herda a cor).

Toda tela com gráfico mantém a **tabela equivalente** (acessibilidade e conferência).
