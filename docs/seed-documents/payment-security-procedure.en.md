# Payment Security Procedure — vendor payments and bank-detail changes

**Version:** 1.3 · **Effective date:** 1 June 2026 · **Owner:** Managing Director · **Applies to:** everyone who creates, approves or releases payments

Business-email compromise and "our bank details have changed" fraud are the most common ways companies like ours lose money.
This procedure makes sure no payment leaves Northstar Digital without the right checks. PayFlow Guard enforces most of these rules
automatically; this document explains why and what people must do.

## 1. Principles

1. **Four eyes.** The person who requests a payment or a bank-detail change can never approve or verify it.
2. **Known channels only.** Bank-detail changes are verified by calling the vendor at the phone number **already on file**,
   never at a number, email address or link contained in the change request.
3. **Slow down when it feels urgent.** Urgency, secrecy ("don't call", "confidential audit") and new countries are red flags, not reasons to hurry.
4. **Nothing by Telegram or email alone.** Messengers and email are notification channels; decisions are recorded in PayFlow Guard.

## 2. Bank-detail changes

1. Enter the new details in PayFlow Guard exactly as received (Vendors → Request bank change), including the source (email, phone, letter)
   and the full text of the request. Payouts to the vendor are put **on hold automatically**.
2. PayFlow Guard calculates a risk score. Typical red flags:
   - the request came from a **lookalike domain** (for example `pixelf0rge.example` instead of `pixelforge.example`);
   - the **account holder name** does not match the vendor's legal name;
   - the bank is in a **different country** or the currency changed;
   - the request mentions urgency, an audit, travel or asks not to call;
   - a payment to this vendor is due soon;
   - the same account is already used by another vendor.
3. A verifier (owner, admin or approver — never the requester) calls the vendor at the known number and confirms:
   the change was really requested, the account holder name, and the last four digits of the new account.
4. Verification methods allowed for medium and high risk: call to the known number, in person, signed letter, video call with a known contact.
5. After verification a **cooling-off period** applies: 24 hours for medium risk, 48 hours for high risk. The first payout to new details
   during cooling-off always needs **two approvals**.
6. If anything does not match — reject the change, keep the vendor on hold and inform the Managing Director.

## 3. Payout approvals

| Payout | Approvals required |
|---|---|
| Below $500 / €500, low risk, recurring vendor, details not recently changed | none (auto-approved, still audited) |
| From $500 / €500 | one approver |
| From $10,000 / €10,000, or high risk, or during cooling-off | two different approvers |

- The requester never approves their own payout.
- Approvals are tied to the amount and the exact bank account. If either changes, all approvals are reset.
- Approving in Telegram is allowed for low and medium risk. **High-risk payouts can only be approved in the web app with password confirmation.**
- Requests waiting more than 24 hours are escalated automatically.

## 4. Release

- Only approved payouts are paid. The finance manager executes the transfer in the bank and records the bank reference in PayFlow Guard.
- Before paying, check that the account in the bank matches the last four digits shown in PayFlow Guard.

## 5. If you suspect fraud

1. Do not pay. Put the vendor on hold in PayFlow Guard.
2. Inform the Managing Director immediately.
3. If money was already sent — call the bank immediately and ask for a recall; then report to the police.
4. Keep all emails and messages; do not reply to the suspicious sender.

## 6. Our own bank details

Northstar's receiving bank details printed on invoices can be changed only by the owner, with password confirmation; every change
notifies all owners. Clients are told in our contracts that we never change bank details by email.
