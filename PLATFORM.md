# Platform Blueprint — shared foundation for PayFlow Guard, OpsBot, ScamPause Business

This file is the **single source of truth** for everything the three products share:
repo layout, service boundaries, inter-service contracts, i18n, theming, security,
testing and delivery. Each project's `docs/PLAN.md` describes only its *domain* on top
of this blueprint. When a project doc contradicts this file, this file wins unless the
project doc explicitly says "overrides PLATFORM.md §X" with a reason.

---

## 0. Hard rules (non-negotiable)

1. **No CDN, ever.** No `<script src="https://…">`, no Google Fonts / Bunny Fonts links,
   no `@import url(http…)`, no hot-linked images, no Gravatar. Everything is installed
   as a dependency (npm / composer / uv) or embedded in the repo (inline SVG, local assets).
   Fonts come from `@fontsource-variable/*` / `@fontsource/*` packages. A strict CSP
   (`default-src 'self'`) enforces this in production.
2. **Two locales everywhere: Russian (`ru`) and English (`en`).** UI, emails, Telegram bot
   messages, validation errors, AI answers (answer in the user's locale), PDFs, landing page.
   No hard-coded user-facing strings in components — always translation keys.
   Russian plural forms must be correct (1 счёт / 2 счёта / 5 счетов).
3. **Light + dark theme everywhere** (plus "system"), switchable in the header and on
   the landing page, persisted (cookie + localStorage, no flash on load). Both themes are
   designed, not inverted: every surface, chart, badge and illustration is checked in both.
4. **No "plain" styling.** Each product has its own brand identity (see §9). Landing pages
   are rich (motion, bento grids, illustrated product mockups built from real components,
   gradients/textures), app screens are polished data-dense SaaS UI. Use shadcn-vue
   components as the base and extend them; component patterns (shadcn-vue, Inspira UI,
   reka-ui examples) live in the repo, never as remote dependencies.
5. **Accessibility & UX baseline**: 4.5:1 contrast in both themes,
   visible focus rings, keyboard navigation, labels on inputs, `aria-label` on icon buttons,
   44px touch targets, `prefers-reduced-motion` respected, no emoji as icons (Lucide only),
   cursor-pointer on clickables, responsive at 375 / 768 / 1024 / 1440, no horizontal scroll.
6. **Works out of the box without any third-party keys.** Stripe, Telegram and LLM keys are
   optional: fake/mock drivers make every flow (including E2E) runnable offline. Real
   drivers activate when keys are present in `.env` or saved in the admin UI.
7. **Production posture:** typed code (PHP 8.4 strict types, TS strict, Python typed),
   tests for every feature, structured logs, health checks, idempotent jobs and webhooks,
   secrets never logged, sensitive columns encrypted, audit trail for security-relevant actions.
8. **Icons only from icon packs — no emoji, no stickers.** UI icons: Lucide (`@lucide/vue` /
   `lucide-vue-next`, already in the kit). Third-party brand marks (Telegram, Stripe, Visa…):
   the `simple-icons` npm package (local SVG paths). Forbidden everywhere — web UI, emails,
   PDFs, Telegram bot messages and keyboards, seed data, notifications, landing copy: emoji,
   Telegram stickers/GIFs/animated emoji, emoji used as bullets or status markers, clip-art /
   3D "sticker" illustrations, stock photos. Status is conveyed by text + color + Lucide icon.
   In Telegram (where icons can't be embedded) use plain HTML formatting (`<b>`, `<i>`,
   `<code>`, `<a>`), words and simple typographic marks (`•`, `—`, `№`) only. Illustrations are
   inline SVG built from geometric shapes, product UI fragments and Lucide glyphs.
9. **Privacy & legal are part of the product** (see §10): legal documents in RU+EN, consent
   capture, data export/deletion, retention, cookie honesty, AI transparency.
10. **Each product uses exactly the stack defined for it** (decision 2026-10-22):

| Product | Laravel + Vue SaaS | Telegram bot with RAG | FastAPI + PostgreSQL + pgvector | Node.js + BullMQ/Redis | Playwright |
|---|---|---|---|---|---|
| PayFlow Guard | `apps/web` | in the Node service (TypeScript RAG) | — **no Python service** | `apps/worker` (also hosts the bot + RAG) | `e2e` |
| OpsBot | `apps/web` | bot in `apps/worker`, RAG answers from `apps/ai` | `apps/ai` | `apps/worker` | `e2e` |
| ScamPause Business | `apps/web` | in the Node service (TypeScript RAG) | — **no Python service** | `apps/worker` (also hosts the bot + RAG) | `e2e` |

   The RAG HTTP contract of §4.4 (`/v1/chat`, `/v1/documents/*`, `/v1/admin/ai/*`, …) is identical in both
   implementations, so the Laravel side (`AiClient`, `AI_URL`) does not care which one serves it: in OpsBot
   `AI_URL` points to FastAPI (`:8202`), in PayFlow/ScamPause to the Node service (`:8101`, routes under `/v1`).
   The Node RAG ports the TBO TypeScript provider manager almost verbatim and implements §4.5–4.6 in TypeScript (pgvector via `pg`, hybrid FTS + vector, same confidence
   gate, same mock provider). Every product still has its **own UI** (own brand, layout language, landing and
   domain screens — §9), not a recolor of a shared template.
11. **Clean code and repository hygiene.**
   - **No comments in source code** (PHP, TS/JS, Vue, Python, CSS, SQL, shell, Dockerfiles, YAML/JSON configs we
     write): code explains itself through naming, small functions and types. No commented-out code, no TODO/FIXME,
     no section banners, no docblocks with prose. The only allowed exceptions are machine-read type annotations a
     tool needs and native types cannot express (e.g. a bare PHPStan generic `/** @var array<string, int> */`,
     `/** @use HasFactory<UserFactory> */`) — no words in them. Framework skeleton files are stripped of comments too.
   - **README.md**: clean, professional, product-focused (what it is, features, stack, architecture diagram,
     quick start, configuration, testing, deployment, license). No process notes.
   - Every app has a precise `.gitignore` (dependencies, env files, build output, caches, test artifacts, IDE files).

---

## 1. Repository layout (identical in every project)

```
<project>/
├─ README.md                 # bilingual quick start (EN first, RU section)
├─ PLATFORM.md               # copy of this file
├─ docs/
│  ├─ PLAN.md                # product + roadmap + domain contracts (project specific)
│  ├─ ARCHITECTURE.md        # diagrams (mermaid), data flow, ADR index
│  └─ adr/NNNN-*.md          # architecture decision records
├─ design-system/<slug>/MASTER.md   # brand + UI rules
├─ apps/
│  ├─ web/                   # Laravel 13 + Inertia 3 + Vue 3 + TS + Tailwind 4 + shadcn-vue
│  ├─ worker/                # Node 24 + TS + BullMQ + grammY + Fastify
│  └─ ai/                    # FastAPI + SQLAlchemy 2 + psycopg 3 + pgvector + Alembic (uv, Python 3.12)
├─ e2e/                      # Playwright (TS) + @axe-core/playwright
├─ infra/
│  ├─ docker/                # Dockerfiles: web (FrankenPHP or nginx+php-fpm), worker, ai
│  └─ postgres/init/         # creates <db>_test, extensions vector, pg_trgm, unaccent
├─ docker-compose.yml        # local infra: postgres(pgvector), redis 7, mailpit
├─ docker-compose.prod.yml   # full stack: infra + web + worker + ai (+ scheduler)
├─ package.json              # root scripts (dev / test / e2e / lint) via concurrently
└─ .github/workflows/ci.yml  # lint + unit tests (pest, vitest, pytest) + build + e2e
```

## 2. Ports & names (avoid collisions — all three can run at once)

| Service            | PayFlow Guard | OpsBot  | ScamPause |
|--------------------|---------------|---------|-----------|
| web (Laravel)      | 8100          | 8200    | 8300      |
| vite dev server    | 5173          | 5174    | 5175      |
| worker HTTP        | 8101          | 8201    | 8301      |
| ai (FastAPI)       | 8102          | 8202    | 8302      |
| Postgres (pgvector)| 5441          | 5442    | 5443      |
| Redis 7            | 6391          | 6392    | 6393      |
| Mailpit SMTP / UI  | 1031 / 8131   | 1032 / 8231 | 1033 / 8331 |
| DB name            | `payflow`     | `opsbot`| `scampause` |
| compose project    | `payflow`     | `opsbot`| `scampause` |

Dev DB credentials: user `app`, password `app` (dev only). Test DB: `<db>_test`.
Schemas in one database: `public` (Laravel, owner of business data), `rag` (ai service),
`worker` (worker service: webhook idempotency, bot state). Each service migrates only its schema.
The machine has an old native Redis 3 on 6379 and native Postgres 16 on 5432 — **never use them**;
always the docker ones on the ports above.

## 3. Service responsibilities

| Service | Owns | Never does |
|---|---|---|
| **web** (Laravel) | auth, workspaces/tenancy, roles, all business entities & rules, UI (Inertia/Vue), billing state, audit log, outbox publishing, internal API | send emails/Telegram directly, call LLMs, long-running work in a request |
| **worker** (Node) | BullMQ queues & schedulers, outbox relay, email delivery (nodemailer), Telegram bot (grammY), Stripe/Telegram webhook ingress + idempotency, retries/backoff, Bull Board | own business rules (it calls web's internal API to read/mutate business data) |
| **ai** (FastAPI) | document parsing/OCR/chunking/embeddings, pgvector + FTS hybrid retrieval, LLM provider manager, agent tool loop, answer-with-citations + confidence gate, AI usage accounting | write business tables (it calls web's internal API for domain data via tools) |

## 4. Inter-service contracts

### 4.1 Internal request signing (all service-to-service HTTP)

One key per service pair (ADR-0010): `INTERNAL_KEY_WEB_WORKER`, `INTERNAL_KEY_WEB_AI`, `INTERNAL_KEY_WORKER_AI`
(each held only by its two services; in PayFlow/ScamPause the `ai` party is the Node RAG). The signer picks the key by
its target, the verifier by `X-Service`. Production requires all pair keys (32+ chars, distinct) and refuses to boot
otherwise; outside production an unset pair key falls back to the shared `INTERNAL_API_SECRET`, then to the dev secret.
Web scopes internal routes per caller (`internal.caller:<service,…>` → `403 {error:'caller_not_allowed'}`).
Every internal request carries:

```
X-Service: web | worker | ai
X-Request-Id: <uuid>                       # propagated end to end, logged everywhere
X-Signature: t=<unix_seconds>,v1=<hex>
  hex = HMAC_SHA256(secret, "<t>.<METHOD>.<path_with_query>.<raw_body>")
```

Receiver rejects if `|now - t| > 300s` or signature mismatch (constant-time compare) → `401`.
Implementations: Laravel middleware `VerifyInternalSignature` + `InternalClient` (Http macro);
Node `internalFetch()` helper; Python `httpx` auth class + FastAPI dependency. Unit-tested
with a shared test vector in each language:
`secret="test-secret"`, `t=1700000000`, `POST /internal/v1/ping`, body `{"a":1}` →
all three implementations must produce the same hex (put the computed value in each test).

### 4.2 Transactional outbox (web → worker)

Table `public.outbox_messages`:
`id uuid pk, topic varchar(120), payload jsonb, workspace_id bigint null, available_at timestamptz default now(),
dispatched_at timestamptz null, attempts int default 0, last_error text null, created_at timestamptz`.
Partial index on `(available_at) where dispatched_at is null`.

- Web: `Outbox::publish(string $topic, array $payload, ?int $workspaceId = null, ?CarbonInterface $availableAt = null)`
  inside the business transaction; after commit it runs `NOTIFY outbox`.
- Worker relay: `LISTEN outbox` + poll every 2s as fallback; claims rows with
  `FOR UPDATE SKIP LOCKED LIMIT 100`, enqueues to BullMQ with **`jobId = outbox id`** (idempotent),
  sets `dispatched_at`. Topic → queue routing table lives in `apps/worker/src/queues/routes.ts`.
- Payloads are self-contained (already-rendered, already-localized content where relevant).

Foundation topics:

| topic | payload | queue → handler |
|---|---|---|
| `email.send` | `{to, to_name?, subject, html?, text?, locale, reference:{type,id}, workspace_id, from?:{address,name}, reply_to?:{address,name}, attachments?:[{filename, content_type, content_base64}]}` (one message per recipient; html/text may be null, not both) | `emails` → SMTP, then `POST /internal/v1/deliveries` |
| `telegram.notify` | `{user_id?|chat_id?, text_html, buttons?:[{text, url?|callback?}], reference}` | `telegram` → Bot API, then deliveries callback |
| `document.uploaded` | `{document_id, workspace_id}` | `documents` → ai `POST /v1/documents/{id}/ingest` |
| `document.deleted` | `{document_id, workspace_id}` | `documents` → ai `DELETE /v1/documents/{id}` |
| `workspace.reembed` | `{workspace_id}` | `documents` → ai `POST /v1/workspaces/{id}/reembed` |

Every job: `attempts: 5`, exponential backoff (`delay: 2000`), `removeOnComplete: 1000`,
`removeOnFail: 5000`, structured log on fail. Handlers are idempotent.

### 4.3 Web internal API (`/internal/v1/*`, signed, no session, no CSRF)

| Method & path | Purpose |
|---|---|
| `GET  /internal/v1/ping` | signed health check |
| `POST /internal/v1/telegram/link` | `{token, telegram_user_id, chat_id, username, language_code}` → links account |
| `GET  /internal/v1/telegram/context?telegram_user_id=` or `?user_id=` | `{user:{id,name,locale}, workspace:{id,name,plan}\|null, role, permissions[], telegram:{user_id,chat_id,username}}` or 404 |
| `POST /internal/v1/telegram/actions` | inline-button action the bot has no local handler for → `{ok, message?, message_html?, alert?}` (404 unknown type) |
| `POST /internal/v1/deliveries` | `{reference:{type,id}, channel:'email'|'telegram', status:'sent'|'failed', error?, provider_message_id?}` |
| `POST /internal/v1/stripe/events` | verified Stripe event JSON (idempotent by `event.id`) |
| `GET  /internal/v1/documents/{id}/file` | streams the stored file (ai downloads it) |
| `POST /internal/v1/documents/{id}/status` | `{status:'processing'|'indexed'|'failed', pages?, chunks?, ocr_used?, error?}` |
| `POST /internal/v1/ai-queries` | logs a question/answer: `{workspace_id, user_id?, channel, question, answer, refused, confidence, citations[], tools_used[], latency_ms, provider, model}` |

Projects add domain endpoints under `/internal/v1/<domain>/…` and list them in `docs/PLAN.md`.

### 4.4 AI service API (`/v1/*`, signed)

| Method & path | Purpose |
|---|---|
| `GET /healthz`, `GET /readyz`, `GET /metrics` | unsigned liveness / readiness (503 when Postgres is down) / Prometheus |
| `POST /v1/chat` | `{workspace_id, user:{id, role, locale, permissions[]}, channel:'telegram'|'web', conversation_id?, message}` → `{answer_markdown, answer_html, citations:[{document_id,title,page?,snippet,url?}], confidence:0..1, refused:bool, tools_used:[], actions:[{type,label,payload}], provider, model, latency_ms}` |
| `POST /v1/search` | `{workspace_id, query, k?, filters?}` → ranked chunks (debug/admin) |
| `POST /v1/documents/{id}/ingest` | `{workspace_id, title, mime, metadata:{department?, roles?:[], tags?:[], version?, effective_date?}}` → fetches file from web internal API, parses (PDF/DOCX/TXT/MD/HTML/images), OCR via Tesseract (`rus+eng`) when no text layer, chunks, embeds, stores → `{pages, chunks, ocr_used}` and calls web status endpoint |
| `GET /v1/documents/{id}/chunks?workspace_id=&limit=` | first chunks of a document (knowledge "Show" preview) |
| `DELETE /v1/documents/{id}?workspace_id=` | drop chunks |
| `POST /v1/workspaces/{id}/reembed` | recompute embeddings with the active embedding model |
| `GET /v1/admin/ai/providers` | catalog with `configured, needs_key, key_source:'settings'|'env'|null, key_hint:'…abcd'` |
| `GET /v1/admin/ai/providers/{id}/models` | live model list (cached 10 min) |
| `GET/PUT /v1/admin/ai/settings` | `{provider, model, fallback_model?, temperature?, embeddings:{provider, model}}` |
| `PUT /v1/admin/ai/keys/{provider}` | `{api_key: string|null}` (stored encrypted, Fernet `AI_SETTINGS_KEY`) |
| `POST /v1/admin/ai/test` | one-shot ping to the active provider → `{ok, latency_ms, model, error?}` |
| `GET /v1/admin/ai/usage?days=30` | tokens/cost by day/provider/model/purpose |

### 4.5 LLM provider manager (port of the TBO provider manager to Python)

Same behaviour as the TBO project:
- Catalog: `anthropic` (Claude: `claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5`, `claude-fable-5-1`),
  `openai`, `deepseek`, `openrouter`, `nvidia`, `opencode-zen`, `opencode-go`, `ollama` (keyless, local),
  and **`mock`** (keyless, deterministic, default — extractive answers from retrieved chunks + rule-based
  intent → tool routing, so everything works and tests are stable without keys).
- OpenAI-compatible providers share one client class (base URL per provider, JSON-schema response
  with fallback to `json_object`); Anthropic uses the official SDK (tool use native).
- Keys: stored key (admin UI, encrypted) overrides env key; UI only ever sees `…last4` + source.
- Active provider/model/fallback model in settings; on 404/403 switch to fallback model; on 429 retry
  with backoff (respect retry-after), after 3 attempts switch to fallback; max 6 attempts.
- Every call logged to `rag.ai_usage` (provider, model, purpose, prompt/completion/cached tokens, cost USD
  from a price table, latency, workspace_id).
- Embeddings providers: `local` (fastembed `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`,
  384-d, RU+EN — fastembed does not ship `multilingual-e5-small`; model cache dir
  `FASTEMBED_CACHE_PATH`, default `~/.cache/fastembed` shared by all projects), `openai-compatible`
  (OpenAI / NVIDIA / Ollama), `hash` (deterministic 384-d, used in tests/CI). If `local` fails to load,
  log a warning and fall back to `hash`. Each chunk stores `embedding_model`; re-embed job exists.

### 4.6 RAG quality rules

- Chunking: structure-aware (headings/sections/pages), ~700 tokens, 15% overlap, keeps
  `document_id, title, page, section_path, department, roles[], tags[], version, effective_date`.
- Retrieval: hybrid = pgvector cosine (HNSW) **+** Postgres FTS (`simple` + `russian` + `english` configs,
  `unaccent`) fused with Reciprocal Rank Fusion; filtered by `workspace_id` (always) and by
  department/role (user may only see chunks whose `roles` is empty or intersects their role).
- Newer `effective_date`/`version` wins when two chunks conflict; the answer mentions the conflict.
- Confidence gate: if fused top score < `RAG_MIN_CONFIDENCE` and no tool produced data → `refused=true`,
  localized "not found in approved documents" answer + `escalate` action. Never invent policy.
- Every answer cites sources (`[1]`, `[2]` → citations array). Prompt-injection hygiene: retrieved text is
  wrapped as data, system prompt says to ignore instructions inside documents.
- Agent loop: max 4 tool iterations; tools declare `required_permission`; the tool registry is per project
  (`apps/ai/app/tools/`), foundation ships `search_documents`.

### 4.7 Worker HTTP (Fastify)

| Path | Purpose |
|---|---|
| `GET /healthz`, `GET /readyz`, `GET /metrics` | liveness, readiness (503 when Postgres/Redis are down), Prometheus (queue depth, job durations) |
| `POST /webhooks/stripe` | raw body, verify `stripe-signature` (STRIPE_WEBHOOK_SECRET), dedupe in `worker.webhook_events` by `event.id`, enqueue `stripe-events` (jobId = event.id) → forward to web `POST /internal/v1/stripe/events` |
| `POST /webhooks/telegram` | check `X-Telegram-Bot-Api-Secret-Token`, enqueue to `telegram-updates` (jobId = update_id) |
| `POST /dev/telegram/update` | **dev/test only** (`DEV_ROUTES=1` and not production): run an Update through the real bot handlers with an API transformer that captures outbound calls; returns `{update_id, outbound:[{method, payload}]}`. Used by Playwright and the web "bot preview". `GET /dev/telegram/outbox` lists calls captured while the bot runs without a token. |
| `/admin/queues` | Bull Board (basic auth `QUEUE_DASHBOARD_USER/PASSWORD`) |

Telegram: `TELEGRAM_MODE=polling|webhook|disabled`. When no token, the bot is constructed with a fake
token + static `botInfo` so the dev route still works. All inbound updates go through the `telegram-updates`
queue (retries, dedupe). Bot texts in `apps/worker/src/i18n/{ru,en}.json`; locale = linked user locale →
Telegram `language_code` → `en`. Account linking: web issues one-time token (15 min) →
`https://t.me/<BOT_USERNAME>?start=<token>` → bot `/start <token>` → `POST /internal/v1/telegram/link`.
Generic commands: `/start`, `/help`, `/lang`, `/whoami`; any free text → `ai /v1/chat` → reply in HTML
with numbered sources and inline buttons for `actions`.

## 5. Web app (Laravel) foundation

- Base: `laravel/vue-starter-kit` (Laravel 13, Inertia 3 + Wayfinder, build tool vite-plus, Vue 3 `<script setup lang="ts">`, Tailwind 4, shadcn-vue/reka-ui,
  lucide-vue-next, Fortify auth incl. 2FA & email verification). Pest for tests. Postgres only (tests too).
- **Tenancy:** `workspaces` + `workspace_user(role)` ; roles enum per project (foundation: `owner`, `admin`,
  `member` + project-specific). Trait `BelongsToWorkspace` (global scope + auto-fill), `CurrentWorkspace`
  resolver (session), workspace switcher, invitations by email (signed link, 7 days), policies/gates,
  `users.is_platform_admin` for the AI admin area.
- **i18n:** `laravel-vue-i18n` (Laravel `lang/{en,ru}/*.php` + `lang/{en,ru}.json` are the single source;
  Vite plugin loads them). `users.locale`, guest locale cookie + `Accept-Language`, `SetLocale` middleware,
  header + landing switcher, localized validation (`lang/ru/validation.php` complete), localized emails and
  notifications, `Intl` formatting on the client (`useFormat()` composable: money, dates, relative time,
  numbers), Russian pluralization verified by a test.
- **Theme:** starter-kit appearance (light/dark/system) kept; brand tokens in `resources/css/brand.css`
  (OKLCH CSS variables for both modes, imported by `app.css` via `@theme inline`); no FOUC (inline script
  in `app.blade.php` reads cookie). Charts read colors from CSS variables so they switch with the theme.
- **Fonts:** `@fontsource-variable/*` imported in `resources/css/app.css`; remove any bunny/google font links.
- **Security:** CSP + security headers middleware (dev allows the Vite origin only), rate limiters
  (login, internal API, bot), encrypted casts for secrets/bank data, `audit_logs` (append-only:
  actor, workspace, action, subject, ip, user_agent, before/after diff, created_at) with an Audit page,
  signed + expiring URLs for file downloads, upload validation (mime sniffing, size limits).
- **Billing:** `BillingGateway` interface with `StripeGateway` (stripe-php: Checkout Session for subscriptions,
  Billing Portal) and `FakeGateway` (`BILLING_DRIVER=fake`, default): checkout redirects to
  `/dev/fake-checkout/{session}`; "Pay" builds Stripe-shaped events, signs them with `STRIPE_WEBHOOK_SECRET`
  (Stripe's `t=…,v1=…` scheme) and POSTs them to worker `/webhooks/stripe` — so the **real** webhook path is
  exercised end-to-end. Our own `subscriptions` table (per workspace) is updated only from webhook events.
  Plans + limits in `config/billing.php`; `PlanLimits` service + middleware + upgrade prompts in UI.
- **Outbox:** `Outbox` service (see §4.2), `OutboxMessage` model.
- **AI admin UI** (platform admin): provider select, model select (live list, free-text allowed), fallback
  model, API key field (shows `…abcd` + source badge, clear button), "Test connection", embeddings provider,
  usage chart (tokens/cost by day) + table. All via `AiClient` (signed HTTP to ai service).
- **Bot preview** (in app): chat panel that sends the same `/v1/chat` request as Telegram (channel `web`) and
  renders citations + actions — makes demos and E2E possible without Telegram.
- **App shell:** collapsible sidebar with workspace switcher, header with command palette (⌘K), locale
  switcher, theme toggle, notifications; breadcrumbs; empty states with illustrations; skeleton loaders;
  toast (sonner); confirm dialogs; data tables (@tanstack/vue-table) with sorting/filter/pagination;
  charts (vue-echarts with tree-shaken echarts, themed from CSS variables).
- **Marketing:** `/` landing, `/pricing`, `/security`, legal pages (`/terms`, `/privacy`) — localized, themed,
  SEO meta (title/description/OG per locale), `hreflang`.
- **Observability:** JSON logs in production, request id middleware, `/up` health, slow-query log.

## 6. Testing strategy

| Layer | Tooling | Must cover |
|---|---|---|
| web | Pest (feature + unit), Postgres test DB | tenancy isolation, policies, internal API signing, outbox, billing webhooks, i18n plural, every domain rule |
| worker | Vitest | signing vector, outbox relay claim/idempotency, routing, bot handlers via dev transformer, webhook verification |
| ai | pytest | signing vector, chunker, hybrid retrieval ranking, confidence gate/refusal, provider manager (mock + fallback/retry with fakes), tools |
| e2e | Playwright + @axe-core/playwright | the product's core journeys in **ru + en** and **light + dark**, accessibility scan on key pages, screenshots to `e2e/artifacts/` |
| UI review | accessibility checklist (§0.5) + screenshots at 375/768/1440 | contrast, focus, hover, spacing, empty/loading/error states |

## 7. Local developer experience

```bash
docker compose up -d                 # postgres(pgvector) + redis + mailpit on project ports
npm run setup                        # composer/npm/uv installs, keys, migrate, seed demo data
npm run dev                          # web + vite + worker + ai via concurrently
npm run test                         # pest + vitest + pytest
npm run e2e                          # playwright (builds assets, starts services)
```

Python via `uv` with Python **3.12** (`uv python install 3.12`; the system Python 3.14 is not used).
Seeders create a realistic, bilingual demo workspace (`demo@<product>.test` / `password`) so every
screen has content.

## 8. Delivery

Dockerfiles (multi-stage, non-root) for web, worker, ai; `docker-compose.prod.yml`; GitHub Actions CI
(services: pgvector, redis; jobs: web, worker, ai, e2e). Env templates documented line by line.

## 9. Brand identities (fonts must support Cyrillic)

| | PayFlow Guard | OpsBot | ScamPause Business |
|---|---|---|---|
| Personality | confident, "money in motion", calm control | friendly, operational, Telegram-native, fresh | vigilant, forensic, serious — "Stop. Call. Confirm." |
| Style | fintech bento + soft aurora gradients; data-dense dashboard | soft-UI / clay-lite, rounded 20px, chat-first, citation chips | Swiss/forensic console, dark-first, grid lines, mono data, diff views, hazard-stripe motif |
| Display / UI / Mono fonts | Manrope Variable / Inter Variable / JetBrains Mono Variable | Unbounded Variable (hero & section titles only) / Onest Variable / JetBrains Mono Variable | IBM Plex Sans (+ Condensed for display if available) / IBM Plex Sans / IBM Plex Mono |
| Primary | emerald `#059669` (light) / `#34D399` (dark) — "paid" | sky `#0284C7` / `#38BDF8` | signal blue `#0369A1` / `#60A5FA` |
| Secondary / accent | ink navy `#0B1324`; amber `#F59E0B` overdue; violet `#7C3AED` AI insights | mint `#14B8A6`; sun `#FACC15` highlight; coral `#FB7185` incidents | hazard amber `#F59E0B` (brand mark "pause"); risk scale red `#EF4444` / orange `#F97316` / yellow `#EAB308` / green `#22C55E` |
| Light bg / Dark bg | `#F7F8FA` / `#070B14` | `#F6FAFD` / `#0A1220` | `#F8FAFC` / `#0A0F1A` |

These hexes are anchors; implement as OKLCH tokens tuned for 4.5:1 contrast in both modes.

## 10. Privacy, legal & compliance (every product)

All three products handle business and personal data (B2B SaaS; users in the EU/US and Russia), so
privacy-by-design and a complete legal pack are mandatory, not optional polish.

### 10.1 Legal documents (RU + EN, versioned)
Stored as Markdown in `apps/web/resources/legal/{en,ru}/<doc>.md` with front-matter
`version`, `effective_date`, `title`; rendered at `/legal/{doc}` (marketing layout, table of contents,
print-friendly, "last updated", link to previous versions); `config/legal.php` holds the operator's
legal entity placeholders (company name, registration no., address, contact & privacy/DPO e-mail,
governing law, support hours) so documents never hard-code them.

| doc key | Purpose |
|---|---|
| `terms` | Terms of Service (B2B): accounts, workspaces, subscriptions via Stripe, auto-renewal, cancellation & refunds, fair use, customer data ownership, IP, AI-output disclaimer, warranties, limitation of liability, suspension/termination, changes, governing law |
| `privacy` | Privacy Policy: controller (account/billing data) vs processor (customer content) roles; data categories; purposes & legal bases (GDPR Art. 6); retention table; sub-processors; international transfers (SCCs); data-subject rights and how to exercise them in-app; Telegram & e-mail channels; AI processing; security; children; contact/DPO; RU section on 152-ФЗ |
| `cookies` | Cookie Policy: the exact cookies used (session, XSRF, locale, appearance) — all strictly necessary, so **no consent banner and no tracking**; states that adding analytics would require opt-in consent first |
| `dpa` | Data Processing Agreement (GDPR Art. 28) template accepted click-wrap by the workspace owner |
| `subprocessors` | Live list (rendered from `config/legal.php`): hosting, e-mail delivery, Stripe, Telegram (only if the customer enables it), each LLM provider (only when enabled — `mock`/local = none), with purpose, data, location |
| `aup` | Acceptable Use Policy (no unlawful collection, harassment, spam, abuse of reminders/bots, uploading data you have no right to) |
| `ai` | AI Transparency notice: what is sent to which model, no training on customer data, answers can be wrong & cite sources, human review, how to switch to local/mock-only processing |
| `consent-ru` | Russian «Согласие на обработку персональных данных» (152-ФЗ) shown for `ru` locale registration |
| product-specific | e.g. PayFlow: reminder-recipient notice; ScamPause: risk-indicator disclaimer ("not a guarantee, independent verification required"); OpsBot: employee notice about question logging and admin visibility |

Every legal doc carries a visible banner in the repo version: *"Template — must be reviewed by a
qualified lawyer for your jurisdiction before production use."* (not shown to end users once
`LEGAL_REVIEWED=true`).

### 10.2 Product features
- **Consent capture**: registration requires an unchecked-by-default checkbox for Terms + Privacy (+ the
  RU 152-ФЗ consent for `ru`); workspace creation requires the owner to accept the DPA. Stored in
  `legal_acceptances(user_id, workspace_id?, document, version, accepted_at, ip, user_agent, locale)`.
  When a document's version changes, a blocking re-acceptance screen appears on next login.
- **Cookie notice**: small non-blocking notice ("we only use strictly necessary cookies" + link),
  dismissal stored in a cookie; no third-party scripts at all.
- **Data subject rights in-app** (Settings → Privacy): download my data (queued export → ZIP of JSON
  + uploaded files the user owns, signed link e-mailed, expires in 24h); delete my account (re-auth,
  ownership transfer required if sole owner of a workspace with members, 14-day grace period with
  cancel link, then hard delete + pseudonymize audit entries); workspace owners: export workspace,
  delete workspace (30-day grace, then purge DB rows, files, RAG chunks/embeddings, queued jobs).
- **Retention** (configurable per workspace within plan limits, defaults in `config/retention.php`):
  AI query logs 180 days, audit log 365 days, delivery logs 90 days, webhook events 30 days,
  dispatched outbox rows 7 days, deleted documents purged from storage + RAG immediately, failed
  jobs 30 days. A scheduled purge job enforces it and writes an audit entry with counts.
- **AI data controls** (workspace setting): "Allow external LLM providers" (off → only mock/local
  models are used); PII redaction before prompts leave the system (e-mails, phones, card numbers,
  IBAN/account numbers masked to last 4 unless the tool strictly needs them); no prompt/response
  logging of secrets; provider + model recorded per answer.
- **Telegram**: `/privacy` command and first-contact notice (what is stored, who sees it, how to unlink);
  `/unlink` removes the link. No message content stored beyond the AI query log retention.
- **E-mail**: every message has sender identity, privacy link, and (for automated customer-facing
  messages such as reminders) a recipient opt-out link that notifies the sender.
- **Security disclosures**: `/.well-known/security.txt`, `/security` page, `robots.txt`, and a
  status/contact route; breach-notification procedure documented in `docs/SECURITY.md`.
- **Footer** on every marketing page and the app's user menu link to: Terms, Privacy, Cookies, DPA,
  Sub-processors, AUP, AI notice, Security, Contact.
- **Tests**: acceptance recorded on registration (both locales), re-acceptance flow, export contents,
  deletion grace + purge, retention purge counts, external-LLM switch respected, PII redaction unit tests,
  legal pages render in ru/en/light/dark with no external requests.

## 11. Payments & markets (foundation step F3 — after F2)

Decision (user, 2026-10-22): these are portfolio products; **Stripe is the primary, fully implemented
provider** (real Stripe SDK/API, test mode). The architecture must also be ready for the Russian market,
where Stripe is unavailable, via a second driver (**YooKassa**). The `fake` driver stays the default for
offline dev, CI and E2E. Steps other than F3 must not implement this section — only avoid contradicting it.

### 11.1 Gateways
- `BillingGateway` (SaaS subscriptions) and `PaymentGateway` (one-off payments a customer collects — used by
  PayFlow for client invoices) are interfaces with drivers `stripe`, `yookassa`, `fake`.
- Driver choice: per workspace by **market** chosen at onboarding (`workspaces.country`, `workspaces.currency`):
  RUB / RU → `yookassa`, everything else → `stripe`; overridable in config. Currencies: USD, EUR (Stripe),
  RUB (YooKassa). Money = integer minor units + ISO currency everywhere; prices per currency in `config/billing.php`.
- Idempotency keys on every write call (`Idempotency-Key` for Stripe, `Idempotence-Key` for YooKassa).
  No card data ever touches our servers (hosted pages only → PCI SAQ A).

### 11.2 Stripe (primary)
- **SaaS billing:** `php artisan billing:sync-stripe` creates/updates Products + Prices idempotently via
  `lookup_key`s (per plan × interval × currency); Checkout Session (`mode=subscription`, customer per workspace
  with metadata, optional trial, tax-ID collection, `automatic_tax` behind a flag); Customer Portal (configuration
  created by the sync command); plan changes with proration; cancel at period end; dunning: `invoice.payment_failed`
  → `past_due` banner + e-mail, `invoice.paid` → active; `customer.subscription.trial_will_end` reminder.
  Webhook events handled (via worker ingress → web): `checkout.session.completed`, `customer.subscription.created|updated|deleted`,
  `invoice.paid`, `invoice.payment_failed`, `customer.subscription.trial_will_end`.
- **Client payments (PayFlow), exactly as the product brief says: "for MVP, SaaS billing + Stripe Payment Links are
  enough; a full marketplace needs Stripe Connect".** MVP: each workspace connects **its own Stripe account** with a
  restricted API key (stored encrypted; scopes: Payment Links, Prices, Products, Checkout Sessions read, Webhook Endpoints);
  PayFlow creates a per-invoice Payment Link (metadata `invoice_id`, `workspace_id`) and registers a webhook endpoint in
  that account with its own signing secret; the worker verifies with the workspace's secret and handles
  `checkout.session.completed`, `payment_intent.succeeded|payment_failed`, `charge.refunded`; a reconciliation job re-fetches
  open links every 30 min. Stripe Connect (Express/Standard) is the documented v2 path behind the same `PaymentGateway`.
- **Testing:** `stripe/stripe-mock` container added to each project's compose (PayFlow 12111, OpsBot 12121,
  ScamPause 12131) so the real `StripeGateway` code path is integration-tested with the official SDK
  (`STRIPE_API_BASE` override in testing); webhook signatures generated with Stripe's test header helper.
  With real test keys (`sk_test_…`) the README documents `stripe listen --forward-to http://localhost:<worker>/webhooks/stripe`
  and an optional Playwright project `stripe-live` that runs only when such keys are present.

### 11.3 YooKassa (RU-market readiness)
- REST API v3 via a small typed client (Basic auth `shopId:secretKey`, `Idempotence-Key`).
- **Subscriptions:** YooKassa has no native subscriptions → first payment with `save_payment_method: true`
  (confirmation redirect), then the worker's monthly scheduler charges `payment_method_id` server-side;
  failures follow the same dunning state machine as Stripe.
- **Receipts (54-ФЗ):** every payment carries a `receipt` (customer e-mail, items, `vat_code`, `payment_subject`,
  `payment_mode`) when `YOOKASSA_RECEIPTS=true`.
- **Notifications (webhooks):** accepted by the worker only from YooKassa's published IP ranges, then the object
  is **re-fetched via the API** before being trusted (YooKassa does not sign notifications); idempotent by object id.
- **Client payments (PayFlow, RU merchants):** the merchant's own shop credentials (shopId + secret key) stored
  encrypted per workspace; payment links = YooKassa payments with redirect confirmation.
- Tests use HTTP fakes with recorded fixture payloads (no public mock server exists).

### 11.4 Market-aware product & legal
- Onboarding asks for country → sets currency, gateway, date/number formats defaults and the legal set.
- RU market: Russian Terms act as a public offer («публичная оферта»), 152-ФЗ consent required, receipts on,
  data-residency note: a RU deployment must run in a RU region (`DATA_REGION=ru` documented; single-region per
  deployment in MVP). EU/US market: GDPR documents, Stripe Tax flag, VAT IDs on invoices.
- Pricing pages show prices in the visitor's market currency (USD/EUR/RUB) with locale formatting.
