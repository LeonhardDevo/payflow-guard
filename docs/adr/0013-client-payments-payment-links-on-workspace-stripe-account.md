# ADR-0013: Client payments — Stripe Payment Links on the workspace's own Stripe account (restricted key), YooKassa for RUB, Connect in v2

- **Status:** Accepted — **supersedes ADR-0009**, reinstates ADR-0002 in amended form (behind the foundation `PaymentGateway`)
- **Date:** 2026-10-22
- **Deciders:** founder (PLATFORM.md §11.2 as rewritten on 2026-10-22: "exactly as the product brief says"), product architecture

## Context

The brief says: *for MVP, SaaS billing + Stripe Payment Links are enough; a full marketplace needs Stripe Connect.* An earlier
version of PLATFORM §11 prescribed Stripe Connect Express, and ADR-0009 followed it. PLATFORM §11.2 now prescribes, for PayFlow:
each workspace connects **its own Stripe account** with a **restricted API key**; PayFlow creates per-invoice Payment Links there,
registers a webhook endpoint in that account with its own signing secret, the worker verifies with that per-workspace secret, a
reconciliation job re-fetches open links every 30 minutes; **Stripe Connect is the documented v2 path behind the same
`PaymentGateway`**. YooKassa (merchant's own shop) stays the RUB driver; `fake` stays the offline default.

## Decision

1. **Interface unchanged from ADR-0009:** domain code calls only `App\Payments\PaymentGateway` (`supports`, `createPaymentLink`,
   `deactivate`, `listCompletedSessions`) and `PaymentAccountService` (connect, verify, disconnect), delivered by foundation step F3.
   Shapes: DOMAIN_CONTRACT B.4.2 / K.4 F3.
2. **Stripe driver = restricted key on the workspace's own account.** Settings → Payments asks for an `rk_test_…`/`rk_live_…` key
   (mode from the prefix). Required permissions (shown as a checklist, verified by test calls): Products **write**, Prices **write**,
   Payment Links **write**, Checkout Sessions **read**, PaymentIntents **read**, Charges & Refunds **read**, Webhook Endpoints **write**.
   The key is stored encrypted (`credentials` [enc:reveal]); the UI shows `…last4` only.
3. **Per-workspace webhook endpoint.** On connect F3 creates a webhook endpoint in the customer's account pointing to
   `{WORKER_PUBLIC_URL}/webhooks/stripe/accounts/{endpoint_key}` (`endpoint_key` = random 32 chars, unique) for
   `checkout.session.completed`, `checkout.session.async_payment_succeeded|failed`, `payment_intent.succeeded|payment_failed`,
   `charge.refunded`, `payment_link.updated`; the returned signing secret is stored encrypted (`webhook_secret` [enc:reveal]).
   The worker resolves `endpoint_key` → `{payment_account_id, workspace_id, webhook_secret}` through a **worker-only** internal
   endpoint, caches it in process memory for 5 minutes (never Redis, never logs), verifies the signature (tolerance 300 s) before
   answering 2xx, dedupes by `(stripe, event.id)` and forwards. Unknown key → 404; bad signature → 400.
4. **Payment Links are created on the customer's account** (no `Stripe-Account` header, no platform fee), one per invoice balance,
   `restrictions.completed_sessions.limit = 1`, metadata `pf_invoice_id`/`pf_workspace_id`, redirect to the public thanks page.
5. **Reconciliation:** `payments:sync-all` every 30 min calls `listCompletedSessions` for open links (lost webhooks). A daily
   `payments:verify-accounts` re-checks key validity and the endpoint (`GET /v1/webhook_endpoints/{id}`): 401/403 or a missing/disabled
   endpoint → account `restricted`, alert `payment_account_restricted`, new links paused until the key is replaced ("Reconnect").
6. **YooKassa (RUB)** unchanged from ADR-0009 §4: merchant's own shopId + secret key, lazy single payments with redirect confirmation,
   54-ФЗ `receipt` when `YOOKASSA_RECEIPTS=true`, notifications accepted only from YooKassa IP ranges and **re-fetched**.
7. **Fake driver** (default offline/CI/E2E): F3's fake account gets an `endpoint_key` and a generated `whsec_fake_…` secret; the fake
   checkout posts Stripe-shaped, correctly signed events to the **same** worker route, so signature, dedupe and routing are the
   production path. The Stripe driver is integration-tested in Pest against `stripe/stripe-mock` (port 12111).
8. **Disconnect** deactivates active links (best effort), deletes the webhook endpoint in the customer's account (best effort), nulls
   key and secret. **v2 — Stripe Connect** (Standard first, so the merchant carries its own losses) as a second Stripe mode of the same
   driver; no domain change needed.

## Consequences

- ADR-0008 holds in full again: money settles directly in the customer's own Stripe account; refunds, disputes and fees are the
  customer's relationship with Stripe; **the platform carries no Connect negative-balance liability** (risk R17 of revision 2 removed,
  alert `payment_account_negative_balance` removed).
- Customers handle a restricted key: the settings page lists exact permissions, verifies them and explains revocation. Keys and
  per-workspace secrets are high-value data: encrypted, reveal-only, never exported, never sent to Telegram/AI/logs.
- The worker needs a small, worker-only secret lookup (DOMAIN_CONTRACT F.1 #12); ADR-0010 keeps it unreachable for the RAG role.

## Alternatives considered

- **Connect Express (ADR-0009)** — rejected for MVP by the user decision; contingent platform liability, KYC flows.
- **Customers paste their own Payment Link URLs** — rejected: no status sync, no amount control.
- **Platform account** — rejected: money transmission (ADR-0008).
