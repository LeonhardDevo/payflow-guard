# ADR-0009: Client payments through the foundation PaymentGateway (Stripe Connect Express, YooKassa, fake)

- **Status:** **Superseded by [ADR-0013](0013-client-payments-payment-links-on-workspace-stripe-account.md)** (2026-10-22) — PLATFORM §11.2 was rewritten to the brief's MVP (Payment Links on the workspace's own Stripe account, Connect = v2). Kept for history; the `PaymentGateway` interface, YooKassa and fake-driver parts live on in ADR-0013
- **Date:** 2026-10-22
- **Deciders:** product architecture, founder (user decision recorded in PLATFORM.md §11)

## Context

ADR-0002 let each workspace paste a restricted Stripe key and created a webhook endpoint inside the customer's own Stripe
account. PLATFORM.md §11 (added 2026-10-22, binding) decides differently for all three products: client payments that a
customer collects go through a `PaymentGateway` interface with drivers `stripe`, `yookassa` and `fake`, delivered by the
foundation step **F3**. For PayFlow the Stripe driver is **Stripe Connect Express**: Account Links onboarding,
`account.updated` gates `charges_enabled`, direct charges on the connected account, and **one** Connect webhook endpoint
(`STRIPE_CONNECT_WEBHOOK_SECRET`). RU merchants use YooKassa (their own shop credentials, notifications that are not signed
and must be re-fetched, 54-ФЗ receipts). Steps other than F3 must not implement provider code themselves.

Connect Express has a liability consequence that ADR-0002 did not have: for Express accounts Stripe requires
`controller.losses.payments = application`, i.e. **the platform is liable for connected-account negative balances**
(refunds and chargebacks that the connected account cannot cover). Only accounts with full Stripe Dashboard access
(Standard) can carry their own losses.

## Decision

1. **Domain code never calls stripe-php or the YooKassa API.** It calls `App\Payments\PaymentGateway`
   (`supports`, `createPaymentLink`, `deactivate`, `listCompletedSessions`) and `App\Payments\PaymentAccountService`
   (onboarding, refresh, disconnect) delivered by F3. Their exact shape is pinned in DOMAIN_CONTRACT B.4.2 / K.4 F3.
2. **One payment account per workspace** (`payment_accounts`, F3-owned): `driver` `stripe | yookassa | fake`,
   `external_account_id` (`acct_…`, YooKassa shopId, `acct_fake_…`), `charges_enabled`, `status`, `credentials` [enc] only
   for YooKassa. Provider events are deduplicated by `(driver, event_id)` in the worker **and** in web.
3. **Stripe (USD, EUR):** Express account created by F3 with the workspace's country; onboarding/re-onboarding through
   Account Links from Settings → Payments; Payment Links are created **on the connected account** (`Stripe-Account`
   header, direct charges, `application_fee_amount` 0 by default). All Connect events arrive at the single worker endpoint
   `/webhooks/stripe/connect` and are routed by `event.account` → `payment_accounts.external_account_id` → workspace.
   The per-workspace webhook endpoint, `endpoint_key` and the restricted key of ADR-0002 are dropped. Handled events:
   `checkout.session.completed`, `checkout.session.async_payment_succeeded|failed`, `payment_intent.succeeded|payment_failed`,
   `charge.refunded`, `payment_link.updated`, `account.updated`.
4. **YooKassa (RUB):** the merchant's own shopId + secret key (encrypted). A payment with redirect confirmation is created
   lazily when the client presses "Pay" on the public invoice page (`receipt` attached for RUB when
   `YOOKASSA_RECEIPTS=true`). Notifications arrive at `/webhooks/yookassa`, are accepted only from YooKassa's published IP
   ranges, routed by `object.recipient.account_id` and **re-fetched** with the shop's credentials before they are trusted.
5. **Currencies per gateway:** Stripe USD/EUR, YooKassa RUB, fake all three. Invoices in other currencies have no payment
   link (bank-transfer details only); the UI says why.
6. **Fake driver** (default offline, CI, E2E): F3's fake checkout signs Stripe-shaped Connect events
   (`account: acct_fake_…`) with the Connect secret and posts them to the real worker ingress, so the production path
   (signature, dedupe, routing, domain listener) is exercised end to end. The Stripe driver itself is integration-tested
   in Pest against `stripe/stripe-mock` (port 12111) — stripe-mock cannot emit webhooks and returns external URLs, so it is
   not used in Playwright.
7. **Liability controls** (because of Express): ToS clause making the customer responsible for refunds, chargebacks,
   provider fees and negative balances of their connected account, with reimbursement on demand; `debit_negative_balances`
   enabled on connected accounts where Stripe supports it; alerts `payment_account_restricted` (`requirements.disabled_reason`
   set or `charges_enabled = false`) and `payment_account_negative_balance` (F3 daily balance reconciliation) that block new
   payment links for that workspace until resolved; the Settings → Payments page and the landing FAQ state the arrangement
   in plain words. YooKassa and the fake driver carry no platform liability (merchant's own shop / no money).

## Consequences

- One webhook secret per platform instead of one per workspace; no secrets of customers' Stripe accounts are stored.
- Customers onboard with Stripe's hosted KYC instead of creating restricted keys — simpler activation.
- The platform carries a **contingent liability** for unrecoverable negative balances of Express accounts; ADR-0008's
  "we never hold funds" stays true (direct charges settle into the connected account), but "we carry no liability" no
  longer does. Recorded as risk R17; the user must accept it or switch the Stripe driver to Standard accounts through an
  explicit "overrides PLATFORM.md §11" decision (open issue).
- Milestone M2 (payment links) depends on F3; until then the domain codes against the interface and uses a test double in
  Pest only (no production driver code outside F3).

## Alternatives considered

- **Keep ADR-0002 (BYO restricted key, per-workspace endpoint)** — rejected: contradicts PLATFORM §11 and requires
  customers to handle API keys.
- **Connect Standard** — merchant bears its own losses and has the full Dashboard, but onboarding is heavier and §11 chose
  Express; kept as the fallback if the user rejects the Express liability.
- **Destination charges / platform account** — rejected: funds would flow through the platform (money-transmission and
  trust problem, ADR-0008).
