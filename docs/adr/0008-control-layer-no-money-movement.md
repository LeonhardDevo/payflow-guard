# ADR-0008: PayFlow Guard is a control layer — it never moves or holds money

- **Status:** Accepted — ADR-0009 amendment withdrawn by ADR-0013 (client payments settle in the customer's own Stripe account / YooKassa shop; no platform liability)
- **Date:** 2026-10-22

## Context

Payment Guard prepares, scores and approves vendor payouts. Executing transfers through bank APIs (PSD2 payment initiation, ACH origination)
would require licences or a licensed partner, bank-specific integrations and much higher liability. Holding client funds (platform Stripe account)
would make us a money transmitter.

## Decision

1. PayFlow never initiates bank transfers and never holds funds. A payout's terminal success state is `released`: a person with
   `payouts.release` confirms they executed the approved transfer in their bank and records the bank reference.
2. Client payments go directly to the workspace's own payment account — Payment Links on the customer's own Stripe account
   (restricted key, no platform fee) or the merchant's own YooKassa shop (ADR-0013) — or by bank transfer to details printed on
   the invoice. Funds never pass through a platform balance.
3. Product copy and the ToS describe PayFlow as a tool that *helps detect risk and enforce approvals*, never as a guarantee against fraud.
4. Refunds, disputes, fees and any negative balance are between the customer and its own provider account; PayFlow is not a
   Connect platform in the MVP and carries no balance liability. Stripe Connect (v2) must re-open this point before it ships.
5. v1 adds CSV/SEPA XML export of approved batches (still executed by the customer); v2 may integrate a licensed payment-initiation partner.

## Consequences

- Lower regulatory burden and liability; faster MVP.
- A gap remains between "approved" and "paid in bank" — mitigated by showing last4 at release time and requiring the bank reference.
- We cannot technically prevent a user from paying outside PayFlow; the audit trail and alerts make bypasses visible.

## Alternatives considered

- **Bank API payouts** — deferred (licensing, partners).
- **Stripe Connect payouts to vendors** — rejected (vendors are not Stripe users; wrong product).
