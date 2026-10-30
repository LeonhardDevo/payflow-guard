# ADR-0015: SaaS billing by market — Stripe catalog by lookup key, gateway per workspace

- **Status:** Accepted — implements PLATFORM.md §11.1–11.2 (foundation step F3)
- **Date:** 2026-10-23

## Context

Subscriptions must work with real Stripe (test mode) for EU/US customers and be ready for the Russian market, where Stripe
is unavailable and YooKassa is used. The offline `fake` driver stays the default for development, CI and E2E. Price ids
configured by hand per environment (`STRIPE_PRICE_*`) do not scale to plan × interval × currency and drift between accounts.

## Decision

1. **Market per workspace.** Onboarding stores `workspaces.country` next to `workspaces.currency`. `MarketResolver` maps it to
   a billing currency and a gateway: `RU` or `RUB` → `yookassa`, everything else → `stripe` with USD or EUR (EUR for euro-area
   countries unless the workspace chose USD). The mapping lives in `config/billing.php` (`markets.*`).
   `BILLING_DRIVER` selects the mode: `fake` (offline), `market` (per workspace), or `stripe` / `yookassa` (forced for all).
2. **Gateways are resolved, not bound.** `BillingGateways` resolves the driver class from `billing.gateways.<name>`; a missing
   class (YooKassa not installed yet) surfaces as `gateway_unavailable`, never a crash. The `BillingGateway` interface is
   unchanged; drivers that can change an existing subscription additionally implement `ManagesSubscriptions`
   (`changePlan`, `cancelAtPeriodEnd`, `resume`). `PaymentGateway` (one-off client payments) is defined for PayFlow's domain
   work with `Money` (integer minor units + ISO currency), `PaymentRequest` and `PaymentLink`.
3. **Stripe catalog by lookup key.** `php artisan billing:sync-stripe` creates Products `<catalog>_<plan>` and Prices with
   `lookup_key = <catalog>_<plan>_<interval>_<currency>` (`transfer_lookup_key`, old price archived on change) plus a Customer
   Portal configuration tagged with `metadata.catalog`. `<catalog>` defaults to the product slug, so several products can share
   one Stripe account. Checkout resolves the price id by lookup key (cached), webhooks map prices back by lookup key, which also
   keeps plan changes made in the Portal correct when subscription metadata is stale.
4. **State only from webhooks.** Controllers call Stripe and redirect; `StripeEventProcessor` (behind worker ingress, idempotent by
   event id, order-tolerant by event `created`) is the only writer of `subscriptions`. Dunning: `invoice.payment_failed`
   (renewals only) → `past_due` + `payment_failed_at` + e-mail to members with `billing.manage`; `invoice.paid` → `active`;
   `customer.subscription.trial_will_end` → reminder e-mail. The past-due / trial-ending banner is a shared Inertia prop.
5. **Every Stripe write carries an `Idempotency-Key`**; customers use a deterministic key per workspace.
6. **Testing.** `stripe/stripe-mock` (compose service, port 12111 for PayFlow) validates the real SDK calls of `StripeGateway`
   and the sync command; signed fixtures in `contracts/stripe/events/` drive both the worker ingress tests and the web state
   machine tests; the `stripe-live` Playwright project runs only with `E2E_STRIPE_LIVE=1` and an `sk_test_` key.

## Consequences

- Adding a currency or plan is a config change followed by `billing:sync-stripe`; no price ids in env files.
- The YooKassa driver plugs in by providing `App\Billing\YooKassa\YooKassaGateway` (and optionally `ManagesSubscriptions`),
  writing `subscriptions.gateway = 'yookassa'` and reusing `BillingNotifier` for the same dunning e-mails.
- A workspace that changes its country keeps its live subscription currency until it re-subscribes.
