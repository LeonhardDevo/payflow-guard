# PayFlow Guard — Product & Production Plan

> Status: approved for build (M1+), revision 3 (2026-10-22: strict stack, client payments per PLATFORM §11.2, adversarial review applied). Owner: product architecture.
> Binding companions: `PLATFORM.md` (shared blueprint, wins on conflicts unless a project doc says "overrides PLATFORM.md §X"),
> `docs/FOUNDATION_CONTRACT.md` and the foundation code in `apps/*` (what already exists), `docs/DOMAIN_CONTRACT.md` (precise build spec,
> including every foundation extension point in K.4), `docs/ARCHITECTURE.md` + `docs/adr/*` (diagrams and decisions),
> `design-system/payflow-guard/MASTER.md` (brand & UI).
>
> Project-level overrides of PLATFORM.md: **§4.1** single shared internal secret → per-pair keys and caller-scoped routes (ADR-0010,
> reason: a compromised RAG/ingest path must not be able to approve payouts). Everything else follows PLATFORM.md, including §0.8 (icons
> only), §0.10 (strict stack), §10 (privacy) and §11 (payments, ADR-0013).
>
> **Stack (PLATFORM §0.10, ADR-0014) — exactly what the brief names, nothing else:**
>
> | Brief item | Where | Notes |
> |---|---|---|
> | Laravel + Vue SaaS | `apps/web` (Laravel 13, Inertia, Vue 3, shadcn-vue, Tailwind 4) | all business rules and data, own PayFlow UI (MASTER.md) |
> | Telegram bot with RAG | `apps/worker/src/bot/**` (grammY) + `apps/worker/src/rag/**` (TypeScript RAG: ingestion, pgvector + FTS hybrid retrieval, TBO provider manager port, agent tools calling web's internal API) | same `/v1` contract as PLATFORM §4.4; **no FastAPI / Python** — the scaffold's `apps/ai` is replaced (DOMAIN_CONTRACT K.4 F18) |
> | Node.js + BullMQ/Redis | `apps/worker` (Fastify, BullMQ 5, roles `jobs,bot,ingress` and `rag`) | reminders, retries, webhooks, digests, imports |
> | Playwright | `e2e` | journeys J1–J13 |
> | PostgreSQL + pgvector, Redis | Docker infra (PG 5441, Redis 6391, Mailpit 1031/8131) | |

---

## 1. Vision

**Get invoices paid on time, and stop risky payouts before they leave.**
PayFlow Guard is the *cash-control layer* for small service businesses: it takes an issued invoice all
the way to money in the bank (payment link, reminder cadence with the right tone, late-payment prediction,
late-payer insight) and puts a second pair of eyes on every risky outgoing payment (bank-detail change
verification, four-eyes approval, Telegram alerts). An AI assistant with RAG over the company's own
contracts, credit policy, templates and client history answers "when / how much / who changed what"
questions in Telegram and in the app, always with citations, never inventing policy.

RU one-liner for the landing: «Доводим счета до оплаты и ставим второй замок на рискованные выплаты».
Copy never promises absolute protection: PayFlow *reduces* risk and adds verification steps (payment-guard disclaimer, DOMAIN_CONTRACT L.1).

## 2. Positioning — why this is not "just another invoicing tool"

The idea brief is explicit: QuickBooks, Xero, FreshBooks and Stripe already own invoicing. We do **not**
compete on creating invoices, bookkeeping, tax or payroll. We compete on two jobs those suites do poorly
for a small service firm, and we sell them as one product:

| Job | Invoicing suites (QuickBooks, Xero, FreshBooks, Stripe Invoicing) | AR-automation tools (Chaser, Upflow, Paidnice…) | Payment-fraud tools (Trustpair, Eftsure…) | **PayFlow Guard** |
|---|---|---|---|---|
| Chase overdue invoices | Fixed reminder schedule, one tone, little per-client logic | Strong, but priced/positioned for finance teams | — | Cadence + **tone that escalates by days late and client behaviour**, per-client pause/dispute, late-fee suggestion from the contract, recipient-friendly opt-out |
| Predict who will pay late | Basic aging report | Some scoring (mid-market) | — | **Deterministic, explainable** late-payment probability per invoice + "systematically late" client class |
| Stop risky payouts (BEC, changed bank details) | Not addressed | Not addressed | Enterprise pricing, AP-department workflows | **Bank-change risk scoring, callback verification, cooling-off, insider-resistant four-eyes payouts**, priced for small teams and usable by freelancers |
| Answer "what does our contract say?" | — | — | — | **RAG assistant** over contracts, credit policy, templates, client history (Telegram + web), with citations |
| Where the team lives | Web app | Web/email | Web | **Telegram-first alerts and approvals**, bilingual RU/EN |

Positioning statement: *For digital agencies, dev studios, B2B service contractors and the freelancers they work with, who invoice clients
and pay contractors, PayFlow Guard is the collections autopilot and payout guard that sits next to the invoicing tool you already use.
Unlike invoicing suites it adapts reminders to each client's behaviour and makes risky payouts wait for a second person; unlike enterprise
AR/fraud tools it costs $0–99/month and works in Telegram.*

Consequences for scope (see ADR-0001): invoices can be **created** in PayFlow (lightweight, for teams without
a tool) **or imported** (CSV presets for QuickBooks, Xero, FreshBooks in MVP; API sync in v1). We never
become the ledger: no chart of accounts, no tax filing, no bank-feed reconciliation.

## 3. Ideal customer profile, personas, JTBD

**ICP (decided with the user, amended in revision 2):** primary — digital agencies, web/app development studios and small B2B service
contractors, 5–50 people, invoicing 10–400 invoices/month, paying 5–100 contractors/vendors (freelancers, subcontractors, SaaS), cross-border
clients (USD/EUR), Russian-speaking and/or English-speaking teams, already using Telegram. Secondary — **freelancers and 1–4 person studios**
(named in the brief): collections work unchanged; Payment Guard works through a seat-free *external approver* (accountant, partner) or the
*solo mode* with a trusted contact and extra delays (ADR-0011), so they never end up with payouts that cannot be approved.

| Persona | Role in app | Pains | Jobs to be done |
|---|---|---|---|
| **Anna — founder / managing director** | `owner` | Cash gaps before payroll; awkward chasing of long-time clients; afraid of a single fraudulent transfer wiping out a month's margin | "When cash is late, tell me early and chase politely but firmly so I don't have to." "Never let money leave without me or a trusted person checking it." |
| **Mark — finance manager / bookkeeper** (part-time or fractional) | `finance` | Copies the reminder email every 4–5 days, attaches PDF by hand; reconciles payments manually; vendor emails "our bank changed" | "Send the right reminder at the right time automatically and record payment the moment it lands." "Prepare payouts and get them approved quickly." |
| **Dmitry — operations lead / co-founder** | `approver` | Gets approval requests in random chats with no context | "Show me the payout, the risk and what changed, and let me approve in one tap — or stop it." |
| **Laura — account / project manager** | `member` | Doesn't know the client is 40 days late until finance escalates; relationship-sensitive | "Tell me which of my clients are late and let me nudge them in a tone that keeps the relationship." |
| **Sam — freelancer / solo studio owner** | `owner` (+ trusted contact or `external_approver`) | Pays subcontractors alone; nobody double-checks a "new bank details" email | "Make me pause and get a second confirmation before I pay new bank details." |
| **Ellen — external accountant** | `external_approver` (no seat) | Asked to "just check" payments over chat | "Show me what I'm approving and let me confirm it safely." |
| **Client AP clerk** (external payer) | public invoice page | Loses invoices in the inbox, needs PO numbers, wants to pay by card/link, dislikes aggressive dunning | "Give me a clear invoice, a one-click way to pay, and a way to say I've already paid." |

## 4. Problem evidence

| Evidence | Source | How we use it |
|---|---|---|
| 59% of small businesses have invoices overdue by more than 30 days; average outstanding amount $17.7k; 39% say a single late payment has already hurt their ability to pay salaries or bills | QuickBooks 2026 small-business late-payments research, as cited in the product brief | Sales deck and success-metric baselines. **Unverified**: the landing proof bar renders a statistic only when `config('marketing.stats_verified')` contains it with the exact report title and year (open issue). |
| Freelancers manually copy the reminder email, attach the invoice by hand and resend every 4–5 days | Reddit freelancer discussions (r/freelance and similar), as cited in the product brief | Default cadence design (4–5 day spacing after due date), "before/after" landing section. Quotes are paraphrased and anonymous; we don't present them as testimonials. |
| Business-email-compromise and "changed bank details" invoice fraud is the costliest cyber-enabled fraud category for businesses (FBI IC3 reports BEC losses in the billions of USD per year) | FBI IC3 annual Internet Crime Report (verify the latest figures before publishing) | Justifies Payment Guard; landing copy may cite it only with the exact report year (same `stats_verified` gate). |
| Market: incumbents (QuickBooks, Xero, FreshBooks, Stripe) cover invoicing; collecting client payments needs a payment provider | Product brief, PLATFORM §11 | ADR-0001 (narrow positioning), ADR-0013 (MVP = Stripe Payment Links on the workspace's own Stripe account; Connect = v2; YooKassa for RUB) |

## 5. Scope

Legend: **MVP** = production-grade and demoable end-to-end (M1–M6). **v1** = next 3 months after launch.
**v2** = later / needs partnerships.

### 5.1 Receivables (money in)

| Capability | MVP | v1 | v2 |
|---|---|---|---|
| Clients CRUD, contacts, terms, locale, **timezone** (send slots), tone ceiling, late-fee override, contract link, anonymise contact | ✓ | client portal (all invoices of a client) | |
| Invoices: create/edit drafts, line items, taxes (per-line %), multi-currency, numbering, PDF (RU/EN), send by email (PDF attached, sender name, Reply-To, legal footer), public invoice page | ✓ | discounts, recurring invoices, credit notes | e-invoicing formats (UBL/Peppol) |
| CSV import of clients & open invoices with presets (generic, QuickBooks, Xero, FreshBooks) + **reminder activation review** (nothing is sent before a human activates) | ✓ | QuickBooks Online / Xero API sync (read open invoices, write payments) | FreshBooks API |
| Payments: manual record (bank transfer, cash, other), partial payments, refunds from the provider | ✓ | bank statement CSV matching (suggested matches) | Open-banking feeds |
| Payment links through the foundation `PaymentGateway` (F3, ADR-0013): **Stripe Payment Links on the workspace's own Stripe account**, connected with a restricted API key (USD, EUR), **YooKassa** for RU merchants (RUB, 54-ФЗ receipts), **fake** driver offline | ✓ | more currencies per gateway | **Stripe Connect** (Standard, then Express marketplace), PayPal and other PSPs |
| Provider events through a **per-workspace webhook endpoint and secret** registered in the customer's Stripe account, YooKassa notifications (IP allow-list + re-fetch), dedupe, 30-minute reconciliation of open links, daily key/endpoint check | ✓ | | |
| Reminder policies (cadence steps relative to due date), send window in the client's timezone, business days, max reminders, pause/dispute, first-contact cap and throttle | ✓ | SMS / WhatsApp channel | Voice |
| Recipient choices: "I already paid" and "stop automated reminders" (one-click), privacy notice in every client e-mail | ✓ | | |
| Tone engine (friendly → neutral → firm → final) with explainable reasons; templates per tone & locale; AI wording helper (names never sent to the model) | ✓ | A/B testing of templates | |
| Late-payment probability per invoice + expected pay date + client late-payer class | ✓ | Model calibration report per workspace | ML model trained on cross-tenant anonymised data (opt-in) |
| Late-fee suggestion from contract terms (percent monthly/daily, fixed, grace, cap) and one-click apply | ✓ | Statutory interest presets (EU 2011/7/EU, ГК РФ ст. 395) | |
| Promise-to-pay tracking | | ✓ | |
| AR analytics: aging, DSO, collected vs invoiced, on-time trend, 8-week cash-in forecast, late payers, reminder effectiveness | ✓ | CEI, cohort analysis, export to Sheets | |

### 5.2 Payment Guard (money out)

| Capability | MVP | v1 | v2 |
|---|---|---|---|
| Vendors (contractors, freelancers, SaaS) with verified contact data, anonymise contact | ✓ | vendor self-service portal with 2FA for detail updates | |
| Versioned bank details (encrypted, fingerprinted, masked), change requests with source and raw request text | ✓ | Parse change requests from forwarded emails | |
| Bank-change risk scoring (lookalike domain, holder mismatch, country/currency change, shared account, urgency language, pending payout…) | ✓ | IBAN/BIC bank-registry lookup | Confirmation-of-payee / account-name-check APIs where available |
| Callback verification workflow with checklist, verifier ≠ requester, cooling-off period | ✓ | | |
| Payout requests with risk score (incl. **split detection**), required approvals (0/1/2) by amount, risk, recent changes and cumulative auto-approval cap; four-eyes enforced; auto-approved payouts released by someone else | ✓ | CSV/SEPA XML export of approved batch | Bank API initiation (PSD2 PIS) — only with licensed partner |
| **Insider controls:** privileged roles granted by owners only with step-up, 72 h trust delay, alerts to all owners; receiving-details changes versioned with second-person confirmation and a 24 h delay | ✓ | | |
| **Step-up with a second factor** (passkey / TOTP, 15 min) for every Guard decision; 2FA required for privileged users by default | ✓ | | |
| **Small teams:** seat-free `external_approver`; solo mode with a trusted contact (out-of-band e-mail confirmation + release delay); hard block with explanation when approvals are impossible | ✓ | | |
| Telegram approvals (stored-action buttons) with confirmation step; high-risk payouts web-only with step-up | ✓ | | |
| Guard alerts centre, escalation of stale approvals | ✓ | | |

### 5.3 Platform features used (from the foundation, configured for the domain)

| Foundation capability | PayFlow usage |
|---|---|
| Workspaces, roles, invitations | `config/permissions.php` + `WorkspaceRole` extended with `finance`, `approver`, `external_approver` (ranks, owner-only privileged grants via a role-grant hook) |
| Billing (fake/stripe), plans, `PlanLimits` | Plans `free/starter/pro/business`; foundation keys kept (`seats`, `documents`, `ai_queries_monthly`, `storage_mb`) + domain keys with registered resolvers + `entitlements` (§7) |
| Payments (F3, PLATFORM §11.2) | `PaymentGateway` (stripe restricted key + per-workspace endpoint / yookassa / fake), payment accounts, provider events |
| Outbox → worker | `email.send` v2 (cc, sender name, Reply-To, attachments by internal path, client-mail retry profile), Telegram notifications, imports |
| Internal API | `/internal/v1/payflow/*` (planner, dispatch, bot views/decisions — worker only; AI tools — RAG role (`ai` key) only, read-only) |
| Telegram linking & bot | `payflowModule: BotModule` with commands `/overdue`, `/due`, `/approvals`, `/changes`, `/client`, `/digest`, `/remind` and stored-action buttons (`pf.pa`, `pf.pr`, …) |
| Knowledge base + RAG + assistant preview | TypeScript RAG in the Node service (ADR-0014); contracts, credit policy, tone guide, bank-change procedure, client notes; 9 domain tools |
| AI admin, mock provider | Mock intent rules with multi-tool steps and a domain answer renderer for the 4 headline questions; deterministic demos and E2E |
| Audit log | All money and security actions (DOMAIN_CONTRACT B.11) |
| Privacy (F2) | Export contributors, workspace purge hook, retention keys, legal docs incl. `reminder-recipients` and `payment-guard-disclaimer` (DOMAIN_CONTRACT L) |
| Marketing shell | Rich landing, pricing, security and legal pages in RU/EN, light/dark |

## 6. MVP definition (what "demoable and production-grade" means)

A new agency — or a freelancer — can, without any third-party keys:
1. Sign up, create a workspace, invite a finance person and an approver (or an external approver / trusted contact when working alone).
2. Import open invoices from a QuickBooks/Xero/FreshBooks CSV, review and activate reminders, **or** create an invoice and send it.
3. The client opens the public invoice page and pays through the (fake, Stripe or YooKassa) payment link created on the workspace's own account; the provider event is
   verified by the worker; the invoice becomes Paid; the team gets a Telegram message and the client a receipt.
4. Overdue invoices receive reminders on schedule in the client's language and timezone window, with tone chosen by rules and shown with
   reasons; clients can say "already paid" or stop automated reminders; the dashboard shows aging, DSO, forecast and late payers.
5. A vendor "changes" bank details by email from a lookalike domain → high-risk alert → callback verification by a second (eligible)
   person with a second-factor step-up → cooling-off → payout needs two approvals → released with a reference by the right person; every
   step audited.
6. In Telegram (or the in-app preview) the team asks the four headline questions and gets cited answers.
With Stripe/YooKassa/Telegram/LLM keys configured, the same flows run against the real services.

## 7. Pricing & limits (maps to `config/billing.php` → `plans.<plan>.{price_*, limits, entitlements, features}`)

Prices in USD, plan keys fixed by the foundation. Yearly = 10× monthly (2 months free). Limits are `null` = unlimited.
**Security controls are never plan-gated:** four-eyes, bank verification, cooling-off, dual approval, step-up, audit log, solo mode and the
trusted contact work on every plan; only the *number* of external approvers is limited. Only volume and convenience features are gated.

Key names follow the foundation code: the foundation limit keys stay (`seats`, `documents`, `ai_queries_monthly`, `storage_mb` — the route
middleware already uses `plan.limit:ai_queries_monthly`), domain keys get resolvers through `PlanLimits::registerResolver()`, and gated
features are machine keys in a separate `entitlements` list checked by `PlanLimits::hasFeature()`. `features` stays the translated bullet
list shown on pricing cards (`PlanCatalog::features`). web-domain-backend owns the `plans` section and sets the values below — including
**business $99 / $990**, replacing the foundation placeholder $149.

| Key | free $0 | starter $19/mo ($190/yr) | **pro $49/mo ($490/yr)** (highlighted) | business $99/mo ($990/yr) |
|---|---|---|---|---|
| `limits.seats` (members + pending invites, **excluding** external approvers) | 2 | 3 | 10 | 50 |
| `limits.external_approvers` | 1 | 2 | 5 | 10 |
| `limits.clients` | 5 | 50 | 300 | null |
| `limits.invoices_per_month` (created or imported, calendar month in workspace tz) | 10 | 60 | 400 | null |
| `limits.vendors` | 3 | 20 | 100 | 500 |
| `limits.payouts_per_month` | 5 | 30 | 200 | null |
| `limits.documents` (foundation key) | 3 | 25 | 150 | 1000 |
| `limits.storage_mb` (foundation key) | 100 | 1024 | 10240 | 51200 |
| `limits.ai_queries_monthly` (foundation key; web **and** Telegram) | 30 | 300 | 2000 | 10000 |
| `limits.reminder_policies` | 1 | 2 | 10 | null |
| `limits.imports_per_month` | 0 | 2 | 20 | null |
| `entitlements`: `payment_links`, `telegram_bot`, `payment_guard`, `rag_assistant` | ✓ | ✓ | ✓ | ✓ |
| `entitlements`: `csv_import` | — | ✓ | ✓ | ✓ |
| `entitlements`: `risk_scoring` (late-payment probability, forecast) | — | ✓ | ✓ | ✓ |
| `entitlements`: `custom_templates` (edit reminder templates) | — | — | ✓ | ✓ |
| `entitlements`: `ai_drafts` (AI wording helper) | — | — | ✓ | ✓ |
| `entitlements`: `analytics_advanced` (forecast, reminder effectiveness, on-time trend) | — | — | ✓ | ✓ |
| `entitlements`: `audit_export` (CSV export of audit log) | — | — | — | ✓ |
| `entitlements`: `priority_support` | — | — | — | ✓ |

Rules: soft warning at 80 % of a limit (banner), hard stop at 100 % for **creation** only (foundation behaviour: back with an error flash +
upgrade prompt; JSON 402). Recording payments, sending reminders for existing invoices, approving/releasing payouts, bank verification and
trusted-contact confirmations are never blocked by limits. Downgrade keeps all data; creation is blocked until under limit. 14-day Pro trial
on first workspace (`subscriptions.status = trialing`), no card required with the fake driver. Stripe price ids come from env:
`STRIPE_PRICE_{STARTER,PRO,BUSINESS}_{MONTHLY,YEARLY}` (F3's `billing:sync-stripe` creates them by lookup key; prices in USD/EUR/RUB per
PLATFORM §11.1).

## 8. Success metrics

| Area | Metric | Target (first 90 days after beta) |
|---|---|---|
| North star | Cash collected through PayFlow-managed invoices per active workspace / month | Growing week over week; baseline set in beta |
| Collections outcome | Median days late of invoices with ≥1 reminder vs workspace's first-30-day baseline | −30 % |
| Collections outcome | % of overdue invoices paid within 14 days after first overdue reminder | ≥ 55 % |
| Collections outcome | DSO change after 60 days | −20 % |
| Collections conduct | Recipient opt-out rate per 1 000 reminders | < 5 (alert the workspace above 20) |
| Guard outcome | Payouts released to unverified or cooling-off details without required approvals | **0** (hard invariant, alerting) |
| Guard outcome | Privileged role grants approved by an eligible person within the trust delay without an owner alert | **0** (invariant) |
| Guard outcome | Median time from payout submit to final approval | < 4 h |
| Guard outcome | Bank changes verified/rejected within 24 h | ≥ 90 % |
| Activation | Workspace sends first invoice or activates imported invoices within 24 h of signup | ≥ 60 % |
| Activation | Telegram linked by at least one member within 7 days | ≥ 50 % |
| Activation | Payment account connected (Stripe restricted key, YooKassa or demo) within 7 days | ≥ 40 % |
| AI | Answers with ≥1 citation or tool result | 100 % of non-refused answers |
| AI | Refusal rate on in-scope questions (sampled review) | < 15 % |
| Reliability | Reminder dispatched within 15 min of scheduled time | ≥ 99 % |
| Reliability | Provider event → invoice paid latency p95 | < 30 s |
| Business | Trial → paid conversion | ≥ 8 % |
| Business | Logo churn | < 3 % / month |

Instrumentation: domain events are already persisted (`invoice_events`, `reminders`, `payout_approvals`,
`ai_queries`); a nightly `payflow:metrics` command writes daily aggregates to `payflow_daily_metrics`
(v1; MVP computes on the fly for the analytics page).

## 9. Milestones

| Milestone | Content | Exit criteria |
|---|---|---|
| **M0 — Foundation** (exists / in progress) | F1 integration (tenancy, roles, invitations, billing fake/stripe, outbox, internal API, Telegram linking, KB + RAG, assistant preview, AI admin, audit, marketing shell), **F2** privacy & legal, **F3** payments & markets (PLATFORM §11), **RAG port to the Node service** (ADR-0014, K.4 F18; `apps/ai` removed) | Foundation DoD (FOUNDATION_CONTRACT §5); F3 exposes `PaymentGateway` as pinned in DOMAIN_CONTRACT B.4.2 |
| **M1 — AR core** | Migrations, roles/abilities, `WorkspaceCreated` listener + backfill, invoice CRUD, numbering, PDF, `email.send` v2 (web + worker), public invoice page with legal footer, manual payments, per-pair internal keys + caller groups, demo seeder (base) | Pest green (incl. internal routing, permission matrix, email v2 contract test); invoice create → send (PDF attached) → public view → manual pay works in UI |
| **M2 — Payment links & provider events** (needs F3) | Payment links via `PaymentGateway` (fake, Stripe restricted key + per-workspace endpoint, YooKassa), Settings → Payments (permission checklist, verify, replace key), provider-event listener, sync job, receipts, Telegram "paid" notification, money-flow notice | E2E J1 and J3 green (en-light, ru-dark); stripe-mock Pest group green |
| **M3 — Collections engine & analytics** | Policies, templates + mandatory footer, planner/dispatch (client tz, spread, first-contact cap & throttle) with worker schedulers and the client-mail retry profile, recipient choices, tone engine, late fees, risk scoring, client classes, dashboard & analytics charts, CSV import with activation | E2E J2, J8, J9, J12 green; unit tests for every formula |
| **M4 — Payment Guard** | Vendors, versioned bank details, risk scoring (incl. split), verification flow, payouts, approvals (B.9.5 vectors), step-up with second factor, privileged-role grants + trust delay, receiving-details versions, external approver, solo mode, guard settings, alerts, Telegram stored-action buttons, escalations | E2E J4, J5, J13 green; invariants test-covered |
| **M5 — AI & bot** (needs the RAG port) | 9 domain tools (TypeScript) with `summary`, mock intent extension (multi-tool, anchored refusals, entity keywords, anaphora via memory), answer renderer, domain prompts, seed documents indexed, bot commands, assistant suggestions, Telegram AI quota | E2E J6 green; Vitest headline answers under mock and an LLM fake |
| **M6 — Brand, landing, privacy, hardening** | Brand tokens (superset migration), logo, landing/pricing/security/legal pages, retention/export/anonymise/pseudonymise (DOMAIN_CONTRACT L), DPIA/LIA template, a11y pass, responsive pass, Docker prod, CI e2e matrix, security review | Full E2E matrix + axe green; Lighthouse ≥ 90 perf/a11y on landing; no external requests; L.8 tests green |
| **M7 — Private beta** | 10 design-partner agencies + 5 freelancers, onboarding concierge, metrics dashboard | Activation metrics measured; top 10 feedback items triaged |
| v1 (3 months) | QBO/Xero sync, promise-to-pay, bank CSV matching, vendor portal, SEPA export, statutory interest presets, custom sending domain (DKIM) | |
| v2 | Open-banking feeds, confirmation-of-payee integrations, cross-tenant ML (opt-in), more PSPs | |

## 10. Risk register

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Crowded market; perceived as "another invoicing tool" | High | High | Narrow ICP and message (collections + payout guard), import-first onboarding, landing comparison is about jobs not features; never build ledger features |
| R2 | Client payments land on the wrong account | Medium | Critical | ADR-0013: links created with the workspace's own restricted key on its own account; events accepted only on that workspace's endpoint key and verified with its own secret (cross-workspace signature test in J3); the platform account never charges clients; fake driver otherwise |
| R3 | Lost/duplicated provider events → wrong invoice status | Medium | High | Signature verification / IP allow-list + re-fetch, `(driver, event_id)` dedupe in worker and web, `payments.provider_payment_id` unique, link `completed_sessions.limit=1`, 30-min sync job |
| R4 | False sense of security from Payment Guard | Medium | High | Non-absolute tagline; copy never claims "guaranteed"/"100 % safe"; `payment-guard-disclaimer` linked from every Guard surface; UI shows what was and wasn't checked; callback checklist is mandatory; audit trail |
| R5 | Telegram account takeover used to approve payouts | Low | Critical | High-risk/cooling-off/split payouts web-only with second-factor step-up; approvals bound to amount + bank fingerprint; stored-action buttons bound to the recipient; requester can't approve; per-workspace toggle to disable Telegram approvals; alerts to all approvers |
| R6 | Insider changes bank details or adds a sockpuppet approver and approves alone | Low | Critical | ADR-0011: privileged roles granted by owners only with step-up, 72 h trust delay before a new approver/verifier counts, critical `guard_role_granted` alert to all owners and approvers, 2FA required, approvals invalidated when a member is removed; verifier ≠ requester (DB CHECK), approver ≠ requester, auto-approved payouts released by someone else, cumulative auto-approve cap, split detection; receiving-details changes need a second person and 24 h; guard settings owner-only + broadcast alert. J4 negative tests. |
| R7 | Bank data leak | Low | Critical | Encrypted columns (display vs reveal-only classes), HMAC fingerprints, masked by default, reveal = permission + step-up + audit; never in logs, Telegram, AI (no holder names) or e-mails |
| R8 | Reminder emails flagged as spam / bounce | Medium | Medium | Transactional templates, plain-text part, Reply-To = workspace email, sender display name, RFC 8058 one-click unsubscribe header, rate limits per client, first-contact throttle, retries over ≈ 22 h for 4xx, bounce → reminder failed → Telegram alert; v1 custom DKIM domain |
| R9 | Over-aggressive tone damages client relationship | Medium | Medium | Per-client tone ceiling, first automated contact capped at `neutral`, import activation review, pause, dispute state, recipient opt-out, preview before manual send, reasons shown, respectful templates |
| R10 | Late fees not enforceable / illegal for a jurisdiction | Medium | Medium | Fees only suggested from configured contract terms; applying is a manual, audited action; legal note in UI |
| R11 | LLM hallucinates policy or numbers | Medium | High | Tools-first, RAG with confidence gate and citations, numeric guard on AI drafts, mock provider default, anchored refusal rules with negative tests |
| R12 | Timezone/date errors in reminders and aging | Medium | Medium | Business dates in workspace tz with a single `WorkspaceClock`; send slots in the client's tz; vectors across DST and a New York client; `now` injectable in all planners (dev/e2e only) |
| R13 | Multi-currency confusion | Medium | Medium | No FX conversion (ADR-0007); analytics per currency; thresholds per currency; CLDR-exact formatting shared by server and UI |
| R14 | Personal-data regulation (GDPR, 152-ФЗ) | Medium | High | MVP privacy scope (DOMAIN_CONTRACT L): retention table, export manifest, anonymise contact, actor pseudonymisation, recipient notice and opt-out, view-tracking toggle, AI minimisation, DPIA/LIA template, DPA + sub-processors; RU hosting region decision before selling to RU-resident companies (open issue) |
| R15 | Free tier abuse for spam emails | Low | Medium | Email limits per plan, per-recipient rate limit, verified email required to send, opt-out monitoring |
| R16 | Single-maintainer complexity (3 services) | Medium | Medium | Strict contracts (DOMAIN_CONTRACT incl. K.4), shared signing vectors, shared outbox schema samples, CI matrix, mock drivers |
| R17 | Leaked or over-privileged restricted keys / webhook secrets | Low | High | ADR-0013: restricted keys only (`sk_` refused), permission checklist, encrypted reveal-only storage, never exported/logged/sent to Telegram or AI, secret cached only in worker memory, daily verification; the key cannot move funds (no payouts/transfers scopes) and the customer can revoke it any time |
| R18 | Mass first contact after an import or policy change (final notices to a whole AR ledger) | Medium | High | Imported invoices start paused; activation review with tone counts; first automated reminder capped at `neutral` unless a human confirms; ≤ 50 first contacts per hour |
| R19 | Shared internal secret lets a compromised service act as another | Low | Critical | ADR-0010 per-pair keys + caller-scoped routes; RAG as a separate container in production and keyless parser sandbox (ADR-0014); AI tool endpoints read-only |
| R20 | RAG port to TypeScript (foundation) lags and blocks M5 | Medium | Medium | M1–M4 don't depend on it; rag-domain codes against the G.2 interfaces with Vitest doubles; `/v1` contract fixtures shared with the port |

## 11. Legal & privacy, payments & markets (PLATFORM §0.8, §10, §11; details: DOMAIN_CONTRACT L, B.4)

### 11.1 Legal & privacy coverage

| Item | What PayFlow adds on top of the foundation (F2) | Where | Owner |
|---|---|---|---|
| Product legal docs (RU + EN, versioned, template banner until `LEGAL_REVIEWED=true`) | new `reminder-recipients` and `payment-guard-disclaimer`; PayFlow sections in `terms` (money flow on the customer's own account, collections conduct, data lawfulness), `privacy` (processor role, view tracking, late-payer scoring), `dpa` annex, `subprocessors` (Stripe, YooKassa, Telegram, LLM providers only when enabled), `aup`, `ai` | DOMAIN_CONTRACT L.1 | web-domain-backend |
| Export | per-table manifest; bank details masked unless the owner exports with step-up (audited); keys and webhook secrets never exported | L.3 | web-domain-backend |
| Deletion | workspace purge hook (deactivate links, delete the Stripe webhook endpoint, disconnect, delete files); anonymise client/vendor contact; actors of financial records pseudonymised, never hard-deleted | L.3–L.5 | web-domain-backend |
| Retention | `payflow.*` keys (provider payloads 30 d, reminder bodies 365 d, import files 30 d, PDF cache 7 d, approval IP/UA 365 d, …) enforced by `maintenance:purge` with audit counts | L.2 | web-domain-backend + worker-domain (scheduler) |
| PII redaction | AI tool output: no [enc] values, holder name → `holder_matches_vendor`; PLATFORM §10.2 redaction when `ai_external_allowed`; AI drafts use `[[GREETING]]`/`[[SENDER]]` placeholders; Telegram gets last4 + amounts only | F.3, G.5, L.6 | web-domain-backend, rag-domain |
| Notices & disclaimers | footer of every client e-mail and public invoice page (sender identity, controller privacy contact, `reminder-recipients`, "already paid / stop automated reminders", RFC 8058 one-click); Guard surfaces link the disclaimer; Settings → Payments money-flow notice; `/privacy` + first-contact notice in Telegram (foundation) | B.6.6, B.4.6, L.1, L.6 | web-domain-backend, web-domain-ui |
| DPIA / LIA | template for bank-change fraud scoring and late-payer profiling | `docs/legal/DPIA-LIA-template.md`, L.7 | product architecture |
| Icons only (§0.8) | no emoji/stickers in UI, e-mails, PDFs, Telegram texts **and buttons**, notification templates, seed data, legal docs; Lucide in the UI, `simple-icons` for brand marks; emoji-scan tests in Pest and Vitest | DOMAIN_CONTRACT §0, H.3 | all packages |

### 11.2 Payments & markets (ADR-0013)

- **SaaS billing:** foundation F3 (Stripe Checkout + Portal primary, YooKassa for RUB, fake offline); PayFlow only sets plan values (§7).
- **Client payments, exactly as the brief says:** MVP = **Stripe Payment Links on the workspace's own Stripe account**, connected with a
  **restricted API key** (permission checklist, verified on connect), a **per-workspace webhook endpoint and signing secret** registered in that
  account (worker route `/webhooks/stripe/accounts/{endpoint_key}`), idempotent event handling and a 30-minute **reconciliation** of open links.
  **Stripe Connect is v2** behind the same `PaymentGateway`.
- **RU market readiness:** **YooKassa** with the merchant's own shop credentials (RUB, 54-ФЗ receipts, notifications re-fetched), chosen by
  workspace market/currency; RU legal set (public offer, 152-ФЗ consent) and `DATA_REGION=ru` deployment note from PLATFORM §11.4.
- Currencies with payment links: USD/EUR (Stripe), RUB (YooKassa); other currencies get bank-transfer details only, and the UI says why.

### 11.3 Legal & compliance notes

- **We don't move money.** PayFlow Guard never initiates bank transfers (no PSD2 PIS, no money transmission). Payouts are approvals + a
  record that the customer executed the transfer in their own bank. Client payments are Payment Links on the customer's **own** Stripe
  account (restricted key) or their own YooKassa shop; card data never touches our servers (Stripe/YooKassa-hosted pages, PCI scope SAQ-A).
- **Money flow.** Refunds, disputes and provider fees stay between the customer and its own Stripe/YooKassa account; PayFlow is not a
  Connect platform in the MVP and carries no balance liability (ADR-0013, ADR-0008). Stripe Connect (v2) must re-open the liability question.
  The restricted key the customer gives us cannot create payouts or transfers.
- **No guarantees.** Marketing and UI say "helps detect / reduces risk / adds a verification step", never "guaranteed", "fraud-proof",
  "100 % safe", "never pay a fraudster". The `payment-guard-disclaimer` (RU+EN) states that decisions remain the customer's.
- **Data protection.** Client and vendor contacts and bank details are personal data when they belong to sole proprietors. GDPR: we are
  processor for workspace data; DPA annex, sub-processor list (hosting, e-mail, Stripe (platform billing; client payments run in the customer's own account), YooKassa for RU merchants, Telegram if
  linked, each LLM provider if enabled), retention schedule (DOMAIN_CONTRACT L.2), export, anonymisation and pseudonymisation flows
  (L.3–L.5), DPIA/LIA template for bank-change fraud scoring and late-payer profiling (`docs/legal/DPIA-LIA-template.md`). Russia (152-ФЗ):
  personal data of RU citizens must be initially recorded in databases located in Russia — selling to RU-resident companies requires an RU
  deployment (`DATA_REGION=ru`, PLATFORM §11.4; open issue). Encryption at rest for bank data, access logging (audit).
- **Reminder recipients.** PLATFORM §10.2 applies in full: every invoice, reminder and receipt e-mail carries the sender identity, the
  controller's privacy contact, a link to `/legal/reminder-recipients` and an "I already paid / stop automated reminders" link (plus RFC 8058
  one-click). Stopping pauses automated reminders for that client and notifies the client owner and finance; invoices and manual reminders
  stay possible. View tracking and payment-behaviour scoring are disclosed and view tracking can be turned off.
- **AI.** LLM providers are optional and configurable; with the mock provider no data leaves the deployment. When a cloud LLM is configured
  and allowed (`ai_external_allowed`), tool output passes PII redaction, bank holder names are never sent, AI drafts use placeholders for
  personal names; answers are labelled as AI-generated and cite sources.
- **Collections conduct.** B2B first-party reminders are not consumer debt collection (FDCPA / 230-ФЗ target consumer debts), but templates
  stay factual and respectful, no threats, max reminder count, quiet hours (send window in the client's timezone), business days only by
  default, first automated contact never firmer than `neutral` without a human decision.
- **Late fees** must be agreed in the contract (EU Late Payment Directive 2011/7/EU allows statutory interest and €40 compensation in B2B;
  Russia: contractual неустойка, ГК РФ ст. 330, or interest under ст. 395). PayFlow only suggests the configured contractual fee; applying it
  is explicit and audited.
- **Email.** Transactional messages only; sender display name = workspace sender name, address = platform domain, Reply-To = workspace;
  physical address of the workspace in the footer.
- **Telegram.** No full bank details, holder names, invoice PDFs or reminder bodies in Telegram; only masked last4 and amounts. No emoji or
  stickers (PLATFORM §0.8).
- **Records.** Financial records (invoices, payments, payouts, approvals) are never hard-deleted; voided/canceled instead; personal parts are
  trimmed by retention or anonymisation. Users referenced by such records are pseudonymised on account deletion. Drafts can be deleted
  (soft delete).

## 12. Definition of done (production)

A feature is done when:
1. It follows `docs/DOMAIN_CONTRACT.md` exactly (names, props, payloads) or the contract was updated first.
2. Backend: Pest feature tests for happy path, permission denial (each of the 6 roles), workspace isolation (incl. the two-workspace
   internal-API cases of F.0), validation errors (RU + EN messages), and every state transition (allowed + forbidden); unit tests for every
   formula (risk, tone, late fee, planner, approvals, money rounding and formatting) with table-driven cases from the contract.
3. Worker: Vitest for routing, idempotency (same jobId twice; job ids accepted by a real BullMQ queue), signature verification (valid,
   invalid, stale, wrong pair key), handlers via the dev transformer for bot commands and stored actions, email v2 samples.
4. AI (TypeScript, Vitest): each tool (schema, permission refusal, output masking, no holder names), mock intents (RU + EN positive and negative
   examples from the contract), headline answers under mock and an LLM fake, domain prompt loaded per locale.
5. UI: all strings via translation keys (RU plural checked), light + dark designed, empty/loading/error states, keyboard accessible, axe
   clean, 375/768/1024/1440 without horizontal scroll, charts have a table alternative, no emoji (scan).
6. Audit entries for every action listed in DOMAIN_CONTRACT B.11; no secrets or bank data in logs.
7. Privacy: retention key, export coverage and anonymisation behaviour defined for every new table (DOMAIN_CONTRACT L).
8. Works offline with fake/mock drivers; E2E journey for the feature green in its project/tag matrix.
9. `php artisan test`, `vue-tsc`, `npm run lint`, worker `npm test` (incl. `test/rag/**`), `npm run build`, Playwright matrix all green in CI (no Python toolchain).
10. Docs updated (contract, ADR if a decision changed, README section).

Release readiness (production): Docker images build non-root; `docker-compose.prod.yml` boots web + worker (`jobs,bot,ingress`) + rag (same image, internal only); health checks green;
backups for Postgres configured; `APP_DEBUG=false`; CSP enforced; secrets from env only — three internal pair keys (split per container, ADR-0014), platform `STRIPE_WEBHOOK_SECRET`,
YooKassa IP allow-list current; no dev routes (`DEV_ROUTES` unset); Bull Board behind auth; error alerting configured; data-retention job
scheduled; Terms/Privacy/DPA/Sub-processors/reminder-recipients/payment-guard-disclaimer published in RU/EN and reviewed by a lawyer
(`LEGAL_REVIEWED=true`).
