# ADR-0001: Position PayFlow Guard as a collections autopilot + payout guard on top of existing invoicing

- **Status:** Accepted — amended 2026-10-22 (ICP includes freelancers and 1–4 person studios through the Guard solo mode,
  ADR-0011; outcome wording is non-absolute)
- **Date:** 2026-10-22
- **Deciders:** product architecture, founder (user)

## Context

The idea brief lists invoicing, payment links, reminders, late-payment prediction, tone suggestions, late-payer insight,
vendor bank-detail change checks, Telegram notifications and four-eyes approvals. It also warns that QuickBooks, Xero, FreshBooks
and Stripe already own invoicing, so "another invoicing service" cannot win. The niche agreed with the user: digital agencies,
dev studios and small B2B service contractors (5–50 people) that invoice clients and pay contractors. The brief also names
freelancers; they are kept as a secondary segment (1–4 people) served by the same product.

## Decision

1. Sell **two outcomes as one product**: (a) get invoices paid on time (collections autopilot), (b) stop risky payouts before
   they leave (payout guard). Copy never promises absolute fraud prevention (payment-guard disclaimer, DOMAIN_CONTRACT L.1).
2. Be **import-first**: open invoices can be imported from QuickBooks/Xero/FreshBooks CSV exports in MVP (API sync in v1). Invoice creation
   exists but stays lightweight (line items, per-line tax, PDF, numbering) for teams without a tool.
3. Never build ledger features: no chart of accounts, tax filing, payroll, bank-feed reconciliation, expense tracking.
4. Differentiate with behaviour-aware collections (tone ladder + client class + explainable risk), payment-security controls priced for
   small teams, Telegram-first approvals and alerts, bilingual RU/EN, and a RAG assistant grounded in the customer's own contracts and policy.
5. **Freelancers and very small teams are in scope.** Collections work unchanged for them; Payment Guard works through the
   seat-free `external_approver` role (accountant, partner) or the trusted-contact solo mode with extra delays (ADR-0011).
   A one-person business never ends up with payouts that cannot be approved: submission is blocked with an explanation
   instead.

## Consequences

- Positive: clear message on the landing page ("works next to the invoicing tool you already use"); smaller MVP; the two jobs reinforce each
  other (the same finance person chases receivables and releases payouts).
- Negative: customers keep two systems; payments recorded in PayFlow are not written back to the ledger until v1 sync — mitigated by CSV export.
- Pricing must stay in the $19–99/month band; volume limits rather than feature walls (security controls are never plan-gated —
  including solo mode and external approvers, which only have a count limit per plan).

## Alternatives considered

- **Full invoicing suite** — rejected: head-on competition with incumbents, long feature tail (taxes, accounting).
- **Pure AR-automation add-on for QuickBooks/Xero** — rejected for MVP: requires marketplace approvals and OAuth before any value; kept as v1 sync.
- **Pure payment-fraud tool** — rejected: enterprise-oriented market; small teams feel the receivables pain daily and the fraud pain rarely,
  so collections drive activation while the guard drives retention and trust.
- **Drop freelancers from the ICP** — rejected: the brief names them, and the solo mode costs little once four-eyes is
  hardened anyway.
