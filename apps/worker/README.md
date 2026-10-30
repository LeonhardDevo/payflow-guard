# Worker

Node 24 · TypeScript (strict, ESM) · BullMQ 5 · grammY · Fastify 5 · pg · nodemailer · pino · prom-client.
Implements PLATFORM.md §4.1 (signing), §4.2 (outbox relay), §4.3/§4.4 (web/ai clients), §4.7 (HTTP + Telegram).
Product identity comes only from `PRODUCT_NAME` / `PRODUCT_SLUG` / `TELEGRAM_BOT_USERNAME`, so the folder is cloned
unchanged into other products.

## Run

```bash
docker compose up -d          # project root: Postgres 5441, Redis 6391, Mailpit 1031/8131
npm install
npm run migrate               # worker schema (also runs on boot)
npm run dev                   # tsx watch; uses .env, or .env.example when .env is missing (non-production)
npm test                      # vitest; Postgres/Redis suites skip with a notice when docker is down
npm run build && npm start    # compiled dist/
npm run lint && npm run typecheck
```

## Layout

| Path | What |
|---|---|
| `src/config.ts` | zod-validated env → frozen `AppConfig` (empty `KEY=` means unset) |
| `src/clients/` | `signing.ts` (§4.1 + shared vector), `internalFetch.ts`, `web.ts`, `ai.ts`, retry classification |
| `src/outbox/` | `OutboxRelay`: `LISTEN outbox` + 2 s poll, `FOR UPDATE SKIP LOCKED LIMIT 100`, jobId = outbox id |
| `src/queues/` | queue names/settings, **`routes.ts` (topic → queue table)**, job defaults, producers, instrumented processor |
| `src/jobs/` | handlers: `email.send`, `telegram.notify`, `document.*`, `workspace.reembed`, `stripe.event`, `telegram.update` |
| `src/bot/` | grammY bot: middleware (locale, rate limit, error boundary), `modules/*`, capture transformer, runtime |
| `src/http/` | Fastify: `/healthz`, `/readyz`, `/metrics`, `/webhooks/stripe`, `/webhooks/telegram`, `/dev/telegram/*`, `/admin/queues` |
| `src/db/` | pool, `worker` schema migrations (run at startup), `webhook_events`, `telegram_chats` |
| `src/i18n/{ru,en}.json` | bot/notification strings with `{product}` placeholder and CLDR plural forms |
| `src/domain/payflow/` | PayFlow queues and jobs (reminder planning every 15 min and dispatch inside send windows, risk recompute, digests, payment-link reconciliation every 30 min, Guard escalations, CSV imports, retention purge), the internal API client, the Telegram module (`/overdue`, `/due`, `/approvals`, `/changes`, `/client`, `/remind`, `/digest`, payout approve/reject with confirmation) and the dev job runner |
| `src/rag/domain/payflow/` | PayFlow assistant: nine read-only data tools, RU/EN intent rules with entity and follow-up resolution, deterministic answers, refusal rules, domain prompt and `POST /v1/payflow/reminder-draft` |

## Extending in a product

- **New outbox topic**: add `'<topic>': QUEUES.<queue>` to `src/queues/routes.ts` and a handler in `src/jobs/index.ts`.
- **Bot features**: create `src/bot/modules/<name>.ts` exporting a `BotModule` (`commands`, `messages` for ru/en,
  `register(composer)`, `actions` for inline buttons, `notifications` templates for `telegram.notify` `{template, params}`),
  then list it in `domainModules` in `src/bot/modules/index.ts`. Button actions without a local handler are forwarded to
  web `POST /internal/v1/telegram/actions`.

## Dev / test endpoints (`DEV_ROUTES=1`, never in production)

```bash
curl -X POST http://127.0.0.1:8101/dev/telegram/update -H 'content-type: application/json' \
  -d '{"from":{"id":4242,"language_code":"ru"},"text":"/start <link-token>"}'
# → {"update_id":…,"outbound":[{"method":"sendMessage","payload":{…}}]}
```

The body is a full Telegram `Update` or the shorthand `{from:{id,username?,first_name?,language_code?}, text?|callback_data?, chat_id?}`.
Without `TELEGRAM_BOT_TOKEN` the bot is offline: notifications are answered locally and listed at `GET /dev/telegram/outbox`.

PayFlow jobs can be run on demand with a simulated clock and drained (scheduler iterations are never promoted):

```bash
curl -X POST http://127.0.0.1:8101/dev/jobs/run -H 'content-type: application/json' \
  -d '{"job":"reminders:plan-all","now":"2026-10-24T09:30:00Z"}'
# → {"enqueued":2,"workspaces":2}
curl -X POST http://127.0.0.1:8101/dev/jobs/drain -H 'content-type: application/json' \
  -d '{"queues":["pf-scheduler","pf-reminders","emails"],"promote_delayed":true,"timeout_ms":15000}'
# → {"processed":4,"failed":0,"idle":true}
```

Client e-mails (`retry_profile: "client_mail"`) are retried 8 times after 1 min, 5 min, 15 min, 1 h, 3 h, 6 h and 12 h; every
retryable failure is reported to web as `deferred` with the attempt number and the next retry time.

---

## Кратко по-русски

Воркер: очереди BullMQ, ретранслятор outbox (LISTEN + опрос каждые 2 с, `FOR UPDATE SKIP LOCKED`, jobId = id из outbox),
доставка писем (SMTP → Mailpit в разработке), Telegram-бот на grammY (RU/EN, привязка аккаунта через `/start <токен>`,
ответы ассистента с источниками и кнопками действий), приём вебхуков Stripe/Telegram с идемпотентностью, Bull Board
на `/admin/queues` (basic auth). Без ключей Telegram/Stripe всё работает офлайн. Модуль PayFlow (`src/domain/payflow/`) планирует и отправляет напоминания, сверяет платёжные ссылки, запускает
сводки, эскалации и импорт, а в Telegram даёт команды по счетам и выплатам с подтверждением решений. Ассистент PayFlow
(`src/rag/domain/payflow/`) отвечает по живым данным через девять инструментов только для чтения и никогда ничего не меняет.
Новые темы outbox добавляются в
`src/queues/routes.ts`, функции бота — модулями в `src/bot/modules/`. Тесты: `npm test` (наборы с Postgres/Redis
пропускаются с пояснением, если docker не запущен).
