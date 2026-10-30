# ADR-0005: Four-eyes approvals bound to amount and bank fingerprint; Telegram limited to low/medium risk

- **Status:** Accepted — **amended by [ADR-0011](0011-insider-resistant-four-eyes-and-solo-mode.md)** (privileged-role grants,
  trust delay, narrower auto-approval, structuring signal, `guard.stepup` with a second factor, solo mode). Where the two
  differ, ADR-0011 wins: step-up below means the 15-minute `guard.stepup`, not the 3-hour `password.confirm`.
- **Date:** 2026-10-22

## Context

The brief requires "confirmation by a second employee before a risky payment" and Telegram notifications. Telegram is where the team lives,
but a compromised Telegram account (or a shared phone) must not be enough to release money to a fraudster. Approvals must not "carry over"
when the payment changes after someone approved it.

## Decision

1. **Four-eyes everywhere**: the requester can never approve their own payout or verify their own bank-detail change. Enforced in policies and,
   for verification, by a DB CHECK (`verified_by_user_id <> requested_by_user_id`).
2. **Required approvals** are computed at submit: 0 (auto) only for low-risk, below-threshold payouts to vendors with history and no recent
   change; 2 for high risk, cooling-off, or amounts above the dual threshold; otherwise 1. Thresholds are per currency (ADR-0007).
3. **Binding**: each approval snapshots amount, currency, bank fingerprint and risk band. Amount/vendor are immutable after submit; any bank-detail
   change puts the payout on hold and invalidates approvals (new approval round). Release re-checks the fingerprint.
4. **Telegram**: approve/reject buttons only when `band ≠ high`, not in cooling-off, and the workspace allows Telegram approvals. Each tap requires
   a confirmation step; callback data carries the first 8 hex chars of the bank fingerprint so a stale message cannot approve changed details.
   High-risk approvals happen only in the web app with a second-factor step-up (`guard.stepup`, 15 minutes, ADR-0011).
5. Security controls are **not plan-gated**.

## Consequences

- A single compromised account (Telegram or web) cannot move money to new details.
- Small teams (1–3 people) may lack eligible approvers for high-risk payouts; ADR-0011 adds the seat-free `external_approver`
  role and a trusted-contact solo mode, and blocks submission with an explanation when neither can reach the required count.
- Slightly more friction for high-risk payouts — intentional.

## Alternatives considered

- **Telegram approvals for everything** — rejected (account takeover risk).
- **No Telegram approvals** — rejected (slow approvals; the brief explicitly wants Telegram).
- **One approval with mandatory comment** — rejected (insider risk).
