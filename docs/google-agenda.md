# Google Agenda (FIN-REM-01)

Sincronização **em um sentido** (Augi → Google). Cada usuário conecta a **própria** conta Google, em **cada** empresa. Os lembretes internos do Augi (central de notificações) funcionam com ou sem o Google.

## O que acontece

| No Augi | No Google Agenda |
|---|---|
| Nova conta a pagar (ou parcela) | 1 evento, no horário escolhido do dia do vencimento, com até 5 lembretes |
| Vencimento alterado | o **mesmo** evento é atualizado (sem duplicar) |
| Pagamento parcial | evento e alertas continuam (há saldo) |
| Quitada | evento removido, ou mantido como "✓ Quitado" sem alertas (preferência) |
| Cancelada | evento removido |
| Compra no cartão | 1 evento por **fatura** (não por compra) |
| Desconectar | acesso revogado; opcionalmente apaga a agenda/eventos criados |

- **Privacidade:** por padrão o título é discreto ("Pagamento — Augi"), sem fornecedor nem valor; a descrição só tem o link do lançamento (exige login). Eventos privados e "livres" (não bloqueiam a agenda).
- **Falhas:** erro do Google fica registrado e é refeito na próxima rodada (a cada 15 min). Limite de requisições pausa 15 min. Acesso revogado avisa o usuário e para de tentar. Nada disso afeta o financeiro.
- **Auditoria:** conexão, desconexão, sincronizações com mudança ou erro, e alterações de vencimento ficam em `eventos`.

## Permissões pedidas (mínimas)

| Modo | Escopos |
|---|---|
| Agenda separada "Augi Gestão — Financeiro" (recomendado) | `calendar.app.created` — só a agenda criada pelo Augi |
| Agenda existente | `calendar.events.owned` + `calendar.calendarlist.readonly` |

Mais `openid email`, para mostrar qual conta foi conectada.

## Habilitar (administrador da plataforma)

1. No **Google Cloud Console**, crie um projeto e ative a **Google Calendar API**.
2. Em **APIs e serviços → Tela de consentimento OAuth**: tipo *Externo*, nome "Augi Gestão", e-mail de suporte, domínio, links de política de privacidade e termos. Adicione os escopos acima.
3. Em **Credenciais → Criar ID do cliente OAuth → Aplicativo da Web**, com o URI de redirecionamento:
   `https://SEU-DOMINIO/integracoes/google/retorno`
4. No `.env` do servidor (nunca no banco):
   ```
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   ```
5. Enquanto o app estiver em "Teste" no Google, só os e-mails cadastrados como testadores conseguem conectar. Para liberar a todos os clientes, é preciso **publicar e passar pela verificação do Google** (escopos de agenda são "sensíveis"; o `calendar.app.created` costuma ter verificação mais simples).
6. Cron do cPanel (o mesmo de sempre): `* * * * * php artisan schedule:run`. A sincronização roda a cada 15 min (`google:sincronizar`) e os lembretes internos a cada 10 (`financeiro:vencimentos`).

Sem credenciais, a tela mostra "integração não habilitada" e cada lançamento oferece o link **"Adicionar ao Google Agenda (manual)"**, que abre o Google com o evento preenchido.
