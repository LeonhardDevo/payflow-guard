# ADR-0004: Two-phase reminder scheduling — worker schedulers, web-owned planning, re-validation at dispatch

- **Status:** Accepted — amended 2026-10-22 (BullMQ-safe job ids, dispatch-job retention, client timezone, first-contact cap)
- **Date:** 2026-10-22

## Context

The brief assigns to Node.js + BullMQ/Redis: scheduled email sending, delivery retries, reminder generation, payment-status sync, Stripe
webhook verification and the Telegram notification queue. PLATFORM.md says the worker must not own business rules (eligibility, cadence,
tone, templates are business rules) and that web must not do long-running work in requests. Reminders must be sent at a local business-hours
slot, never twice for the same step, never after the invoice was paid, and survive Redis restarts or worker downtime.

## Decision

1. **Timing lives in the worker**: BullMQ Job Schedulers (Redis-coordinated, one job per iteration across replicas) tick every 15 minutes and
   fan out per workspace. Laravel's scheduler is not used. Scheduler ids use `-` separators (`pf-reminders-plan`).
2. **Planning lives in web**: `POST /internal/v1/payflow/workspaces/{id}/reminders/plan` creates reminder rows for due steps
   (`ON CONFLICT DO NOTHING` on `(invoice, step)`), skips superseded steps, computes the send slot in the **client's** timezone
   (falling back to the workspace's) inside the workspace send window, spreads sends by a stable hash of the invoice's public
   token, throttles first contacts per workspace and hour, and returns **all** scheduled reminders due within 26 hours — not only
   new ones — so a lost Redis job is re-enqueued on the next tick (self-healing).
3. The worker enqueues `reminders:dispatch` as a **delayed job** with `jobId = rem-{id}-{scheduledForEpoch}` (dedupe; BullMQ 5
   rejects custom ids containing `:`), `removeOnComplete: true` and `removeOnFail: true` (a finished or permanently failed
   job never blocks a later re-enqueue). A `too_early` answer re-adds `rem-{id}-{epoch}-r{n}` (n ≤ 5).
4. **Dispatch re-validates in web** under row locks: still scheduled, same `scheduled_for`, invoice still eligible, interval respected; it
   caps the tone of the first automated contact at `neutral` unless a human confirmed it, renders the email and publishes
   `email.send` (v2) through the outbox in the same transaction. Delivery retries belong to the worker `emails` queue with the
   `client_mail` retry profile (8 attempts over ≈ 22 h); intermediate `deferred` and final outcomes come back through
   `/internal/v1/deliveries`.
5. A `now` override flows through internal calls only when `!app()->isProduction()` and `DEV_ROUTES=1` (the E2E harness runs
   with `APP_ENV=e2e`).

## Consequences

- Exactly-once *business effect* even with at-least-once jobs; reminders are never sent for paid/disputed/paused invoices.
- Planning load is bounded (chunked per workspace); dispatch is O(1).
- Two network hops per reminder (plan + dispatch) — acceptable at our volumes.
- Requires internal API availability; when web is down, dispatch jobs retry with backoff and the next plan tick re-enqueues.
- A duplicate delayed job can exist briefly after a `too_early` re-add; web's idempotent dispatch answers `already_processed`.

## Alternatives considered

- **Precompute all reminders at invoice issue** — rejected: any change (payment, pause, policy) requires invalidating future rows; stale sends.
- **Outbox rows with `available_at` per reminder** — rejected: outbox is for committed facts, not tentative schedules; cancellation is awkward.
- **Laravel scheduler + queue** — rejected: violates the service split (worker owns queues/timing) and duplicates infrastructure.
