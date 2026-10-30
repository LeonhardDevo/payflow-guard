# ADR-0011: Insider-resistant four-eyes, step-up authentication and a solo mode for 1–2 person businesses

- **Status:** Accepted — **amends ADR-0005**
- **Date:** 2026-10-22

## Context

ADR-0005 makes the requester unable to approve or verify their own change. An adversarial review found that a single
insider could still bypass it:

- an `admin` (or a lone owner) could invite a second mailbox they control as `approver`, accept it and approve through the
  sockpuppet; role changes were not covered by any Guard control;
- below-threshold, low-risk payouts were auto-approved and could be released by the same `finance` person who created them;
- a large payment could be split into several sub-threshold payouts (different amounts evade `duplicate_suspected`);
- auto-approval was allowed right after a bank-detail change (a phone-originated change scores low);
- the receiving bank details printed on every invoice could be changed by one owner, alerted only after the fact;
- step-up relied on `password.confirm`, whose default timeout is 3 h and which does nothing against a hijacked session.

At the same time four-eyes needs 3 people for dual approval, the free plan has 2 seats, and the brief targets freelancers:
a 1–2 person business would end up with payouts that can never be approved.

## Decision

1. **Guard-privileged roles** are roles holding any of `payouts.approve`, `bank_changes.verify`, `payouts.release`,
   `guard.configure`, `bank_details.reveal`, `payments.configure` (owner, admin, finance, approver, external_approver).
   Only **owners** may grant them (invite or role change), with step-up; admins may assign `member` only. Every grant raises
   a critical `guard_role_granted` alert to all owners and privileged members (Telegram + email, cannot be muted).
2. **Trust delay:** a newly invited or promoted privileged member becomes an *eligible* approver / verifier / releaser
   only after `guard_trust_delay_hours` (default 72 h; the workspace creator is eligible immediately). The approval UI,
   `eligible_approvers` and Telegram show "eligible from …". Removing a privileged member invalidates their approvals in
   open rounds.
3. **Auto-approval is narrow:** never when the account was verified < 90 days ago, after any bank-detail change of the
   vendor in 90 days, for the first payout to an account, when `split_suspected` fires, or when the vendor's rolling 30-day
   auto-approved sum in that currency would exceed `auto_approve_cumulative_minor`. An auto-approved payout must be
   released by someone other than its requester; if nobody else can release, it needs one approval instead.
4. **Structuring signal `split_suspected`** (30 points, forces dual approval): ≥ 2 non-canceled payouts to the same vendor
   and currency submitted within 7 days whose sum reaches the dual-approval threshold while each stays below it.
5. **Receiving bank details** (printed on invoices) are versioned: a change by an owner needs confirmation by a second
   eligible owner/approver when one exists (else the trusted contact, else it proceeds alone with a critical alert), and
   becomes effective no earlier than 24 h after the request; invoices keep the old details until then.
6. **Step-up authentication** for every Guard decision uses a dedicated `guard.stepup` middleware with its own 15-minute
   window (not the 3-hour `password.confirm`). Methods in order of preference: passkey, TOTP, password (password only when
   the user has no second factor and the workspace does not require one). `require_second_factor` (default **on**) blocks
   privileged actions for users without TOTP or a passkey and shows a setup prompt.
7. **Small teams** get two ways to complete four-eyes instead of an impossible state:
   - `external_approver` role (accountant, partner): payouts.view, payouts.approve, bank_changes.verify,
     integrations.manage; does **not** consume a seat (own plan limit `external_approvers`).
   - **Solo mode with a trusted contact:** the owner registers one trusted contact (verified e-mail, trust delay applies,
     changes alert all owners). When the eligible people are fewer than required, the contact receives a single-use signed
     link (24 h) showing amount, vendor, last 4 digits and risk, and may confirm or decline; a confirmation counts as one
     approval (`channel = trusted_contact`) and adds a mandatory release delay (`solo_release_delay_hours`, default 24 h).
     A lone requester may verify their own bank change only in solo mode, with a trusted-contact confirmation and a cooling-off
     of at least 72 h (DB CHECK allows `verified_by = requested_by` only with `verification_mode = 'solo'` and a confirmation id).
   - When even that cannot reach the required count, submission is **blocked** with an explanation and the actions that
     fix it (invite an external approver, add a trusted contact).

## Consequences

- A sockpuppet needs an owner, a step-up with a second factor, survives a visible 72-hour window and alerts every owner.
- Freelancers and two-person studios can use Payment Guard on the free plan; the ICP is restated to include them
  (ADR-0001 amendment).
- More states to test: trust delay, solo paths and releaser rules have dedicated Pest vectors and negative E2E steps (J4).
- Residual risk: a compromised sole owner with a compromised trusted contact can still pay a fraudster after the delays —
  documented in the payment-guard disclaimer.

## Alternatives considered

- **Count eligible approvers without a delay** — rejected (sockpuppet bypass).
- **Forbid Payment Guard for teams < 3** — rejected (drops freelancers, contradicts the brief).
- **Password step-up with a shorter timeout only** — rejected (does not stop session hijacking).
