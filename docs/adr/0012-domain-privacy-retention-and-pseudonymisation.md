# ADR-0012: Domain privacy — retention, export, anonymisation, recipient notice and actor pseudonymisation

- **Status:** Accepted — implements PLATFORM.md §10 for the PayFlow domain (no override)
- **Date:** 2026-10-22

## Context

PLATFORM §10 makes export, deletion, retention, notices and AI data controls part of the MVP. The first plan deferred
deletion/anonymisation to v1, said "unsubscribe is not applicable" to reminders, gave no retention to domain personal data
(reminder bodies, provider event payloads with customer names and addresses, import CSVs, PDF cache, approval IP/user agent,
bank-change request text) and declared NOT NULL user foreign keys that are also four-eyes evidence — which collide with the
foundation's hard deletion of user accounts.

## Decision

1. **Retention table** (DOMAIN_CONTRACT L.2) with keys in `config/retention.php` under `payflow.*`, enforced by the daily
   `maintenance:purge` job that writes an audit entry with counts per key. Financial records (issued invoices, payments,
   payouts, approvals, bank-account versions) are kept; only their personal or bulky parts are trimmed (event payloads →
   ids, reminder bodies, IP/user agent, request text after the evidence period).
2. **Export manifest per domain table** (L.3) for the foundation workspace export. Bank details are masked unless the owner
   exports with step-up; that export is audited.
3. **Workspace deletion hook** deactivates payment links, disconnects the payment account and deletes domain files before
   the foundation purge.
4. **Anonymise contact** for clients and vendors (sole proprietors are data subjects): contact fields are nulled, holder names
   replaced by "Anonymised", amounts and numbers kept so the books stay intact.
5. **Users referenced by financial or approval records are pseudonymised, never hard-deleted**: every domain FK to `users`
   is `on delete restrict`; the foundation `AccountDeletion` asks a domain hook and, when references exist, tombstones the
   user (name "Deleted user #id", e-mail `deleted-{id}@deleted.invalid`, credentials, second factors, Telegram link and
   memberships removed). Approval evidence keeps pointing at the tombstone.
6. **Reminder recipients** get a notice and a choice: every invoice and reminder e-mail carries the controller's privacy
   contact, a link to PayFlow's `/legal/reminder-recipients` notice and an "I already paid / stop automated reminders" link
   (plus RFC 8058 one-click `List-Unsubscribe`). Stopping pauses automated reminders for that client and notifies the client
   owner and finance; invoices and manual reminders stay possible. View tracking can be disabled per workspace.
7. **AI data minimisation:** tools never return bank holder names (a `holder_matches_vendor` boolean and a similarity bucket
   instead); when `ai_external_allowed` is on, tool output passes the foundation PII redaction; the AI wording helper sends
   placeholders instead of personal names.
8. A **DPIA / LIA template** (`docs/legal/DPIA-LIA-template.md`) covers bank-change fraud scoring and late-payer profiling;
   customers adapt it as controllers.

## Consequences

- MVP scope grows by the purge keys, export manifest, anonymise actions and the opt-out page — all small, testable units.
- Audit trails stay meaningful after account deletion; the approval CHECKs never degenerate.
- Automated reminders can be stopped by recipients, which reduces collection pressure slightly — mitigated by notifying the
  team immediately so a human can follow up.

## Alternatives considered

- **Hard delete users and `set null` FKs** — rejected: requester ≠ verifier evidence would be lost.
- **Treat reminders as purely transactional (no opt-out)** — rejected: contradicts PLATFORM §10.2 and harms deliverability.
