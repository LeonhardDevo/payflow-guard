---
title: 'Payment Guard disclaimer'
version: '1.0'
effective_date: '2026-10-22'
summary_of_changes: 'Initial version.'
requires_acceptance: false
---

<!-- template-notice -->

Payment Guard in {{product.name}} helps your team notice suspicious changes of payee bank details and keeps outgoing payments under a four-eyes process. Please read the limits below before relying on it.

## Indicators, not guarantees

Risk scores, signals such as a lookalike sender domain or a holder name that does not match, and the resulting risk bands are indicators. They are computed from the data your team enters and from simple, documented rules. A low score does not prove that a request is genuine, and a high score does not prove fraud.

## The decision stays with you

{{product.name}} never executes, initiates or holds payments. Payouts are released in your own bank by people in your organisation. Verifying a bank change - for example by calling the vendor on a phone number you already knew - and approving or releasing a payout are decisions made by your team.

## Known limits

- If an attacker controls an owner account together with the trusted contact, the four-eyes process can be bypassed.
- Signals depend on the quality of vendor data: a missing known phone number or e-mail domain weakens the checks.
- Cooling-off periods, approval thresholds and the second-factor requirement only protect you when they stay switched on; every change to them is logged and announced to the owners.

## If you suspect fraud

1. Do not release the payment and put the vendor on hold.
2. Contact the vendor through a channel you already trusted before the request.
3. Contact your bank immediately if money has already left your account; recall requests work best within hours.
4. Keep the original messages and export the audit log for the investigation.
5. Report the incident to the police and, where required, to your data protection authority.

Questions about Payment Guard can be sent to {{company.email}}.
