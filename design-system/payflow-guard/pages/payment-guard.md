# Payment Guard Overrides (`guard/Index`, `guard/BankChangeReview`, `vendors/*`, `payouts/*`, `settings/Guard`)

> UX: confirmation dialogs, error feedback, focus states. Charts: performance vs target → bullet/gauge. No alert pulse/glow
> or status blink effects (one double ping for "awaiting you"). Overrides `../MASTER.md` for these pages.

## Visual language: "forensic calm"

- Page header carries an `--ink` strip (both themes) with the module label "Payment Guard" / «Защита платежей» and `ShieldCheck`.
- Identifiers (payout numbers, IBAN fragments, references) in `mono`; visible grid lines (`border`) in diff tables; generous whitespace around the
  decision bar; red only for high risk / reject / failures, amber for on-hold and cooling-off.

## Guard overview

- KPI tiles: Awaiting your approval (primary pill) · Pending approvals (count + amounts per currency) · Bank changes to verify (with high-risk count)
  · On hold · Released this month.
- "Needs your decision" first, then "Bank-detail changes" (high first), then the alert feed with a severity filter; side card "How Guard protects you"
  summarising thresholds, cooling-off and Telegram rules from settings.

## Bank-change review

- Order: summary header (vendor, requested by / when / source, risk badge) → **BankDiffTable** → **SignalList** → request text (quoted, monospace,
  urgency/secrecy words highlighted, rendered as plain text — never HTML) → affected payouts → **CallbackPanel** → sticky decision bar.
- If the viewer is the requester, the decision bar says "You requested this change — another person must verify it" / «Вы инициировали изменение —
  подтвердить его должен другой сотрудник» and the verify button is absent (not merely disabled).
- Verify requires all 4 checklist items; medium/high (and solo) opens the **StepUpDialog** first (passkey/TOTP); high requires a note (≥ 10
  chars), solo ≥ 30 chars plus a confirmed trusted-contact confirmation. A verifier still inside the trust delay sees "You can verify from {date}"
  instead of the button; a user without a second factor sees the setup link.
- Footer line on the review, payout and trusted-confirmation pages: "Risk scores are indicators, not a guarantee" + link to the payment-guard
  disclaimer. Every render of the review page is audited (it shows the holder name and request text).

## Payout detail

- Header: number, status, `amount-lg`, vendor + masked account (`LT58 •••• 0519`), risk badge; ApprovalStepper directly under the header.
- Checks list (`PayoutCheck`): vendor active · details verified · details current · not in cooling-off · no duplicates · approvals complete — each with
  `CircleCheck` / `CircleX` and text.
- Approve/Reject only for eligible approvers; otherwise a reason line (`is_requester`, `already_decided`, `not_eligible_yet` with the date,
  `second_factor_missing`, …). `split_suspected` shows the group total; auto-approved payouts state "Release by someone other than {requester}";
  solo mode shows "Waiting for {contact} (e-mail)" and, after confirmation, the release countdown. The release dialog asks for the bank
  reference and shows the last 4 digits to compare with the bank screen; high risk requires typing the payout number.

## Vendors

- List columns: name · category · country · current account (masked + status) · pending change (risk badge) · open payouts · last payout.
- Vendor page: bank-account history as a versioned timeline (verified / rejected / superseded, solo verifications labelled) with the diff on
  expand; "Reveal" requires step-up and shows details for 60 s with a visible countdown.

## Guard settings

- Thresholds table per currency (auto-approve below, dual from, cumulative 30-day cap), cooling-off, trust delay, release delay, Telegram
  approvals, second-factor requirement (switching it off shows a destructive confirmation and a persistent warning banner).
- Eligible approvers list with role, "eligible from" and missing-second-factor badges; trusted contact card (status, active from, replace /
  revoke with step-up); "How Guard protects you" summary uses the same data.
