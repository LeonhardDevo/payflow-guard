# PayFlow Guard — Architecture

Companion to `PLATFORM.md` (shared blueprint), `docs/FOUNDATION_CONTRACT.md` (foundation internals) and
`docs/DOMAIN_CONTRACT.md` (domain build spec, revision 3). This document shows how the pieces fit; the contracts define the exact shapes.

Stack (PLATFORM §0.10, ADR-0014): **Laravel + Vue** (`apps/web`), **Node.js + BullMQ/Redis** (`apps/worker`: queues, schedulers, webhook
ingress, grammY Telegram bot **and the TypeScript RAG**), **Playwright** (`e2e`), PostgreSQL + pgvector, Redis. There is no Python/FastAPI service.

## 1. System context

```mermaid
flowchart LR
    subgraph people["Agency team (workspace members)"]
        owner["Owner / MD"]
        finance["Finance manager"]
        approver["Approver / external approver"]
        am["Account manager"]
    end
    trusted["Trusted contact<br/>(solo mode, e-mail only)"]
    client["Client AP clerk<br/>(payer)"]
    vendor["Vendor / contractor<br/>(payee)"]
    attacker["Fraudster<br/>(lookalike email)"]

    pfg(["PayFlow Guard<br/>collections autopilot + payout guard"])

    stripePlatform["Stripe — platform account<br/>(SaaS subscriptions)"]
    stripeWs["Stripe — workspace's own account<br/>(Payment Links via restricted key)"]
    yookassa["YooKassa — merchant's own shop<br/>(RU merchants)"]
    telegram["Telegram Bot API"]
    smtp["SMTP provider<br/>(Mailpit in dev)"]
    llm["LLM providers<br/>(optional, mock by default)"]
    bank["Agency's bank<br/>(transfers executed manually)"]

    owner & finance & approver & am -->|web app, Telegram| pfg
    pfg -->|invoices, reminders, receipts<br/>with privacy notice + opt-out| smtp --> client
    client -->|public invoice page, pays| stripeWs
    client -->|pays in RUB| yookassa
    client -->|"already paid / stop reminders"| pfg
    stripeWs -->|events to the workspace's own endpoint + secret| pfg
    yookassa -->|notifications, re-fetched| pfg
    stripePlatform -->|subscription webhooks| pfg
    pfg <-->|alerts, approvals, Q&A| telegram
    pfg -->|confirmation link| trusted
    pfg -->|answers with citations| llm
    vendor -.->|bank-detail change request| finance
    attacker -.->|"we changed our bank"| finance
    finance -->|executes approved payouts| bank --> vendor
```

PayFlow Guard never moves or holds money itself (ADR-0008): client payments are Stripe Payment Links on the workspace's own Stripe account
(restricted key, per-workspace webhook endpoint) or its own YooKassa shop through the foundation `PaymentGateway` (ADR-0013, PLATFORM §11.2);
vendor payouts are approved in PayFlow and executed by the customer in their bank. Stripe Connect is v2.

## 2. Containers

```mermaid
flowchart TB
    browser["Browser<br/>Vue 3 + Inertia (app, public invoice, trusted confirmation, landing)"]
    tg["Telegram"]
    psp["Stripe (platform billing + workspaces' own accounts) / YooKassa"]

    subgraph web["web — Laravel 13 + Inertia (port 8100)"]
        ui["Inertia pages<br/>(web-domain-ui)"]
        domain["Domain modules<br/>Receivables · Collections · Payments · Guard · Analytics · Imports · Notifications · Privacy"]
        payments["Foundation F3<br/>PaymentGateway · PaymentAccountService"]
        internal["Internal API /internal/v1/*<br/>(per-pair HMAC keys, caller groups)"]
        outboxW["Outbox publisher<br/>(same DB transaction)"]
    end

    subgraph worker["worker — Node 24 + BullMQ + grammY + Fastify (role jobs,bot,ingress — port 8101)"]
        relay["Outbox relay<br/>LISTEN outbox + poll"]
        sched["Job schedulers<br/>pf-reminders-plan · pf-risk-recompute · pf-digests · pf-payments-sync · pf-guard-hourly · pf-maintenance-purge"]
        queues["Queues<br/>emails (client_mail retry profile) · telegram · pf-reminders · pf-payments · pf-imports · payment-events (F3)"]
        ingress["Webhook ingress<br/>/webhooks/stripe · /webhooks/stripe/accounts/:endpointKey · /webhooks/yookassa · /webhooks/telegram"]
        bot["Telegram bot<br/>core + payflowModule + assistant"]
    end

    subgraph ai["rag — same Node image, role rag (port 8102 in prod; in-process on 8101 in dev) — X-Service ai"]
        agent["Agent loop (TypeScript)<br/>tools + hybrid RAG + confidence gate"]
        ingest["Ingest: keyless parser sandbox · OCR (Tesseract) · chunk · embed"]
        providers["LLM provider manager<br/>(TBO port, mock default)"]
    end

    subgraph pg["Postgres 17 + pgvector (port 5441)"]
        pub[("public<br/>business data, outbox, audit")]
        rag[("rag<br/>chunks, embeddings, usage, conversation memory")]
        wsch[("worker<br/>webhook_events")]
    end
    redis[("Redis 7 (port 6391)<br/>BullMQ, stored bot actions, ForceReply state")]
    mail["SMTP / Mailpit (1031 / 8131)"]

    browser --> ui --> domain
    domain --> payments --> psp
    domain --> pub
    domain --> outboxW --> pub
    relay --> pub
    relay --> queues
    sched --> queues
    queues <--> redis
    queues -->|"signed (web/worker key)"| internal
    internal --> domain
    queues --> mail
    queues --> tg
    tg --> ingress
    psp --> ingress
    ingress --> queues
    bot -->|views, decisions| internal
    bot -->|"free text (worker→ai key)"| agent
    domain -->|"/v1/chat, /v1/payflow/reminder-draft (web/ai key)"| agent
    agent -->|"read-only tools: /internal/v1/payflow/ai/*"| internal
    agent --> rag
    ingest --> rag
    ingest -->|download file, status| internal
    providers --> agent
```

Service rules (PLATFORM §3): web owns all business rules and data; the worker owns timing, retries, delivery and webhook ingress and
calls web for every decision; the RAG role (wire name `ai`, ADR-0014) never writes business tables and reads domain data only through
permission-checked, read-only tools. Internal calls are signed with a key per service pair and every internal route declares its allowed callers
(ADR-0010, overrides PLATFORM §4.1).

## 3. Data flow — from invoice to cash (and from request to payout)

```mermaid
flowchart LR
    subgraph AR["Money in"]
        create["Create / import invoice<br/>(imports start paused)"] --> activate["Activation review<br/>(tones, throttle)"]
        create --> issue["Issue: number, PDF,<br/>payment link, email v2"]
        activate --> plan
        issue --> public["Public invoice page<br/>(views tracked if enabled)"]
        issue --> plan["Planner: due steps, client-tz window,<br/>first-contact cap, tone"]
        plan --> remind["Reminder emails<br/>(outbox → emails queue, retries)"]
        remind --> public
        public --> pay["Client pays via Payment Link on<br/>the workspace's Stripe / YooKassa"]
        public --> choice["Already paid /<br/>stop automated reminders"]
        choice --> plan
        pay --> hook["Provider event: workspace endpoint,<br/>own secret, dedupe"]
        hook --> record["Record payment,<br/>update status"]
        manual["Manual payment<br/>(bank transfer)"] --> record
        record --> stats["Client stats, risk score,<br/>analytics, Telegram"]
        stats --> plan
    end
    subgraph AP["Money out"]
        req["Bank-detail change request"] --> score["Risk scoring<br/>(lookalike, holder, country…)"]
        score --> hold["Hold vendor payouts,<br/>alert eligible verifiers"]
        hold --> verify["Callback verification<br/>by a second eligible person (step-up)"]
        verify --> cool["Cooling-off"]
        payout["Payout request"] --> prisk["Payout risk (incl. split) +<br/>required approvals"]
        cool --> prisk
        prisk --> approve["Approvals (web / Telegram /<br/>trusted contact), four-eyes"]
        approve --> release["Release with bank reference<br/>(customer pays in bank)"]
    end
    stats -.-> rag1[("RAG + tools")]
    score -.-> rag1
```

## 4. Domain data model (simplified ER)

```mermaid
erDiagram
    WORKSPACES ||--|| PAYFLOW_SETTINGS : configures
    WORKSPACES ||--o{ CLIENTS : has
    WORKSPACES ||--o| PAYMENT_ACCOUNTS : "connects (F3)"
    WORKSPACES ||--o{ RECEIVING_DETAILS_VERSIONS : "prints on invoices"
    WORKSPACES ||--o| TRUSTED_CONTACTS : "solo mode"
    CLIENTS ||--o{ INVOICES : billed
    INVOICES ||--|{ INVOICE_ITEMS : contains
    INVOICES ||--o{ PAYMENTS : receives
    INVOICES ||--o{ PAYMENT_LINKS : "pay via"
    PAYMENT_ACCOUNTS ||--o{ PAYMENT_LINKS : hosts
    INVOICES ||--o{ REMINDERS : chased_by
    INVOICES ||--o{ INVOICE_EVENTS : timeline
    REMINDER_POLICIES ||--|{ REMINDER_POLICY_STEPS : defines
    REMINDER_POLICY_STEPS ||--o{ REMINDERS : schedules
    CLIENTS }o--o| REMINDER_POLICIES : uses
    WORKSPACES ||--o{ MESSAGE_TEMPLATES : owns
    PAYMENT_ACCOUNTS ||--o{ PAYMENT_PROVIDER_EVENTS : "receives (F3)"
    WORKSPACES ||--o{ VENDORS : pays
    VENDORS ||--|{ VENDOR_BANK_ACCOUNTS : "versioned details"
    VENDORS ||--o{ PAYOUT_REQUESTS : "paid by"
    VENDOR_BANK_ACCOUNTS ||--o{ PAYOUT_REQUESTS : "bound to"
    PAYOUT_REQUESTS ||--o{ PAYOUT_APPROVALS : "approved by"
    TRUSTED_CONTACTS ||--o{ TRUSTED_CONFIRMATIONS : confirms
    TRUSTED_CONFIRMATIONS ||--o| PAYOUT_APPROVALS : "counts as"
    WORKSPACES ||--o{ GUARD_ALERTS : raises
    WORKSPACES ||--o{ IMPORTS : runs
    CLIENTS }o--o| DOCUMENTS : "contract in KB"

    INVOICES {
        bigint id
        varchar number
        varchar status
        bigint total_minor
        bigint amount_paid_minor
        bigint balance_due_minor
        char currency
        date due_date
        smallint risk_score
        varchar reminders_pause_source
    }
    VENDOR_BANK_ACCOUNTS {
        bigint id
        int version
        text iban "encrypted, reveal-only"
        char fingerprint "HMAC"
        char last4
        varchar status
        smallint risk_score
        bigint requested_by_user_id
        bigint verified_by_user_id "must differ unless solo"
    }
    PAYOUT_REQUESTS {
        bigint id
        varchar number
        bigint amount_minor
        varchar status
        smallint required_approvals
        smallint approvals_count
        char bank_fingerprint
        boolean release_requires_other_user
    }
```

## 5. State machines

```mermaid
stateDiagram-v2
    direction LR
    [*] --> draft
    draft --> open: issue (send / mark sent)
    draft --> void: void
    open --> partially_paid: partial payment
    open --> paid: full payment
    partially_paid --> paid: remaining paid
    open --> disputed: dispute
    partially_paid --> disputed: dispute
    disputed --> open: resolve
    disputed --> partially_paid: resolve
    open --> uncollectible: write off
    partially_paid --> uncollectible: write off
    disputed --> uncollectible: write off
    uncollectible --> paid: late payment
    open --> void: void (nothing paid)
    paid --> partially_paid: refund
    paid --> open: full refund
```
*Invoice. "Overdue" and "due soon" are derived display states, not stored. Reminder pauses (manual, import review, client reported paid,
recipient opt-out) are flags, not statuses.*

```mermaid
stateDiagram-v2
    direction LR
    [*] --> draft
    draft --> draft: submit blocked (insufficient approvers)
    draft --> pending_approval: submit (required ≥ 1)
    draft --> approved: submit (auto, required = 0)
    pending_approval --> approved: approvals (users / trusted contact) = required
    pending_approval --> rejected: any approver or the contact declines
    pending_approval --> on_hold: bank change / vendor hold
    approved --> on_hold: bank change / vendor hold
    approved --> pending_approval: approver removed (new round)
    on_hold --> pending_approval: resubmit (new round)
    approved --> released: release (step-up, right releaser, delay passed)
    draft --> canceled
    pending_approval --> canceled
    approved --> canceled
    on_hold --> canceled
```
*Payout request.*

```mermaid
stateDiagram-v2
    direction LR
    [*] --> pending_verification: request (risk scored, payouts held)
    pending_verification --> verified: callback by another eligible person (+ cooling-off)
    pending_verification --> verified: solo mode (trusted-contact confirmation, ≥ 72 h cooling-off)
    pending_verification --> rejected: reject / withdraw
    verified --> superseded: newer details verified
```
*Vendor bank account version.*

```mermaid
stateDiagram-v2
    direction LR
    [*] --> scheduled: planner
    scheduled --> queued: dispatch (email.send v2 in outbox)
    scheduled --> skipped: ineligible at dispatch
    scheduled --> canceled: invoice closed / paused / opted out / policy changed
    queued --> deferred: SMTP 4xx, retry scheduled
    deferred --> deferred: next retry
    queued --> sent: delivery ok
    deferred --> sent: delivery ok
    queued --> failed: permanent failure
    deferred --> failed: retries exhausted
    failed --> queued: manual retry
```
*Reminder.*

## 6. Core flows

### 6.1 Invoice → payment link → client pays → provider event → paid

```mermaid
sequenceDiagram
    autonumber
    actor F as Finance (web)
    participant W as web (Laravel + F3 PaymentGateway)
    participant DB as Postgres (public)
    participant S as Stripe (workspace's own account) / fake checkout
    participant R as worker (relay + queues)
    actor C as Client
    participant T as Telegram

    F->>W: POST /invoices (save & send)
    W->>DB: BEGIN, insert invoice, items, assign number
    W->>S: Price + Payment Link with the workspace's restricted key (completed_sessions.limit=1)
    S-->>W: plink_… url
    W->>DB: payment_links row, outbox email.send v2 (invoice_issued, attachment by internal_path), COMMIT, NOTIFY outbox
    R->>DB: claim outbox rows (SKIP LOCKED)
    R->>R: emails queue (jobId = outbox id, client_mail retry profile)
    R->>W: GET /internal/v1/payflow/invoices/{id}/pdf (signed, web/worker key)
    R-->>C: email (sender name, Reply-To, PDF, legal footer) with link to /i/{token}
    R->>W: POST /internal/v1/deliveries (sent)
    C->>W: GET /i/{token} (view recorded if enabled)
    C->>W: POST /i/{token}/pay
    W-->>C: redirect to Payment Link / fake checkout (YooKassa: payment created now)
    C->>S: pays
    S->>R: POST /webhooks/stripe/accounts/{endpoint_key} (stripe-signature)
    R->>W: GET /internal/v1/payments/endpoints/{key} (worker only; secret cached in memory 5 min)
    R->>R: verify with that workspace's secret (±300 s), dedupe (stripe_account, event.id) in worker.webhook_events
    R->>R: enqueue payment-events (jobId pevt-stripe-{event.id})
    R->>W: POST /internal/v1/payments/events (F3, worker only)
    W->>DB: BEGIN, provider event (unique driver+event_id), account from the endpoint key → workspace
    W->>DB: domain listener: payment (unique payment_intent), invoice paid, cancel reminders, deactivate link
    W->>DB: outbox email.send (receipt) + telegram.notify (payment_received), COMMIT
    R-->>C: receipt email
    R->>T: "Payment received …" to finance / owner
    Note over R,W: Every 30 min payments:sync calls PaymentGateway::listCompletedSessions<br/>for active links in case an event was lost.
    C->>W: GET /i/{token}/status (thanks page polling) → paid
```

### 6.2 Reminder planning and dispatch

```mermaid
sequenceDiagram
    autonumber
    participant SCH as worker scheduler pf-reminders-plan (*/15)
    participant Q as BullMQ pf-reminders
    participant W as web (planner / dispatcher)
    participant DB as Postgres
    participant E as emails queue → SMTP
    actor C as Client

    SCH->>W: GET /internal/v1/payflow/workspaces?active=1
    SCH->>Q: reminders:plan per workspace (jobId plan-{ws}-{tick})
    Q->>W: POST /workspaces/{ws}/reminders/plan
    W->>DB: due steps → insert reminders ON CONFLICT DO NOTHING<br/>(superseded skipped, client-tz window, spread, first-contact throttle, tone)
    W-->>Q: scheduled reminders within 26 h (incl. earlier ones)
    Q->>Q: reminders:dispatch delayed until scheduled_for (jobId rem-{id}-{epoch}, removeOnComplete)
    Q->>W: POST /reminders/{id}/dispatch {expected_scheduled_for}
    alt still eligible
        W->>DB: lock, first-contact cap (≤ neutral), ensure link, render (client locale, tone, legal footer), outbox email.send v2, status queued
        W-->>Q: {status: queued}
        E-->>C: reminder email (+ PDF, List-Unsubscribe one-click)
        E->>W: POST /internal/v1/deliveries → deferred (retrying) / sent / failed (+ alert, Telegram)
    else paid / paused / opted out / disputed
        W->>DB: status skipped (reason)
        W-->>Q: {status: skipped}
    else rescheduled (min interval / window) or too early
        W-->>Q: {status: rescheduled | too_early, scheduled_for}
        Q->>Q: new delayed job (rem-{id}-{epoch} or …-r{n})
    end
```

### 6.3 Bank-detail change → verification → four-eyes payout (with Telegram approval)

```mermaid
sequenceDiagram
    autonumber
    actor M as Mark (finance, requester)
    participant W as web
    participant R as worker
    participant T as Telegram
    actor D as Dmitry (approver, eligible)
    actor A as Anna (owner)

    M->>W: Request bank change (source email, request text)
    W->>W: normalize + fingerprint, risk signals → 100 / high
    W->>W: payouts of vendor → on_hold, approvals invalidated, alerts
    W->>R: outbox telegram.notify (bank_change_high_risk) to eligible verifiers ≠ Mark, owners
    R->>T: messages with "Review in app" (plain URL line when not public)
    D->>W: open review: diff, signals, known phone (view audited)
    D->>D: calls vendor at the number on file
    D->>W: verify (checklist, method, note) + step-up (passkey / TOTP)
    W->>W: verifier eligible (trust delay, 2FA), ≠ requester (CHECK), verified, cooling-off 48 h, old version superseded
    M->>W: resubmit / create payout
    W->>W: payout risk (cooling_off) → required approvals = 2, telegram_allowed = false
    W->>R: telegram.notify payout_pending_approval (web-only link)
    D->>W: approve in web (step-up) → 1/2
    A->>W: approve in web (step-up) → 2/2 → approved
    M->>W: release with bank reference → released (re-checks fingerprint)
    Note over T,W: For low/medium risk the approval can happen in Telegram (stored actions):
    D->>T: tap "Approve" (callback_data a:ID → type pf.pa, arg {id}:{fp8})
    T->>R: callback_query → payflowModule action pf.pa
    R->>W: POST /internal/v1/payflow/bot/payouts/{id}/decision {confirmed:false}
    W-->>R: confirmation prompt (workspace taken from the payout)
    R->>T: "Approve PO-2026-0089 — $7,200.00 to Ivan Smirnov (•••• 0047)?"
    D->>T: "Confirm approval" (a:ID → pf.pac)
    R->>W: decision {confirmed:true, fp8}
    W->>W: four-eyes, eligibility, permission in the payout's workspace, fp8 = current fingerprint, band ≠ high
    W-->>R: approved 1/1
    R->>T: edit message → "Approved — ready to release"
```

### 6.4 Telegram question answered with tools + RAG

```mermaid
sequenceDiagram
    autonumber
    actor U as User in Telegram
    participant R as worker (bot)
    participant W as web (internal API)
    participant AI as rag role (TypeScript agent, X-Service ai)
    participant V as rag (pgvector + FTS)

    U->>R: "Какой штраф предусмотрен договором с Балтийской логистикой?"
    R->>W: GET /internal/v1/telegram/context?telegram_user_id=…
    W-->>R: user, workspace, role, permissions[], locale ru, ai_quota
    R->>AI: POST /v1/chat {workspace_id, user{permissions, locale}, channel: telegram, conversation_id: tg-<chat>, message} (worker→ai key)
    AI->>AI: intent "penalty" (mock rules, multi-tool) or LLM tool choice
    AI->>W: GET /internal/v1/payflow/ai/clients/terms?client_query=Балтийской логистикой (ai-only, read-only)
    W->>W: verify membership, resolve client (trigram + transliteration), check clients.view
    W-->>AI: {summary, data: late_fee 0.1%/day, cap 10%, contract_document, accrued_now NS-2026-0145 26,95 €}
    AI->>V: hybrid search (vector + FTS, RRF) filtered by workspace + roles
    V-->>AI: chunk "3.1 … 0,1% … не более 10% …" (contract, RU)
    AI->>AI: compose answer (domain renderer), cite [1], confidence gate passes
    AI->>W: POST /internal/v1/ai-queries (log)
    AI-->>R: {answer_html, citations[1], tools_used[get_contract_terms, search_documents]}
    R->>U: answer + "Источники: [1] Договор № NSD-2025-031" + "Данные PayFlow на 10:42"
```

## 7. Cross-cutting concerns

| Concern | Approach |
|---|---|
| Idempotency | Outbox `jobId = outbox id`; provider events deduped by `(driver, event_id)` in worker **and** web; `payments.provider_payment_id` unique; reminders unique per (invoice, step); all job ids use `-` separators (BullMQ 5 rejects `:`); dispatch re-validates state |
| Time | `Clock` interface (web), `now` override only when not production and `DEV_ROUTES=1`; workspace timezone for business dates, client timezone for send slots; BullMQ delays computed from UTC |
| Security | Per-pair HMAC keys + caller-scoped internal routes (ADR-0010), RAG in its own container with a keyless parser sandbox (ADR-0014); per-workspace Stripe webhook secrets + restricted keys encrypted (ADR-0013), YooKassa IP allow-list and re-fetch; encrypted bank data (display vs reveal-only) + HMAC fingerprints; four-eyes enforced in policy and DB CHECK; privileged roles owner-granted with trust delay; `guard.stepup` with passkey/TOTP; high-risk approvals web-only; CSP `default-src 'self'` |
| Privacy | Masked bank data everywhere except audited reveal; no holder names to AI; PII redaction for external LLMs; recipient notice and opt-out in every client e-mail; retention purge, export, anonymisation, actor pseudonymisation (DOMAIN_CONTRACT L) |
| Observability | Request id end-to-end; Prometheus metrics in worker (`pf_*`); structured JSON logs; audit log for money/security actions and retention runs |
| Offline | Fake payment driver (Stripe-shaped events signed with the fake account's secret through the real per-workspace ingress), Telegram outbound capture, mock LLM, hash embeddings — every flow incl. E2E runs without keys |
| i18n | Client-facing texts rendered in the client's locale; team-facing in the user's; RU plurals via `trans_choice` / `Intl.PluralRules`; money formatting CLDR-identical on server and client; no emoji anywhere |

## 8. Deployment

`docker-compose.prod.yml`: `postgres` (pgvector), `redis` (AOF), `web` (FrankenPHP, non-root, `php artisan migrate --force` on release),
no Laravel cron — all schedules live in the worker's BullMQ Job Schedulers (ADR-0004); `worker` (2 replicas safe:
schedulers are Redis-coordinated, relay uses `SKIP LOCKED`; `WORKER_ROLES=jobs,bot,ingress`), `rag` (same image, `WORKER_ROLES=rag`, port 8102,
internal network only; `WORKER_ROLES=all` is refused in production). There is no Python container. The worker is the only public webhook
endpoint besides web (`WORKER_PUBLIC_URL`: `/webhooks/stripe`, `/webhooks/stripe/accounts/:endpointKey`, `/webhooks/yookassa`, `/webhooks/telegram`).
Secrets: three internal pair keys (worker holds web/worker + worker/ai, rag holds web/ai + worker/ai), the platform Stripe webhook secret,
`APP_KEY` (encryption of bank data, restricted keys and per-workspace webhook secrets; fingerprints). Backups: nightly `pg_dump` + WAL
archiving; Redis is reconstructible (outbox + self-healing planner; stored bot actions simply expire). A RU deployment runs in a RU region
(`DATA_REGION=ru`, PLATFORM §11.4).

## 9. ADR index

| ADR | Decision |
|---|---|
| [0001](adr/0001-positioning-collections-and-payout-guard.md) | Position as collections autopilot + payout guard on top of existing invoicing (import-first); freelancers served through solo mode |
| [0002](adr/0002-client-payments-on-workspace-stripe-account.md) | Restricted Stripe key with a per-workspace webhook endpoint — reinstated in amended form by 0013 |
| [0003](adr/0003-deterministic-rule-based-scoring.md) | Deterministic, explainable rule-based scoring (late-payment probability, tone, bank-change & payout risk); LLM only for wording and Q&A |
| [0004](adr/0004-two-phase-reminder-scheduling.md) | Reminder scheduling: worker schedulers + web-owned planning + re-validation at dispatch (self-healing, idempotent, BullMQ-safe ids) |
| [0005](adr/0005-four-eyes-approvals-and-telegram-limits.md) | Four-eyes approvals bound to amount + bank fingerprint; Telegram approvals only for low/medium risk (amended by 0011) |
| [0006](adr/0006-bank-details-encryption-and-fingerprints.md) | Bank details: versioned, encrypted at rest, HMAC fingerprints for equality, masked by default, audited reveal |
| [0007](adr/0007-money-minor-units-no-fx.md) | Money as integer minor units + currency; no FX conversion; CLDR-exact formatting |
| [0008](adr/0008-control-layer-no-money-movement.md) | PayFlow is a control layer: it never initiates bank transfers or holds funds |
| [0009](adr/0009-client-payments-via-payment-gateway.md) | *Superseded by 0013* — Stripe Connect Express via the foundation `PaymentGateway` |
| [0010](adr/0010-per-pair-internal-keys-and-caller-scoped-routes.md) | Per-pair internal API keys and caller-scoped internal routes (overrides PLATFORM §4.1) |
| [0011](adr/0011-insider-resistant-four-eyes-and-solo-mode.md) | Insider-resistant four-eyes: owner-only privileged grants, trust delay, narrow auto-approval, split detection, second-factor step-up, external approver and solo mode |
| [0012](adr/0012-domain-privacy-retention-and-pseudonymisation.md) | Domain privacy: retention, export, anonymisation, recipient notice and opt-out, actor pseudonymisation |
| [0013](adr/0013-client-payments-payment-links-on-workspace-stripe-account.md) | Client payments per PLATFORM §11.2: Payment Links on the workspace's own Stripe account (restricted key, per-workspace endpoint + secret, reconciliation), YooKassa for RUB, fake offline, Connect = v2 |
| [0014](adr/0014-rag-and-ai-tools-in-the-node-service.md) | Strict stack: RAG, agent tools and provider manager in TypeScript inside the Node service (roles `jobs,bot,ingress` / `rag`, keyless parser sandbox); no Python |
| [0015](adr/0015-saas-billing-markets-and-stripe-catalog.md) | SaaS billing by market: gateway per workspace country (Stripe USD/EUR, YooKassa RUB), Stripe catalog by lookup key via `billing:sync-stripe`, webhook-only subscription state with dunning |
