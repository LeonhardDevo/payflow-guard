# ADR-0006: Versioned, encrypted bank details with HMAC fingerprints and audited reveal

- **Status:** Accepted — amended 2026-10-22 (display vs reveal-only encryption classes, step-up via `guard.stepup`, no holder names to AI)
- **Date:** 2026-10-22

## Context

Payment Guard stores vendor IBANs/account numbers and must detect: the same account used by two vendors, re-use of a previously rejected account,
and changes over time — without exposing the numbers in the UI, logs, Telegram or AI answers.

## Decision

1. `vendor_bank_accounts` is **append-only per version**: a change creates a new row (`pending_verification → verified/rejected`, older verified
   → `superseded`). Nothing is edited in place except status/verification fields.
2. Account holder, IBAN/account/routing/SWIFT, request text and requester contact are **encrypted** with Laravel's `encrypted` cast
   (AES-256-GCM, `APP_KEY`, rotation via `APP_PREVIOUS_KEYS`).
3. A **fingerprint** `HMAC-SHA256(K, "v1|scheme|country|normalized account")` with `K` derived from `APP_KEY` enables equality checks and
   indexes without decryption. `last4` is stored in clear for display.
4. Default display is masked (`LT58 •••• •••• •••• 0519`). Full details are revealed only via a dedicated endpoint requiring
   `bank_details.reveal` + step-up (`guard.stepup`: passkey or TOTP, ADR-0011), returned for 60 seconds, and audited. Audit diffs replace secrets with `***` + last4.
5. AI tools and Telegram receive last4 and country only; AI tools get `holder_matches_vendor` + a similarity bucket instead of the holder name.
6. Encrypted columns come in two classes (DOMAIN_CONTRACT §0): **display** (holder name, change-request text — rendered to authorised roles on
   authenticated pages, every render of the review page audited) and **reveal-only** (IBAN, account and routing numbers, SWIFT, tax id,
   requester contact — only through the audited reveal endpoint or an owner export with step-up).

## Consequences

- DB dumps alone don't leak bank details; key rotation re-encrypts lazily on read/write.
- Fingerprints are workspace-agnostic only if the key is shared — that's acceptable (same deployment) and the queries always filter by workspace.
- Searching by account number is impossible by design (search by vendor name).

## Alternatives considered

- **Postgres pgcrypto column encryption** — rejected: keys would live in SQL, harder rotation, no Laravel integration.
- **Plain storage with access control only** — rejected (backup/dump leakage).
- **External vault/tokenization service** — deferred (operational cost for MVP).
