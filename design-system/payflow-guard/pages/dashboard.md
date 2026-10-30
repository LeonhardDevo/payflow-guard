# Dashboard Overrides (`/dashboard`, page `Dashboard`)

> Charts: performance-vs-target → bullet/gauge, time-series forecast → columns + line with an at-risk band,
> compare categories → sorted bars. UX: skeletons, number formatting, bulk actions. Overrides `../MASTER.md`.

## Layout overrides

- Content max 1440 px, 12-column grid, 24 px gaps (16 on mobile). Density high (app `body` 14/20, compact card padding 20).
- Order: onboarding checklist (only if incomplete) → KPI row → [Aging (5 cols) | Cash-in forecast (7 cols)] → [Needs attention (7) | Payment Guard (5)]
  → [Upcoming reminders (4) | Late payers (4) | Insights (4)].
- KPI row (the grid keeps `data-test="dashboard-stats"`, asserted by the foundation E2E): 5 cards ≥ xl, 3 + 2 on lg, 2 columns on md, stacked on mobile — never horizontal scrolling.
- Currency switcher in the page header (segmented control when ≤ 3 currencies, select otherwise); every widget follows it.

## Chart overrides

- Aging: vertical bars `--aging-0..4`, tabular value labels on top, bar click → `/invoices?aging=…`; table view lists bucket, count, amount.
- Forecast: stacked columns `expected` (`--chart-1`) + `at_risk` (`--aging-4` with decal stripes) per week + dashed cumulative line (`--chart-2`);
  "later" total as a caption; without `analytics_advanced` the real chart is blurred under a `LockedCard` with an upgrade CTA.
- DSO sparkline inside its KPI card (no axes, last-point dot, tooltip on focus).
- Rejected CLI suggestions: Sankey/funnel for cash flow (poor accessibility), pie charts for aging (hard comparisons).

## Component overrides

- "Needs attention" table: compact rows (36 px) — number · client + class badge · days-overdue chip · balance · risk badge · "Remind" (`sm` outline,
  opens the reminder preview sheet). Mobile: card rows.
- Payment Guard card: `GuardCard` with the ink header strip; critical alerts first; inline Approve only where the web rules allow it without a
  step-up (low risk, not cooling-off, no split signal); high risk shows "Review" linking to the payout page.
- Insights: at most 3 violet `InsightCard`s; hidden when empty (no placeholder).

## States

Deferred props (`forecast`) show chart-shaped skeletons; an empty workspace shows the onboarding checklist + `EmptyInvoices` with "Create invoice"
and "Import CSV"; a widget error shows an inline retry and never breaks the page.
