# ADR-0002: Client payments via Stripe Payment Links on the workspace's own Stripe account; Connect later

- **Status:** **Reinstated in amended form by [ADR-0013](0013-client-payments-payment-links-on-workspace-stripe-account.md)**
  (2026-10-22): same restricted-key + per-workspace endpoint design, but implemented behind the foundation `PaymentGateway` (F3),
  with YooKassa for RUB and Connect as v2. ADR-0013 is binding where the two differ (permissions list, Connect timing).
- **Date:** 2026-10-22

## Context

The brief separates two Stripe concerns: billing the SaaS itself (Stripe Billing/Checkout — foundation) and accepting the agency's client
payments. It states that Stripe Payment Links are enough for the MVP and that a marketplace would need Stripe Connect.
If Payment Links were created on the **platform's** Stripe account, client money would land with us — legally a money-transmission problem
and a trust problem. Stripe Connect onboarding (OAuth, KYC flows, platform liability) is heavier than an MVP needs.

## Decision

1. Each workspace connects **its own Stripe account** by pasting a **restricted key** (recommended permissions: Payment Links write,
   Prices write, Products write, Checkout Sessions read, Webhook Endpoints write, Account read). The key is stored encrypted.
2. On connect, PayFlow creates a **webhook endpoint in that account** pointing to the worker URL
   `/webhooks/stripe/accounts/{endpoint_key}` and stores the returned signing secret (encrypted). `endpoint_key` is random (32 chars) and maps
   to the workspace.
3. Payment Links are created per invoice balance with `restrictions.completed_sessions.limit = 1`, metadata `pf_invoice_id`/`pf_workspace_id`,
   and an after-completion redirect to the public "thanks" page. A changed balance deactivates the old link and creates a new one.
4. The worker verifies signatures with the per-workspace secret, dedupes by `event.id`, and forwards events to web; web dedupes again and
   records payments idempotently by `payment_intent`. A 30-minute sync job reconciles via `checkout.sessions.list` for lost webhooks.
5. A **fake provider** with the same webhook path (signed with a per-workspace fake secret) powers demos and E2E without keys.
6. Stripe Connect Standard (OAuth) is v1; Connect Express marketplace is v2.

## Consequences

- Money always goes straight to the agency's account; PayFlow never holds funds (see ADR-0008).
- Agencies without Stripe can still use PayFlow (bank-transfer details on invoices + manual payment recording).
- Restricted-key onboarding is a manual step; the settings page lists exact permissions and verifies them with a test call.
- Per-workspace secrets must be cached carefully in the worker (in-process LRU only, never Redis/logs).

## Alternatives considered

- **Platform account + manual payouts to agencies** — rejected (holding client funds).
- **Connect from day one** — rejected for MVP (KYC flows, platform responsibilities, longer time to demo).
- **Agencies paste their own Payment Link URLs** — rejected: no reliable status sync, no amount control.
