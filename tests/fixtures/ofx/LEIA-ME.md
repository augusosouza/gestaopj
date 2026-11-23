# Extratos OFX de teste

Cada `nome.ofx` tem ao lado um `nome.esperado.json` com o que o Augi **deve** ler: período coberto, saldo informado pelo banco e cada lançamento (data, valor em centavos, descrição). O teste `OfxArquivosTest` roda todos os pares automaticamente.

Os arquivos atuais são **sintéticos no formato dos bancos** (Itaú OFX 1.02 SGML em CP-1252 com CRLF; OFX 2.x em XML; extrato sem movimento).

## Incluir um extrato real (Itaú, outros bancos)

1. Exporte o OFX no internet banking.
2. Rode: `php artisan ofx:anonimizar caminho/do/extrato.ofx itau-pj-2026-11`
   - zera agência e conta, troca FITID por hash, e nos históricos mantém só palavras bancárias (PIX, TED, TARIFA…), trocando nomes, CPFs e chaves por `X`;
   - mantém datas, valores e saldo (é o que o teste confere).
3. **Abra o `.ofx` gerado** e confirme que não sobrou nenhum dado pessoal.
4. Confira o `.esperado.json` contra o extrato do banco (PDF/tela) e mude `"conferido": false` para `true`. Rascunhos não conferidos ficam fora dos testes.
5. Rode `php artisan test --filter=OfxArquivosTest`.
