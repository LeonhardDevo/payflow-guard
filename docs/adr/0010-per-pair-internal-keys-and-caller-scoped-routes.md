# ADR-0010: Per-pair internal API keys and caller-scoped internal routes

- **Status:** Accepted — **overrides PLATFORM.md §4.1** (single shared `INTERNAL_API_SECRET`) for PayFlow Guard; amended by ADR-0014 (the `ai` party is the `rag` role of the Node service, not a Python service)
- **Date:** 2026-10-22

## Context

PLATFORM §4.1 signs every service-to-service request with one secret shared by web, worker and ai. `X-Service` is
self-declared: `VerifyInternalSignature` only checks that it is in `config('internal.allowed_callers')`. The ai service
parses untrusted uploads (PDF, DOCX, OCR images). A parser exploit there would yield the shared key, and with it the
ability to call worker-only endpoints such as `POST /internal/v1/payflow/bot/payouts/{id}/decision` with any approver's
`telegram_user_id` — a one-person bypass of four-eyes. A symmetric key also lets every *verifier* forge requests as the
signer, so "one key per caller" is not enough when two services verify the same caller.

## Decision

1. **One key per unordered service pair**, never shared with the third service:
   `INTERNAL_KEY_WEB_WORKER` (web / worker), `INTERNAL_KEY_WEB_AI` (web / ai), `INTERNAL_KEY_WORKER_AI` (worker → ai,
   e.g. `/v1/chat` for Telegram). The signer picks the key by its target; the verifier picks the key by the pair
   (`X-Service`, itself). A compromised ai therefore cannot sign as worker towards web.
2. The signature algorithm, headers and the shared test vector of PLATFORM §4.1 are unchanged (the vector test runs once
   per key name with the same `test-secret`).
3. **Caller-scoped route groups.** After signature verification web applies `internal.caller:<service,…>`; a request from
   a service not listed gets `403 {error:'caller_not_allowed'}`. Foundation routes: `telegram/*`, `deliveries`,
   `stripe/events`, `payments/events` → `worker`; `documents/*` → `ai`; `ai-queries` → `ai,worker`; `ping` → any.
   PayFlow routes: worker-only (planner, dispatch, bot views and **all money-relevant bot decisions**), ai-only (**read-only**
   tool endpoints under `/internal/v1/payflow/ai/*`, GET only).
4. **Production posture:** the three keys are required (≥ 32 chars, pairwise distinct); boot fails otherwise. Outside
   production an unset pair key falls back to `INTERNAL_API_SECRET` (then to the committed dev fallback), so a fresh clone
   still runs offline. `scripts/env-sync.ts` generates all three.

## Consequences

- The blast radius of an ai compromise is limited to read-only, permission-checked tool data of the workspaces it is asked
  about (still filtered by the user permissions web re-checks).
- Three secrets to rotate instead of one; rotation is per pair and can be staged (verifiers accept `*_PREVIOUS` during a
  rotation window of 24 h).
- Foundation code in all three apps changes slightly (key selection); listed as DOMAIN_CONTRACT K.4 F11.

## Alternatives considered

- **Per-caller keys (`INTERNAL_API_SECRET_WORKER`, `INTERNAL_API_SECRET_AI`)** — rejected: ai must verify worker's
  `/v1/chat` calls, so it would hold the worker key and could forge worker requests to web.
- **Asymmetric signatures (Ed25519)** — cleaner, but needs `sodium`/extra libraries (PHP here has no sodium) and a new
  vector set; deferred.
- **mTLS between services** — operationally heavy for the single-host compose deployment; deferred.
