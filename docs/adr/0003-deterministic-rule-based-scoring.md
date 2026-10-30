# ADR-0003: Deterministic, explainable rule-based scoring; LLM only for wording and Q&A

- **Status:** Accepted
- **Date:** 2026-10-22

## Context

The product must "determine the probability of late payment", "suggest the tone depending on the delay", "show which clients systematically
pay late" and "check vendor bank-detail changes". Small workspaces have little history (often < 20 paid invoices per client), outcomes must be
explainable to finance people, tests must be stable, and everything must work offline with the mock AI provider.

## Decision

1. **Late-payment probability**: a fixed logistic formula over transparent features (smoothed client late rate, average days late, other
   overdue invoices, amount vs client median, long terms, days already overdue, unviewed invoice, dispute history, new client) with
   Bayesian smoothing toward the workspace prior. Weights live in config, are versioned with test vectors, and produce top-3 factor
   contributions for the UI. Bands: < 30 low, 30–59 medium, ≥ 60 high.
2. **Client class** (`new/reliable/occasional/chronic`) from simple thresholds over the last 24 months.
3. **Tone** from a days-overdue ladder plus adjustments (chronic +1, reliable −1, large amount +1, many reminders floor, ceiling per client).
4. **Bank-change and payout risk**: additive signal points with a cap of 100 and fixed bands; every signal is shown with its reason.
5. LLMs are used only to (a) rephrase a reminder draft that a human reviews, with a numeric guard that rejects new numbers, and (b) answer
   questions through permission-checked tools and RAG with citations.
6. All formulas take an injected clock and have table-driven tests shared across PHP (and TS where the UI previews them).

## Consequences

- Explainability: every badge has "why" reasons; support can reproduce any score.
- Stable demos and E2E (seed data yields known scores, e.g. NS-2026-0118 → high, PixelForge change → 100/high).
- Accuracy is "good enough" rather than optimal; v2 may add an opt-in model trained on anonymised cross-tenant data, behind the same
  interface, with calibration reports — the rule set remains the fallback.

## Alternatives considered

- **LLM-judged risk/tone** — rejected: non-deterministic, unexplainable, needs keys, prompt-injection surface (vendor request text).
- **Per-workspace ML model** — rejected: insufficient data per tenant at MVP stage.
