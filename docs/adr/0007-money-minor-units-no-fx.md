# ADR-0007: Money as integer minor units + currency; no FX conversion

- **Status:** Accepted — amended 2026-10-22 (formatting follows CLDR/Intl exactly; single currency list with gateway support)
- **Date:** 2026-10-22

## Context

Agencies invoice in USD and EUR (and sometimes RUB, GBP, PLN…). Analytics (aging, DSO, forecast), approval thresholds and late fees must be
exact. The server has no `intl` extension, so formatting can't rely on PHP `NumberFormatter`.

## Decision

1. Amounts are `bigint` minor units (`*_minor`) with a `char(3)` currency; all supported currencies use exponent 2. Floats are never used;
   forms send decimal strings parsed by `MoneyParser`; rounding is half-up in integer arithmetic (line amounts, tax, late fees).
2. **No FX conversion** anywhere in MVP. Dashboards and analytics are per currency with an explicit currency switcher (default: workspace
   currency); mixed totals are shown as lists (`$12,400.00 · €3,850.00`).
3. Approval thresholds are configured **per currency**; a currency without thresholds never auto-approves and uses risk-based dual approval only.
4. Server-side formatting uses a small `MoneyFormatter` whose output must equal `Intl.NumberFormat` (Node 24 / Chromium ICU,
   CLDR) for every supported currency and both locales: `en` uses a symbol only for USD `$`, EUR `€`, GBP `£`, CAD `CA$`,
   AUD `A$` and otherwise `CODE` + U+00A0 (`RUB 1,234.50`); `ru` groups with U+00A0, uses `,` decimals and appends U+00A0 +
   `$`, `€`, `£`, `₽`, `CA$`, `A$` or the code (`1 234,50 GEL`). The vectors file is **generated with Node Intl** and checked by
   Pest (formatter) and Vitest (Intl). Negative values use the hyphen-minus Intl produces.
5. `config('payflow.currencies')` is the single currency list (onboarding included) and states which payment gateway supports
   payment links for each currency (Stripe: USD, EUR; YooKassa: RUB). Currencies whose CLDR display precision is not 2 (e.g. RSD)
   are not offered.

## Consequences

- No wrong numbers from stale exchange rates; simpler audits.
- Emails, PDFs and Telegram show exactly what the UI shows.
- Workspaces with many currencies see several dashboards instead of one total — acceptable for the ICP (usually 1–2 currencies).
- v2 may add an optional "reporting currency" with dated ECB rates, clearly labelled as an estimate.

## Alternatives considered

- **Convert to workspace currency with live rates** — rejected (non-reproducible numbers, external dependency, offline requirement).
- **Decimal(18,2) columns** — rejected in favour of integers (simpler cross-language handling, no float leaks in JSON).
- **Hand-written symbol table (`RUB ₽`, `GEL ₾` everywhere)** — rejected: diverges from Intl, so server-rendered mail would
  disagree with the UI.
