# PayFlow Guard

**Get every invoice paid, and never pay a fraudster.** PayFlow Guard is the cash-control layer for small
service businesses (agencies, dev studios, B2B contractors with 5–50 people): it takes an invoice all the
way to money in the bank — payment links, reminder cadences whose tone escalates with lateness,
explainable late-payment prediction — and puts a second pair of eyes on every risky outgoing payment
(bank-detail change verification, four-eyes approval, Telegram alerts). An AI assistant answers questions
about the company's own contracts and policies — in the app and in Telegram, in Russian or English, always
with citations and never inventing policy.

Product scope and roadmap: [`docs/PLAN.md`](docs/PLAN.md). Shared platform rules: [`PLATFORM.md`](PLATFORM.md).
Seams between the parts of the foundation: [`docs/FOUNDATION_CONTRACT.md`](docs/FOUNDATION_CONTRACT.md).

> Русская версия — ниже, в разделе [«Русский»](#русский).

---

## Contents

- [Architecture](#architecture)
- [Prerequisites](#prerequisites)
- [Quick start](#quick-start)
- [Ports](#ports)
- [Everyday commands](#everyday-commands)
- [Environment variables](#environment-variables)
- [Billing and payments](#billing-and-payments)
- [Testing](#testing)
- [Production images](#production-images)
- [Troubleshooting on Windows](#troubleshooting-on-windows)
- [Project structure](#project-structure)
- [Cloning the platform into another product](#cloning-the-platform-into-another-product)

## Architecture

Two services share one Postgres database (each migrates only its own schemas) and talk to each other
over HMAC-signed HTTP (`X-Signature: t=…,v1=…`, per-pair keys). The Node service hosts the queues, the
Telegram bot, webhook ingress **and the TypeScript RAG API** (`/v1/*`); there is no Python service. The web
app never sends e-mail or Telegram messages and never calls an LLM itself: it writes to a transactional
**outbox** that the worker relays to BullMQ queues, and reaches the RAG API at `AI_URL`.

```mermaid
flowchart LR
  user([Browser]) -->|Inertia + Vue| web
  tg([Telegram]) <-->|Bot API / webhook| worker
  stripe([Stripe]) -->|signed webhooks| worker

  subgraph app [Services]
    web["web · Laravel 13 + Inertia/Vue<br/>auth, workspaces, business rules,<br/>billing state, audit, UI"]
    worker["worker · Node 24 + BullMQ<br/>outbox relay, e-mail, Telegram bot,<br/>webhook ingress, retries"]
    rag["RAG API · same Node service<br/>parsing + OCR, embeddings,<br/>hybrid retrieval, LLM providers"]
  end

  subgraph infra [Infrastructure]
    pg[("Postgres 17 + pgvector<br/>public · rag · worker")]
    redis[("Redis 7<br/>queues, cache, sessions")]
    smtp[["SMTP<br/>Mailpit in dev"]]
  end

  web -- "outbox_messages + NOTIFY" --> pg
  pg -- "LISTEN / poll" --> worker
  worker -- "/internal/v1/* (signed)" --> web
  worker -- "in-process (WORKER_ROLES=all) or /v1/* (signed)" --> rag
  web -- "/v1/admin/ai/*, /v1/chat (signed)" --> rag
  rag -- "/internal/v1/documents/* (signed)" --> web
  worker --> redis
  web --> redis
  worker --> smtp
  web --> pg
  rag --> pg
```

Key flows: e-mail and Telegram notifications (web → outbox → worker → SMTP / Bot API → delivery callback),
knowledge ingestion (upload → outbox → worker → RAG ingest → status callback), the assistant (web or Telegram →
RAG `/v1/chat` → answer with citations or a refusal), fake billing (checkout → signed Stripe-shaped event →
worker webhook → web subscription). Everything runs **offline** with fake/mock drivers: no Stripe, Telegram
or LLM keys are needed.

## Prerequisites

| Tool | Version | Notes |
|---|---|---|
| Docker Desktop | 25+ (compose v2) | Postgres + pgvector, Redis 7, Mailpit |
| PHP | 8.4 | extensions: `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `zip`, `gd`, `fileinfo`; `redis` and `intl` recommended |
| Composer | 2.x | |
| Node.js | 24 LTS (npm 11) | |
| Tesseract | 5.x with `rus` + `eng` | OCR of scanned PDFs/images in the RAG API |

`npm run doctor` checks all of the above, the ports and the env files, and prints a fix for every problem.

## Quick start

```bash
npm run setup     # docker infra (wait for healthy) → env files + shared secrets → composer/npm installs
                  # → migrations + demo seed (web, worker) → web assets + worker build → Playwright
npm run dev       # web :8100 + vite :5173 + worker with the RAG API :8101 (one terminal, prefixed logs)
```

Open <http://localhost:8100> and sign in with the seeded demo workspace owner **`demo@payflow.test` / `password`**
(platform admin for the AI settings: **`admin@payflow.test` / `password`**). Mail lands in Mailpit at
<http://localhost:8131>.

`npm run setup` runs `scripts/setup.ps1` on Windows and `scripts/setup.sh` elsewhere (Git Bash works too:
`bash scripts/setup.sh`). It is idempotent — re-run it after pulling: `npm ci` only runs for packages whose
`package.json`/lockfile changed since the last install (so it never pulls `node_modules` from under a running
dev server), migrations only apply what is new, secrets are never rotated. Options: `-- --fresh` (drop all
data and re-seed), `-- --reinstall` (force `npm ci` everywhere), `-- --skip-docker`, `-- --skip-build`,
`-- --skip-e2e`. Stop everything with `npm run stop` (`-- --keep-infra` leaves docker running).

## Ports

All three products of the platform can run side by side (PLATFORM.md §2).

| Service | Port | URL |
|---|---|---|
| web (Laravel) | 8100 | <http://localhost:8100> (health: `/up`) |
| web (internal) | 8102 | second local PHP server that answers worker-to-web calls, so a page waiting on the RAG API never blocks the tools it calls back (`npm run dev` and E2E only) |
| vite dev server | 5173 | used by `npm run dev` only |
| worker (Fastify) | 8101 | `/healthz`, `/readyz`, `/metrics`, Bull Board `/admin/queues`, RAG API `/v1/*` (signed) |
| Postgres (pgvector) | 5441 | db `payflow` (+ `payflow_test`, `payflow_e2e`), user/password `app` / `app` |
| Redis 7 | 6391 | |
| Mailpit SMTP / UI | 1031 / 8131 | <http://localhost:8131> |

The machine's native Postgres (5432) and Redis 3 (6379) are **never** used; `npm run doctor` flags any env
file that points at them.

## Everyday commands

| Command | What it does |
|---|---|
| `npm run setup` | full, idempotent developer setup (see above) |
| `npm run dev` | all dev servers via `concurrently`; `-- --only web,vite` / `npm run dev:worker` for a subset, `-- --scheduler` adds `schedule:work` |
| `npm run stop` | kills whatever listens on the dev ports (and leftover watchers of this checkout), then `docker compose stop` |
| `npm run test` | Pest + Vitest (web and worker, including the RAG suites) + root-script tests (sequentially, all of them always run) |
| `npm run e2e` | Playwright suite (see [Testing](#testing)) |
| `npm run lint` / `npm run lint:fix` | pint, `vp check`, vue-tsc, phpstan · eslint + tsc · e2e + scripts tsc |
| `npm run build` | web assets + worker bundle |
| `npm run doctor` | toolchain, ports, infra and env diagnostics |
| `npm run env:sync` | create missing `.env` files, generate secrets once, keep shared secrets identical |
| `npm run infra:up` / `infra:down` / `infra:logs` | docker compose helpers |

## Environment variables

Each app documents every variable line by line in its own `.env.example`
([`apps/web`](apps/web/.env.example), [`apps/worker`](apps/worker/.env.example)).
`scripts/env-sync.ts` creates the `.env` files from them and owns the secrets:

| Variable | Apps | Managed by env-sync |
|---|---|---|
| `INTERNAL_API_SECRET` | web, worker | generated once, identical in both (development fallback for request signing) |
| `INTERNAL_KEY_WEB_WORKER`, `INTERNAL_KEY_WEB_AI`, `INTERNAL_KEY_WORKER_AI` | web, worker | per-pair signing keys (ADR-0010); `WEB_AI` signs web ↔ RAG API calls |
| `STRIPE_WEBHOOK_SECRET` | web, worker | generated once, identical in both (fake billing signs, worker verifies); use Stripe's `whsec_…` with real Stripe |
| `APP_KEY` | web | generated (`base64:` AES-256 key) |
| `AI_SETTINGS_KEY` | worker | generated key encrypting provider API keys saved in the admin UI (AES-256-GCM; earlier Fernet values stay readable) |
| `QUEUE_DASHBOARD_PASSWORD`, `TELEGRAM_WEBHOOK_SECRET` | worker | generated when declared |
| `PRODUCT_NAME`, `PRODUCT_SLUG` | all | filled from `scripts/project.env` when empty |

Existing valid values are **never rotated**; placeholders such as `…change-me` are replaced. Other important switches:

| Variable | Default | Meaning |
|---|---|---|
| `BILLING_DRIVER` (web) | `fake` | `market`, `stripe` or `yookassa` for real billing, see [Billing and payments](#billing-and-payments) |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_MODE` (worker) | empty, `polling` | without a token the bot runs offline (calls captured, `/dev/telegram/update` works) |
| `DEV_ROUTES` (worker) | `1` in dev | enables `/dev/telegram/*`; ignored in production |
| `WORKER_ROLES` (worker) | `all` | `all` in development/E2E (one process, RAG API on 8101); production runs `jobs,bot,ingress` and a separate `rag` container |
| `AI_URL` (web, worker) | `http://127.0.0.1:8101` | RAG API base URL (`http://rag:8102` in production) |
| `AI_PROVIDER` (worker) | `mock` | `anthropic`, `openai`, `deepseek`, `openrouter`, `nvidia`, `opencode-zen`, `opencode-go`, `ollama`; keys via env or the admin UI |
| `EMBEDDINGS_PROVIDER` (worker) | `hash` | `local` (`@huggingface/transformers` when installed, multilingual 384-d model), `openai-compatible`, `hash` (deterministic, offline, used by tests/CI/e2e) |
| `EMBEDDINGS_CACHE_DIR` (worker) | `~/.cache/transformers-js` | local embedding model cache |
| `RAG_EXTERNAL_DEFAULT` (worker) | `0` | requests without `ai_external_allowed` never reach an external provider |
| `RAG_MIN_CONFIDENCE` (worker) | `0.35` | below it the assistant refuses with "not found in approved documents" |

Production env files live in `infra/env/` (see [Production images](#production-images)).

## Billing and payments

SaaS subscriptions run through a `BillingGateway` per workspace market. Onboarding asks for the country; the
market decides the currency and the gateway: Russia or RUB → YooKassa, everything else → Stripe (USD or EUR).
Prices are integer minor units per plan × interval × currency in `apps/web/config/billing.php`; the pricing page
and the billing page show the visitor's or the workspace's market currency.

| `BILLING_DRIVER` | Behaviour |
|---|---|
| `fake` (default) | offline: hosted checkout at `/dev/fake-checkout`, Stripe-shaped events signed with `STRIPE_WEBHOOK_SECRET` go through the real worker webhook; plan change, cancel and resume work the same way |
| `market` | real gateway chosen per workspace market (Stripe or YooKassa) |
| `stripe` / `yookassa` | force one gateway for every workspace |

**Stripe (test mode) end to end:**

1. Put test keys into `apps/web/.env` (`STRIPE_KEY=pk_test_…`, `STRIPE_SECRET=sk_test_…`, `BILLING_DRIVER=market`)
   and `apps/worker/.env` (`STRIPE_SECRET_KEY=sk_test_…`).
2. Create the catalog once (idempotent, safe to re-run after price changes):
   `php artisan billing:sync-stripe` (use `--dry-run` to preview). It creates Products `<catalog>_<plan>`,
   Prices with lookup keys `<catalog>_<plan>_<month|year>_<usd|eur>` and the Customer Portal configuration
   (plan switching with proration, cancel at period end, invoices, payment methods, tax IDs). `<catalog>` is
   `STRIPE_CATALOG`, defaulting to `PRODUCT_SLUG`, so several products can share one Stripe account.
3. Forward webhooks to the worker and copy the printed signing secret into **both** `.env` files as
   `STRIPE_WEBHOOK_SECRET`:
   `stripe listen --forward-to http://localhost:8101/webhooks/stripe --events checkout.session.completed,customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,customer.subscription.trial_will_end,invoice.paid,invoice.payment_failed`
4. Subscribe on `/settings/billing` with the card `4242 4242 4242 4242`; try `4000 0000 0000 0341` (attaches,
   then fails on renewal) with `stripe trigger invoice.payment_failed` to see the past-due banner and e-mail.

Options: `BILLING_TRIAL_DAYS` (default 14, first subscription only), `STRIPE_AUTOMATIC_TAX=true` (Stripe Tax),
`STRIPE_TAX_ID_COLLECTION` (default on). No card data touches the application: Checkout and the Portal are hosted
by Stripe.

**YooKassa (RU market):**

1. Put the shop credentials into `apps/web/.env`: `YOOKASSA_SHOP_ID`, `YOOKASSA_SECRET_KEY` (`test_…` for a test shop),
   `BILLING_DRIVER=market`; `YOOKASSA_RECEIPTS=true` adds a 54-ФЗ receipt to every payment (`YOOKASSA_VAT_CODE`,
   `YOOKASSA_TAX_SYSTEM_CODE`). Without both credentials the RU market is shown as "payments coming soon" and RUB is
   not offered on the pricing page.
2. In the YooKassa dashboard point HTTP notifications (`payment.succeeded`, `payment.canceled`) at
   `https://<worker>/webhooks/yookassa`. The worker accepts them only from `YOOKASSA_ALLOWED_IPS` (YooKassa's published
   ranges by default; set `WEBHOOK_TRUSTED_PROXY_HOPS` when a reverse proxy sits in front of the worker) and web
   re-fetches every payment from the API before trusting it.
3. The first payment saves the card; the worker's `BILLING_RENEWALS_CRON` (hourly) asks web to charge subscriptions whose
   period has ended. A failed charge moves the workspace to past due with one e-mail, is retried every
   `YOOKASSA_RETRY_HOURS` (24) and cancels the subscription after `YOOKASSA_GRACE_DAYS` (7). Plan changes start with the
   next period.

**Client payments (Payment Links):** `App\Billing\Payments\PaymentLinks` creates a hosted payment link for a domain
reference (for example an invoice) through the workspace's own connected account: Stripe (restricted key, a webhook
endpoint with its own signing secret is registered at `PAYMENTS_WEBHOOK_BASE_URL/webhooks/payments/stripe/<account>`) or
YooKassa (merchant shop credentials). Credentials are stored encrypted. `PAYMENTS_RECONCILE_CRON` (every 30 minutes)
re-checks open links; status changes raise `PaymentLinkStatusChanged`.

**Tests:** `docker compose up -d stripe-mock` starts `stripe/stripe-mock` on port 12111; the web suite then
runs the real `StripeGateway` and `billing:sync-stripe` through the official SDK against it (the tests skip
with a reason when it is not running; override the address with `STRIPE_MOCK_URL`, the app with
`STRIPE_API_BASE`). Signed webhook fixtures for every handled event live in `contracts/stripe/events/` and are
used by both the worker (signature + ingress) and web (state machine) suites. With
`E2E_STRIPE_LIVE=1 STRIPE_SECRET_KEY=sk_test_…` the e2e run consists of the `stripe-live` Playwright project
only, which drives the real hosted Checkout in test mode.

## Testing

| Layer | Command | Notes |
|---|---|---|
| web | `npm run test:web` (`php artisan test` + `vp test run`) | Pest on Postgres `payflow_test`; Vitest for the Vue/TS units |
| worker | `npm run test:worker` (`vitest run`) | queues, bot and the RAG API (hash embeddings + mock LLM); real Postgres/Redis tests skip with a reason when infra is down, OCR tests when Tesseract is missing |
| root scripts | `npm run test:scripts` | `node --test` (env-sync planning, job tables, port parsing…) |
| e2e | `npm run e2e` | Playwright + axe, see below |

### End-to-end suite (`e2e/`)

- **Projects:** `chromium-{en,ru}-{light,dark}` plus `chromium-mobile-ru-dark`. Journeys tagged `@flow`
  (register → onboarding → dashboard, invite → accept, knowledge upload → indexed → assistant answer with a
  citation, Telegram linking through the worker dev route, fake checkout → active subscription, AI admin
  key + connection test, audit log) run in `en-light` and `ru-dark`; `@visual` specs (landing, preferences,
  accessibility) run in all four; `@responsive` ones also at phone width.
- **Network guard:** every test fails if the browser requests anything outside localhost — the no-CDN rule
  is enforced, not just documented.
- **Accessibility:** axe-core WCAG 2.1 AA scan of the key pages; `serious`/`critical` violations fail.
- **Screenshots:** `e2e/artifacts/<project>/<name>.png` (+ HTML report in `e2e/playwright-report`, service
  logs in `e2e/artifacts/logs`).
- **Modes** (`E2E_MODE`): `managed` (default in CI) recreates `payflow_e2e`, migrates + seeds it and starts
  web and the worker (with the RAG API in-process) itself with offline drivers; `reuse` runs against your
  running `npm run dev`; `auto` (local default) reuses when both services are healthy, otherwise manages. Managed mode needs the dev ports
  free (`npm run stop -- --keep-infra`).
- One project or spec: `npm run e2e -- --project=chromium-en-light specs/billing.spec.ts`. Harness self-test (no app
  needed): `npm --prefix e2e run selftest`. UI mode: `npm run e2e:ui`. Managed mode reads the ports from
  `scripts/project.env` and the origin from `APP_URL`, so a copy of the repository with other ports runs its own
  isolated stack next to a running `npm run dev`.
- Specs address the UI through `data-test` hooks and form field names only, so they run unchanged in both
  languages.

## Production images

Multi-stage, non-root Dockerfiles in [`infra/docker/`](infra/docker): `web` (FrankenPHP + PHP 8.4 with
`intl`, `pdo_pgsql`, `redis`, OPcache; built Vite assets; port 8080) and `worker` (Node 24, compiled `dist/`,
Tesseract with `rus`+`eng`). The worker image runs twice: `worker` (`WORKER_ROLES=jobs,bot,ingress`, port 8101)
and `rag` (`WORKER_ROLES=rag`, port 8102, internal network only).

```bash
npm run env:sync -- --prod   # infra/env/.env.{web,worker,compose}: production settings + fresh secrets
npm run prod:up              # build + start: postgres, redis, migrations, web, scheduler, rag, worker
```

`docker-compose.prod.yml` defines the topology (hosts, credentials, internal URLs) and injects it into the
containers; the env files carry app settings and secrets. Web is published on `127.0.0.1:8080`, the worker
(webhooks, Bull Board) on `127.0.0.1:8081` — put a TLS reverse proxy in front (`/webhooks/*` → worker,
everything else → web). `--profile sandbox` adds Mailpit as SMTP sink.

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)): `platform` (script tests, e2e harness, compose
validation, shellcheck), `web` (pint, vp check, vue-tsc, phpstan, Pest with pgvector + Redis services, Vitest),
`worker` (eslint, tsc, Vitest incl. the RAG suites with Tesseract, build), `e2e` (Playwright, managed
mode, artifacts uploaded), `images` (builds the images and checks they run as non-root). Every
third-party action is pinned to a full commit SHA (the tag is in the trailing comment; Dependabot bumps both).

## Troubleshooting on Windows

- **`vp build` / `vp dev` fails with "Cannot find native binding" (vite-plus).** `vite-plus` ships its
  native code as per-platform optional packages. `apps/web/package.json` lists the Windows and Linux ones
  (`@voidzero-dev/vite-plus-{win32-x64-msvc,linux-x64-gnu}`) in `optionalDependencies`, so `npm ci` installs
  them. If an older lockfile still misses it, `npm run setup` installs the matching version with
  `npm install --no-save @voidzero-dev/vite-plus-win32-x64-msvc@<vite-plus version>` in `apps/web`, and
  `npm run doctor` reports it. Optional packages limited to other platforms (`"os": ["darwin", "linux"]`)
  must not be added to `apps/web`: npm on Windows leaves their dependencies out of the lockfile, and then
  `npm ci` fails on Linux (CI, the web image) with "Missing: … from lock file".
- **Docker Desktop will not start or lost its data disk.** All containers, images and volumes live in
  `%LOCALAPPDATA%\Docker\wsl\disk\docker_data.vhdx`. If the engine is stuck at "starting" or reports a
  corrupt/full disk: quit Docker Desktop, run `wsl --shutdown`, rename the file to
  `docker_data.vhdx.bak-<date>` (keep it until everything works again) and start Docker Desktop: it creates
  a new, empty disk. Then run `npm run setup`: compose pulls the images and starts with empty volumes, the
  Postgres init script recreates `<db>`, `<db>_test`, `<db>_e2e`, the extensions and schemas, and the
  migrations + seeders rebuild the demo data. Local dev data is disposable by design; only uploaded
  knowledge-base files in `apps/web/storage` live outside Docker. To free space instead, move the disk via
  *Settings → Resources → Advanced → Disk image location* and `docker system prune` old images.
  If pulls time out right after Docker starts, wait for the engine to become ready and re-run
  `npm run setup` (every step is idempotent; compose pulls are retried 3×).
- **Documents stay "processing" / the first upload is slow (Hugging Face download).**
  With `EMBEDDINGS_PROVIDER=local` the RAG API downloads its multilingual embedding model on first use
  (cached in `EMBEDDINGS_CACHE_DIR`, default `~/.cache/transformers-js`). Chat falls back to `hash`
  embeddings after `EMBEDDINGS_LOAD_WAIT_MS`, but ingestion waits for the real model, so on a slow link uploads
  look stuck. The dev `apps/worker/.env.example` therefore ships `EMBEDDINGS_PROVIDER=hash` (deterministic,
  offline, no download — the same provider tests, CI and e2e use). Switch to `local` once the model cache is
  filled (for example copy the cache directory from another machine). Every chunk records its `embedding_model`, so existing documents must be re-embedded:
  saving a new embeddings provider on the AI admin page queues `workspace.reembed` for every workspace.
- **Russian OCR (Tesseract `rus`).** A default Tesseract install only has `eng` + `osd`; scanned Russian
  documents are then OCR'd with `eng` and get a warning. Do not write into `C:\Program Files`. Put
  `rus.traineddata` from the `tesseract-ocr/tessdata_fast` repository into a user-writable folder together
  with copies of the installed `eng.traineddata` and `osd.traineddata`, and point Tesseract at it:
  ```powershell
  $td = "$env:LOCALAPPDATA\tessdata"; New-Item -ItemType Directory -Force $td | Out-Null
  Copy-Item "C:\Program Files\Tesseract-OCR\tessdata\eng.traineddata", "C:\Program Files\Tesseract-OCR\tessdata\osd.traineddata" $td
  Invoke-WebRequest https://github.com/tesseract-ocr/tessdata_fast/raw/main/rus.traineddata -OutFile "$td\rus.traineddata"
  [Environment]::SetEnvironmentVariable('TESSDATA_PREFIX', $td, 'User')   # new terminals; then npm run dev
  tesseract --list-langs                                                   # eng, osd, rus
  ```
  The worker process inherits `TESSDATA_PREFIX` from the shell that runs `npm run dev`. The Docker image
  installs `tesseract-ocr-rus` and `tesseract-ocr-eng` itself.
- **Unstyled pages / CSP errors for `http://[::1]:5173` in `npm run dev`.** Node resolves `localhost` to
  the IPv6 loopback, and an IPv6 origin in `public/hot` is not a valid CSP source, so the browser blocks the
  dev stylesheets. The root `npm run dev` starts Vite with `--host 127.0.0.1`; when you run Vite yourself in
  `apps/web`, use `npm run dev -- --host 127.0.0.1`. A stale `apps/web/public/hot` (left behind by a killed
  Vite) makes Laravel point at a dead dev server: delete it or run `npm run stop`.
- **A port is already in use.** `npm run doctor` shows which process owns 8100/5173/8101;
  `npm run stop` frees them. Never stop the native Postgres/Redis services — the project does not use them.
- **`localhost` vs `127.0.0.1`.** Keep `APP_URL=http://localhost:8100`; signed links in e-mails
  (verification, invitations) are only valid on that exact origin.
- **PowerShell blocks scripts.** `npm run setup` already uses `-ExecutionPolicy Bypass` for its own script;
  run it from any shell.
- **Line endings.** `.gitattributes` keeps shell scripts and container files LF; if a container fails with
  `/bin/sh^M: bad interpreter`, re-checkout the file (`git checkout -- <file>`) after `git config core.autocrlf false`.
- **Slow `php artisan serve`.** PHP's built-in server is single-threaded on Windows; the e2e suite therefore
  uses one worker. Use the production image (`npm run prod:up`) for realistic performance tests.

## Project structure

```
.
├─ apps/
│  ├─ web/            Laravel 13 + Inertia + Vue 3 + TS + Tailwind 4 (UI, business rules, billing, audit)
│  └─ worker/         Node 24 + BullMQ + grammY + Fastify (outbox relay, mail, Telegram, webhooks)
│                     + src/rag: TypeScript RAG API (parsing, OCR, pgvector + FTS retrieval, LLM providers)
├─ e2e/               Playwright + axe: specs/, fixtures.ts, support/, selftest/, global-setup.ts
├─ infra/
│  ├─ docker/         web / worker Dockerfiles (+ per-Dockerfile .dockerignore), FrankenPHP config
│  ├─ env/            production env templates (generated files are gitignored)
│  └─ postgres/init/  creates <db>_test, <db>_e2e, extensions and the rag/worker schemas
├─ scripts/           setup.sh / setup.ps1, tasks.ts (dev/test/lint/stop/doctor), env-sync.ts, project.env
├─ docs/              PLAN.md, FOUNDATION_CONTRACT.md, ARCHITECTURE.md, adr/
├─ design-system/     brand + UI rules (MASTER.md, per-page overrides)
├─ docker-compose.yml        local infra (Postgres + pgvector, Redis 7, Mailpit)
├─ docker-compose.prod.yml   full stack
└─ .github/workflows/ci.yml
```

## Cloning the platform into another product

Everything product-specific lives in configuration: edit `scripts/project.env` (name, slug, compose project,
database, ports), the `${VAR:-default}` defaults in `docker-compose.yml`, the service-container ports in
`.github/workflows/ci.yml`, and the per-app product settings (`apps/web/.env.example` + `config/product.php`,
`PRODUCT_*` and `src/rag/prompts/domain.*.md` in the worker, brand tokens and logo in web).
The scripts, e2e suite and Dockerfiles are product-neutral.

---

## Русский

**Доводим каждый счёт до оплаты и не даём отправить деньги мошеннику.** PayFlow Guard — слой контроля
денежного потока для небольших сервисных компаний (агентства, студии разработки, B2B-подрядчики на 5–50
человек): ведёт счёт до поступления денег (ссылки на оплату, напоминания, тон которых растёт вместе с
просрочкой, объяснимый прогноз задержки оплаты) и ставит «вторые глаза» на каждый рискованный исходящий
платёж (проверка смены реквизитов, согласование в четыре глаза, уведомления в Telegram). AI-ассистент
отвечает на вопросы по договорам и политикам компании — в приложении и в Telegram, на русском или
английском, всегда со ссылками на источники и никогда не выдумывая правила.

Продукт и план: [`docs/PLAN.md`](docs/PLAN.md). Общие правила платформы: [`PLATFORM.md`](PLATFORM.md).

### Архитектура

Два сервиса работают с одной базой Postgres (каждый мигрирует только свои схемы: `public` — web, `worker` и
`rag` — Node-сервис) и общаются по HTTP с HMAC-подписью (ключи на каждую пару сервисов). Node-сервис содержит
очереди, Telegram-бота, приём вебхуков и **RAG API на TypeScript** (`/v1/*`); сервиса на Python нет. Web никогда сам не
отправляет почту и сообщения в Telegram и не вызывает LLM: он пишет в транзакционный **outbox**, который
worker переносит в очереди BullMQ. Диаграмма — в английском разделе [Architecture](#architecture).
Без ключей Stripe, Telegram и LLM всё работает офлайн на фейковых/mock-драйверах.

### Требования

Docker Desktop (compose v2), PHP 8.4 (`pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `zip`, `gd`,
`fileinfo`; желательно `redis` и `intl`), Composer 2, Node.js 24 (npm 11), Tesseract 5 с языками `rus` и `eng`.
`npm run doctor` проверяет всё это, порты и env-файлы и подсказывает исправление.

### Быстрый старт

```bash
npm run setup     # docker-инфраструктура → .env и общие секреты → composer/npm → миграции и демо-данные
                  # → сборка ассетов и worker → Playwright
npm run dev       # web :8100 + vite :5173 + worker с RAG API :8101 в одном терминале
```

Откройте <http://localhost:8100>, вход демо-владельца: **`demo@payflow.test` / `password`**, администратор
платформы (настройки AI): **`admin@payflow.test` / `password`**. Письма — в Mailpit: <http://localhost:8131>.

На Windows `npm run setup` запускает `scripts/setup.ps1`, на остальных системах — `scripts/setup.sh` (в Git
Bash тоже работает: `bash scripts/setup.sh`). Скрипт идемпотентен, его можно перезапускать после каждого
обновления: `npm ci` выполняется только для пакетов, у которых изменились `package.json`/lock-файл (поэтому
повторный запуск не удаляет `node_modules` из-под работающего dev-сервера), миграции применяют только новое,
секреты не перегенерируются. Опции: `-- --fresh` (удалить все данные и заново засеять), `-- --reinstall`
(принудительный `npm ci`), `-- --skip-docker`, `-- --skip-build`, `-- --skip-e2e`. Остановить всё:
`npm run stop` (`-- --keep-infra` оставляет docker запущенным).

### Порты

| Сервис | Порт |
|---|---|
| web (Laravel) | 8100 (`/up`) |
| web (внутренний) | 8102 — второй локальный PHP-сервер для запросов worker → web, чтобы страница, ожидающая ответа RAG API, не блокировала вызовы его инструментов (только `npm run dev` и E2E) |
| vite | 5173 |
| worker | 8101 (`/healthz`, `/readyz`, `/metrics`, Bull Board `/admin/queues`, RAG API `/v1/*` с подписью) |
| Postgres (pgvector) | 5441 — БД `payflow`, `payflow_test`, `payflow_e2e`; `app` / `app` |
| Redis 7 | 6391 |
| Mailpit SMTP / UI | 1031 / 8131 |

Нативные Postgres (5432) и Redis 3 (6379) на машине **не используются**; `npm run doctor` предупредит, если
какой-то `.env` на них смотрит.

### Команды

`npm run setup` · `npm run dev` (`-- --only web,vite`, `-- --scheduler`) · `npm run stop` ·
`npm run test` (Pest + Vitest для web и worker, включая тесты RAG, + тесты скриптов) · `npm run e2e` · `npm run lint` / `lint:fix` ·
`npm run build` · `npm run doctor` · `npm run env:sync` · `npm run infra:up | infra:down | infra:logs`.

### Переменные окружения

Каждое приложение документирует свои переменные построчно в `.env.example`. `scripts/env-sync.ts` создаёт из
них `.env` и управляет секретами: `INTERNAL_API_SECRET` и ключи пар `INTERNAL_KEY_*` — одинаковые в web и worker;
`STRIPE_WEBHOOK_SECRET` — один и тот же в web и worker; `APP_KEY` (web), `AI_SETTINGS_KEY` (ключ шифрования
API-ключей провайдеров в worker), `QUEUE_DASHBOARD_PASSWORD` и `TELEGRAM_WEBHOOK_SECRET` (worker)
генерируются один раз. Уже заданные корректные значения **никогда не перегенерируются**, заглушки вида
`…change-me` заменяются. Основные переключатели: `BILLING_DRIVER=fake|market|stripe|yookassa`, `TELEGRAM_BOT_TOKEN` (без
токена бот работает офлайн), `AI_PROVIDER=mock|anthropic|openai|…`, `EMBEDDINGS_PROVIDER=local|openai-compatible|hash`,
`RAG_MIN_CONFIDENCE` (ниже порога ассистент честно отказывается отвечать), `WORKER_ROLES` (`all` в разработке;
в продакшене отдельные контейнеры `jobs,bot,ingress` и `rag`), `AI_URL` (адрес RAG API, по умолчанию `http://127.0.0.1:8101`).

### Оплата и подписки

Подписки оформляются через `BillingGateway` рынка рабочего пространства. При онбординге выбирается страна:
Россия или RUB → ЮKassa, остальные → Stripe (USD или EUR). Цены — целые минорные единицы на тариф × период ×
валюту в `apps/web/config/billing.php`; страница тарифов и страница оплаты показывают валюту рынка.
`BILLING_DRIVER`: `fake` (по умолчанию, офлайн, события в формате Stripe с подписью идут через настоящий вебхук
worker), `market` (шлюз по рынку), `stripe` / `yookassa` (один шлюз для всех).

Stripe в тестовом режиме: ключи `sk_test_…` в `.env` web и worker, `php artisan billing:sync-stripe` (продукты,
цены по `lookup_key`, настройка Customer Portal; `--dry-run` для предпросмотра), затем
`stripe listen --forward-to http://localhost:8101/webhooks/stripe` и общий `STRIPE_WEBHOOK_SECRET` в обоих `.env`.
Тесты: `docker compose up -d stripe-mock` (порт 12111) — интеграционные тесты `StripeGateway` и синхронизации через
официальный SDK; подписанные фикстуры вебхуков — `contracts/stripe/events/`; Playwright-проект `stripe-live`
запускается только с `E2E_STRIPE_LIVE=1` и ключом `sk_test_…`.

### Тестирование

`npm run test:web` (Pest на `payflow_test` + Vitest фронтенда), `npm run test:worker` (Vitest: очереди, бот и RAG API с hash-эмбеддингами
и mock-LLM), `npm run test:scripts`, `npm run e2e` (Playwright + axe).

E2E: проекты `chromium-{en,ru}-{light,dark}` и мобильный `chromium-mobile-ru-dark`. Сценарии `@flow`
(регистрация → онбординг → дашборд, приглашение → принятие, загрузка документа → индексация → ответ
ассистента со ссылкой, привязка Telegram через dev-маршрут worker, фейковая оплата → активная подписка,
админка AI: ключ показывается как `…last4`, проверка соединения, журнал аудита) идут в `en-light` и `ru-dark`;
визуальные `@visual` (лендинг, язык и тема, доступность) — во всех четырёх. **Сетевой страж** валит тест при
любом запросе браузера за пределы localhost (правило «никаких CDN» проверяется автоматически). axe-core
проверяет ключевые страницы по WCAG 2.1 AA (ошибки `serious`/`critical` — провал). Скриншоты:
`e2e/artifacts/<проект>/<имя>.png`. Режимы `E2E_MODE`: `managed` — пересоздаёт `payflow_e2e`, мигрирует,
засевает и сам поднимает сервисы; `reuse` — работает с уже запущенным `npm run dev`; `auto` (по умолчанию
локально) — выбирает сам. Самопроверка обвязки: `npm --prefix e2e run selftest`.

### Продакшен

Многостадийные Dockerfile без root в `infra/docker/` (web на FrankenPHP с `intl`, worker на Node 24 с Tesseract
`rus`+`eng`; образ worker запускается дважды: `worker` с ролями `jobs,bot,ingress` и `rag` на порту 8102). Запуск: `npm run env:sync -- --prod` (создаёт `infra/env/.env.*` с новыми
секретами), затем `npm run prod:up`. Топологию (хосты, пароли, внутренние URL) задаёт
`docker-compose.prod.yml`, env-файлы содержат настройки приложений и секреты. CI: `.github/workflows/ci.yml`
(platform, web, worker, e2e, images).

### Проблемы на Windows

- **vite-plus: «Cannot find native binding».** Нативный код `vite-plus` поставляется отдельными
  платформенными пакетами. В `apps/web/package.json` пакеты для Windows и Linux
  (`@voidzero-dev/vite-plus-{win32-x64-msvc,linux-x64-gnu}`) перечислены в `optionalDependencies`, поэтому
  `npm ci` ставит их сам. Если старый lock-файл его не содержит, `npm run setup` доустановит нужную версию
  (`npm install --no-save …` в `apps/web`), а `npm run doctor` сообщит о проблеме. Не добавляйте в `apps/web`
  опциональные пакеты только для других ОС (`"os": ["darwin", "linux"]`): npm на Windows не записывает их
  зависимости в lock-файл, и `npm ci` в Linux (CI, образ web) падает с «Missing: … from lock file».
- **Docker Desktop не запускается или потерял диск данных.** Все контейнеры, образы и тома лежат в
  `%LOCALAPPDATA%\Docker\wsl\disk\docker_data.vhdx`. Если движок завис на «starting» или пишет, что диск
  повреждён/переполнен: закройте Docker Desktop, выполните `wsl --shutdown`, переименуйте файл в
  `docker_data.vhdx.bak-<дата>` (храните копию, пока всё снова не заработает) и запустите Docker Desktop —
  он создаст новый пустой диск. Затем `npm run setup`: compose скачает образы и поднимет пустые тома,
  init-скрипт Postgres заново создаст `<db>`, `<db>_test`, `<db>_e2e`, расширения и схемы, миграции и
  сидеры восстановят демо-данные. Локальные данные разработки одноразовые по задумке; вне Docker живут только
  загруженные файлы базы знаний в `apps/web/storage`. Чтобы просто освободить место, перенесите диск в
  *Settings → Resources → Advanced → Disk image location* и удалите старые образы (`docker system prune`).
  Если сразу после старта Docker образы не скачиваются, дождитесь готовности движка и перезапустите
  `npm run setup` (шаги идемпотентны, `compose up` повторяется до 3 раз).
- **Документы долго в статусе «processing» / первая загрузка «висит» (скачивание с Hugging Face).** При
  `EMBEDDINGS_PROVIDER=local` RAG API при первом использовании скачивает мультиязычную модель эмбеддингов
  (кэш `EMBEDDINGS_CACHE_DIR`, по умолчанию `~/.cache/transformers-js`). Чат через `EMBEDDINGS_LOAD_WAIT_MS`
  переключается на `hash`, а индексация ждёт настоящую модель, поэтому на медленном канале загрузка документов
  выглядит зависшей. Dev-шаблон `apps/worker/.env.example` уже содержит `EMBEDDINGS_PROVIDER=hash`
  (детерминированно, офлайн, без скачивания — тот же провайдер, что в тестах, CI и e2e).
  Переключиться на `local` можно, когда кэш модели заполнен
  (например, скопирован с другой машины). Каждый чанк хранит свою `embedding_model`, поэтому документы нужно
  переиндексировать: сохранение нового провайдера эмбеддингов на странице администрирования AI ставит
  `workspace.reembed` для всех рабочих пространств.
- **OCR на русском (Tesseract `rus`).** Стандартная установка Tesseract содержит только `eng` и `osd`;
  русские сканы тогда распознаются как `eng` с предупреждением. Не пишите в `C:\Program Files`: положите
  `rus.traineddata` из репозитория `tesseract-ocr/tessdata_fast` в папку пользователя вместе с копиями
  установленных `eng.traineddata` и `osd.traineddata` и задайте `TESSDATA_PREFIX` (команды PowerShell —
  в английском разделе [Troubleshooting on Windows](#troubleshooting-on-windows)). Процесс worker наследует
  переменную от терминала, в котором запущен `npm run dev`. Docker-образ ставит `tesseract-ocr-rus` и
  `tesseract-ocr-eng` сам.
- **Страницы без стилей / ошибки CSP для `http://[::1]:5173` в `npm run dev`.** Node разрешает `localhost`
  в IPv6-адрес, а IPv6-origin в `public/hot` не является допустимым источником CSP, поэтому браузер блокирует
  стили dev-сервера. Корневой `npm run dev` запускает Vite с `--host 127.0.0.1`; если запускаете Vite сами в
  `apps/web`, используйте `npm run dev -- --host 127.0.0.1`. Оставшийся после аварийной остановки
  `apps/web/public/hot` направляет Laravel на мёртвый dev-сервер: удалите его или выполните `npm run stop`.
- **Порт занят.** `npm run doctor` покажет процесс, `npm run stop` освободит порты 8100/5173/8101.
- **`localhost` и `127.0.0.1`.** Оставляйте `APP_URL=http://localhost:8100`: подписанные ссылки в письмах
  действительны только для этого origin.
- **Окончания строк.** `.gitattributes` держит shell-скрипты в LF; ошибка `/bin/sh^M` лечится повторным
  checkout файла при `core.autocrlf=false`.

### Структура проекта

См. английский раздел [Project structure](#project-structure): `apps/web`, `apps/worker`, `e2e/`,
`infra/`, `scripts/`, `docs/`, `design-system/`, `docker-compose*.yml`, `.github/workflows/ci.yml`.
Чтобы клонировать платформу в другой продукт, достаточно поменять `scripts/project.env`, значения по умолчанию
в `docker-compose.yml`, порты сервисов в CI и продуктовые настройки приложений (раздел
[Cloning the platform](#cloning-the-platform-into-another-product)).
