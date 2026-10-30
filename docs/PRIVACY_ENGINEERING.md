# Privacy Engineering

This document maps every store of personal data in the platform to its purpose, legal basis, retention rule and coverage by export and deletion. It is derived from the actual schema: Laravel migrations in `apps/web/database/migrations`, worker migrations in `apps/worker/src/db/migrations.ts`, and the RAG schema migrations in `apps/worker/src/rag/db/migrations.ts`. Update it in the same change that adds or alters a table holding personal data.

Legend:

- **Role** — C: the operator is controller; P: the operator is processor for the customer (workspace).
- **Basis** — Contract (GDPR 6(1)(b) / 152-FZ 6(1)(5)), Legal (6(1)(c) / 6(1)(2)), LI (legitimate interests 6(1)(f)), Instr. (customer's instruction, GDPR art. 28 / 152-FZ 6(3)).
- **Retention key** — key in `config/retention.php`, rendered in legal documents as `{{retention.<key>}}` (value in days). "Lifecycle" means kept while the owning account or workspace exists.
- **Export** — U: in the user export archive; W: in the workspace export archive; — : not exported (with reason).
- **Delete** — what happens on account deletion (A) and workspace purge (W).

## 1. Retention keys

| Key | Default (days) | Enforced on | Enforced by |
|---|---|---|---|
| `ai_queries` | 180 | `ai_queries` rows; RAG `conversation_turns`, `ai_usage` | web purge (`POST /internal/v1/privacy/purge`) and RAG purge (`DELETE /v1/users/{id}`, `POST /v1/privacy/prune-memory`) |
| `audit_logs` | 365 | `audit_logs` rows | web purge |
| `deliveries` | 90 | `deliveries` rows | web purge |
| `webhook_events` | 30 | `worker.webhook_events` rows; `processed_stripe_events` rows | worker daily job; web purge |
| `outbox_dispatched` | 7 | `outbox_messages` with `dispatched_at` set | web purge |
| `failed_jobs` | 30 | `failed_jobs` rows | web purge |
| `data_exports` | 1 | `data_exports` files and signed links | web purge |
| `account_deletion_grace` | 14 | `deletion_requests` (subject user) | web purge, then hard delete |
| `workspace_deletion_grace` | 30 | `deletion_requests` (subject workspace) | web purge, then `workspace.purge` outbox topic |

Workspace owners can override `ai_queries`, `audit_logs` and `deliveries` downwards per workspace (`workspaces.retention` jsonb) within plan limits. The worker runs the purge scheduler daily at 03:15 UTC: it calls the signed web endpoint, which returns counts per key, purges `worker.webhook_events` itself, and an audit entry with the counts is written.

## 2. Data map — web application (PostgreSQL, Laravel)

| Table | Personal data | Purpose | Role | Basis | Retention | Export | Delete |
|---|---|---|---|---|---|---|---|
| `users` | name, email, hashed password, `two_factor_secret` and `two_factor_recovery_codes` (encrypted), locale, platform admin flag, current workspace | account, authentication | C | Contract | Lifecycle | U (without secrets/hashes) | A: hard delete after grace |
| `password_reset_tokens` | email, hashed token | password reset | C | Contract | 60 min (framework expiry) | — (transient) | A: deleted |
| `sessions` | user id, IP address, user agent, session payload | sign-in state, security | C | Contract / LI | session lifetime (`SESSION_LIFETIME`) | — (transient) | A: deleted |
| `passkeys` | credential id, public key credential, name, last used | passwordless authentication | C | Contract | Lifecycle | U (name, created, last used only) | A: deleted |
| `workspaces` | name, slug, timezone, currency, locale, `stripe_customer_id`, creator, `ai_external_allowed`, `retention` | workspace container, billing link, privacy settings | C (metadata) / P (configuration) | Contract | Lifecycle | W | W: deleted |
| `workspace_user` | membership, role | access control | C | Contract | Lifecycle | U, W | A: removed; W: deleted |
| `workspace_invitations` | invitee email, role, hashed token, inviter, accepted by, expiry | inviting members | P | Instr. | until accepted or expired, then with workspace | W | W: deleted |
| `audit_logs` | actor id and name, action, subject label, changes, metadata, IP, user agent, request id | security, accountability | C | LI / Legal | `audit_logs` | W (owner) | A: actor pseudonymised (name replaced, id nulled); W: deleted |
| `telegram_link_tokens` | user, workspace, hashed token, expiry | linking Telegram | C | Contract | 15 min validity, then purged with outbox cleanup | — (transient) | A, W: deleted |
| `telegram_accounts` | Telegram user id, chat id, username, language code, linked at | assistant in Telegram | C | Contract (user request) | until `/unlink` or account deletion | U | A: deleted; W: deleted |
| `subscriptions` | workspace plan, status, Stripe customer and subscription ids, period end, trial and cancel dates | billing | C | Contract / Legal | Lifecycle; invoices at the provider kept per tax law | W | W: deleted locally (provider retains invoices) |
| `processed_stripe_events` | event id, type, workspace | webhook idempotency | C | LI | `webhook_events` | — (technical) | W: deleted |
| `documents` | uploader, title, original file name, file path on disk, department, roles, tags, status, errors | knowledge base | P | Instr. | until deleted by the customer | W (metadata + files) | W: rows, files on disk and AI index deleted |
| `ai_queries` | user, channel, question, answer, citations, tools used, provider, model, latency, request id | assistant log, quality, audit | P | Instr. | `ai_queries` | U (own questions), W | A: user's rows deleted; W: deleted |
| `deliveries` | reference, channel, status, provider message id, error | delivery tracking | C / P | LI / Instr. | `deliveries` | W | W: deleted |
| `notifications` | notifiable id, notification data, read at | in-app notifications | C | Contract | Lifecycle | U | A, W: deleted |
| `outbox_messages` | topic, payload (may contain ids, e-mail addresses for mail jobs), workspace | reliable async delivery | C / P | Contract | `outbox_dispatched` after dispatch | — (technical) | W: pending rows for the workspace deleted |
| `jobs`, `job_batches`, `failed_jobs` | serialized job payloads, exceptions | background processing | C / P | Contract | `failed_jobs` for failed rows; others transient | — (technical) | W: queued jobs for the workspace deleted |
| `cache`, `cache_locks` | cached values (no long-lived personal data by design) | performance, locks | C | LI | TTL | — | flushed by TTL |
| `legal_acceptances` (F2) | user, workspace, document, version, accepted at, IP, user agent, locale | proof of agreement and consent | C | Legal / LI | Lifecycle + limitation period | U | A: kept pseudonymised for the limitation period, then deleted |
| `data_exports` (F2) | user/workspace, scope, status, archive path, expiry | data portability | C | Legal | `data_exports` | — | archive deleted at expiry |
| `deletion_requests` (F2) | subject, requester, scheduled for, cancelled, completed | right to erasure | C | Legal | until completion, then kept as minimal record | — | completed record kept without personal data |
| `email_suppressions` (F2) | e-mail address, reason, workspace, time | honouring opt-out | C / P | Legal / LI | indefinitely (needed to keep honouring the opt-out) | W | W: deleted with the workspace |

## 3. Data map — worker (schema `worker`)

| Table | Personal data | Purpose | Role | Basis | Retention | Export | Delete |
|---|---|---|---|---|---|---|---|
| `worker.webhook_events` | provider event payloads (Stripe, YooKassa, Telegram updates: names, e-mails, chat messages) | webhook ingestion, idempotency | C / P | Contract / LI | `webhook_events` | — (technical) | purged daily; W: rows referencing the workspace purged by `workspace.purge` |
| `worker.telegram_chats` | Telegram user id, chat id, username, locale | bot first-contact notice, locale | C | Contract | until `/unlink` or account deletion | U (via web) | A: deleted on unlink/deletion |

Redis/BullMQ holds job payloads transiently (completed jobs removed on completion, failed jobs kept for `failed_jobs` days).

## 4. Data map — RAG schema `rag` (worker, PostgreSQL + pgvector)

| Table | Personal data | Purpose | Role | Basis | Retention | Export | Delete |
|---|---|---|---|---|---|---|---|
| `documents` | title, department, roles, tags, errors | index metadata | P | Instr. | until the web document is deleted | via web `documents` | W: `DELETE /v1/workspaces/{id}` |
| `chunks` | document text fragments, embeddings, full-text vectors | retrieval | P | Instr. | until the document is deleted | via web files | document deletion purges immediately; W: purged |
| `conversation_turns` | workspace, conversation id, user id, role, message content | multi-turn context | P | Instr. | `ai_queries` | via web `ai_queries` | A: user's turns; W: purged |
| `ai_usage` | workspace, provider, model, purpose, tokens, cost, latency, errors, request id | cost and quality monitoring | C | LI | `ai_queries` | — (no content) | W: purged |
| `settings` | provider configuration and encrypted API keys (no personal data) | configuration | C | — | Lifecycle | — | — |

In PayFlow Guard and ScamPause Business the same contract is served by the TypeScript RAG in the worker; the same table set and the same retention and deletion rules apply there.

## 5. Export coverage

- **User export** (`privacy.export.requested`, scope `user`): profile (`users` without password hash and two-factor secrets), memberships, passkeys metadata, Telegram link, notifications, the user's own `ai_queries`, legal acceptances, files the user uploaded where the user is also a workspace owner. Format: ZIP of JSON files plus original files. Delivered by signed link valid for `data_exports` days.
- **Workspace export** (scope `workspace`, owner only): workspace settings, members and roles, invitations, documents metadata and original files, `ai_queries`, audit log, deliveries, subscription status.
- Excluded on purpose: secrets, hashes, session payloads, internal technical tables (outbox, jobs, caches, webhook payloads) — they contain no data not already exported or are transient.

## 6. Deletion coverage

- **Account deletion:** re-authentication; blocked if the user is the sole owner of a workspace with other members; `deletion_requests` row with `account_deletion_grace`; cancel link e-mailed; after grace: delete user, sessions, passkeys, Telegram link (web and worker), notifications, memberships, the user's `ai_queries` and AI conversation turns; pseudonymise `audit_logs` actor fields; keep `legal_acceptances` pseudonymised for the limitation period.
- **Workspace deletion:** owner only; `workspace_deletion_grace`; after grace the web emits `workspace.purge`; the worker deletes worker-side rows and calls AI `DELETE /v1/workspaces/{id}` (documents, chunks, embeddings, conversation turns, usage); the web deletes rows of every workspace-scoped table, stored files, pending outbox rows and queued jobs; the Stripe subscription is cancelled beforehand.
- **Document deletion:** immediate removal of the file and of AI `documents`/`chunks` rows.
- Backups: overwritten in the regular rotation; restoration procedures must replay pending deletions before the restored system serves traffic.

## 7. Sub-processor triggers

| Sub-processor category | Triggered by | Data sent | Condition |
|---|---|---|---|
| Hosting / database / object storage | always | all stored data | always used |
| E-mail delivery (SMTP provider) | verification, reset, security, billing, invitations, reminders, exports | recipient e-mail, name, message content, links | always used (Mailpit in development) |
| Stripe | checkout, portal, webhooks | workspace id, customer e-mail, plan, tax ID | when billing driver is `stripe` |
| YooKassa | payments, receipts (54-FZ) | customer e-mail, items, amounts | when billing driver is `yookassa` |
| Telegram | bot messages, linking | Telegram ids, message text (answers masked per product rules) | only when a user links Telegram and the bot token is configured |
| External LLM providers (OpenAI, Anthropic, OpenAI-compatible endpoints) | assistant answers | redacted question, selected fragments, recent turns | only when `ai_external_allowed = true` for the workspace and a provider key is configured; `mock`/local Ollama = no sub-processor |
| Remote embedding provider | indexing and search | document fragments, questions | only when `EMBEDDINGS_PROVIDER` is a remote provider; default local/hash = none |

The `{{subprocessors_table}}` placeholder renders the list from `config/legal.php`, showing only the entries active for the deployment, with purpose, data, location and condition.

## 8. PII redaction (outgoing AI requests)

Applied in the TypeScript RAG (`apps/worker/src/rag/privacy/redact.ts`) to every text sent to an external provider: e-mail addresses, phone numbers (international and RU formats), IBANs, payment card numbers validated with the Luhn check, and bank account numbers are replaced with masks keeping the last 4 characters. When `ai_external_allowed` is false, the request is routed to `mock`/local models and the response carries a degraded flag. Unit tests cover each pattern and the routing decision.

## 9. DPIA screening

Screening against GDPR art. 35(3) and the EDPB criteria (WP248 rev.01):

| Criterion | Present in the foundation? | Notes |
|---|---|---|
| Evaluation or scoring, profiling | No (foundation); product-specific features may add it | products that score risk (fraud, late payment) need their own DPIA — see `docs/legal/DPIA-LIA-template.md` |
| Automated decisions with legal or similar effect | No | AI output is advisory; human review required by Terms and AI notice |
| Systematic monitoring | Limited | audit log and assistant log visible to workspace admins — employee notice required where the product targets employees |
| Sensitive or highly personal data | Not intended | AUP prohibits special categories without basis |
| Large scale | Depends on deployment | reassess at production scale |
| Matching or combining datasets | No | |
| Vulnerable data subjects | Possible (employees vis-a-vis employer) | covered by role-based visibility and transparency notices |
| Innovative technology | Yes (LLMs) | mitigated: external providers off by default, redaction, no training, citations, confidence gate |
| Transfers outside EEA / Russia | Possible (external LLMs, Stripe) | SCCs; RU deployments in RU region; cross-border notification to Roskomnadzor |
| Prevents exercising a right or using a service | No | |

Result: two criteria may apply (innovative technology; possibly vulnerable data subjects or monitoring). Each product must complete a full DPIA before production when its domain features add profiling, scoring, employee monitoring or large-scale processing; the foundation itself is screened as not requiring a DPIA provided the defaults above remain in place. Re-screen on any change to AI routing, new sub-processor categories, or new data categories.
