# Anexos — prova comparativa (Media Library × armazenamento privado próprio)

**Data:** 23/11/2026 · **Critério aprovado:** menor complexidade, sem comprometer isolamento multiempresa, segurança e auditoria.

## Cenário testado (igual nas duas)

Anexar um comprovante PDF a uma compra; guardar em disco **privado**, separado por empresa; recusar tipo perigoso; garantir que outra empresa não veja; baixar com auditoria.

## Spatie Media Library 11.23 (cópia isolada do projeto)

| Item | Resultado |
|---|---|
| Pacotes novos | 6 (`laravel-medialibrary`, `image`, `image-optimizer`, `zipstream-php`, `temporary-directory`, `composer/semver`) |
| Cola necessária | migration `media` + coluna `organizacao_id`; modelo próprio (`Midia` com `DaOrganizacao`); gerador de caminho por empresa; config de 363 linhas publicada (3 alteradas); `HasMedia` + coleção em cada modelo |
| Upload | 19 ms; caminho `anexos/{empresa}/{uuid}/arquivo.pdf` ✔ |
| Tipo perigoso (.exe) | recusado ✔ |
| Outra empresa (pelo nosso modelo) | não vê ✔ |
| **Risco 1** | `getUrl()` devolve `/storage/anexos/...` — link "público" inexistente para disco privado; qualquer tela que use o padrão do pacote gera link quebrado/enganoso |
| **Risco 2** | o modelo base do pacote (`Media`) **não tem o escopo da empresa**: numa consulta sem escopo viu o arquivo da outra empresa. Comandos internos do pacote (limpeza, regeneração) usam esse modelo |
| **Risco 3** | não guarda hash (sha256) do arquivo; precisaria de propriedade customizada |
| **Risco 4** | a versão 12 exigirá PHP 8.4 (decisão: manter 8.3) |

## Armazenamento privado próprio (padrão de `DocumentoContabil`, já em produção)

| Item | Resultado |
|---|---|
| Pacotes novos | 0 |
| Código | 1 tabela `anexos` (polimórfica, com `organizacao_id`), 1 modelo com `DaOrganizacao`, 1 serviço (~90 linhas), 3 rotas |
| Isolamento | escopo multiempresa em **todas** as consultas (não existe modelo "sem escopo") |
| Segurança | extensão **e** conteúdo real (MIME por `fileinfo`) validados; limite de 10 MB; nome original guardado só para exibição; arquivo salvo com nome aleatório |
| Auditoria | envio, download e remoção registrados em `eventos`; sha256 de cada arquivo |
| Regras do negócio | não remove anexo de lançamento em mês **fechado pelo contador** |
| Backup | `storage/app/private` já está no backup |

## Decisão

**Armazenamento próprio.** Menos dependências, nenhum caminho que ignore o escopo da empresa, hash e auditoria nativos, e consistente com o que o contador já usa. A Media Library só faria sentido se precisássemos de miniaturas, conversões de imagem ou imagens responsivas — não é o caso de comprovantes e notas.
