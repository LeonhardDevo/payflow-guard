# Pricing Page Overrides (`/pricing`, `marketing/Pricing`)

> Landing structure: pricing tiers + comparison table + FAQ. Overrides `../MASTER.md` for this page only.

## Layout overrides

- Container max 1120 px. Hero `display-lg`, centered, with a monthly/yearly segmented toggle and a "2 months free" / «2 месяца в подарок» chip.
- Plan cards in one row ≥ xl, 2×2 on md–lg, stacked on mobile (Pro first on mobile). Equal heights; CTA pinned to the card bottom.
- Comparison table below: rows = limit keys from `config/billing.php` (`seats`, `external_approvers`, `clients`, `invoices_per_month`, `vendors`,
  `payouts_per_month`, `documents`, `storage_mb`, `ai_queries_monthly`, `reminder_policies`, `imports_per_month`) + `entitlements` keys (PLAN §7); sticky header; on < md it sits in its own
  horizontally scrollable container with an edge shadow and a sticky first column (the only allowed horizontal scroll).
- Billing FAQ accordion and a final CTA.

## Colour overrides

- Plan cards use `--card`; Pro is highlighted with a 2 px `--primary` border, `--shadow-brand` (light) and a "Most popular" / «Популярный» pill.
  The CLI's "Free grey / Starter blue / Pro gold / Enterprise dark" scheme is rejected — tiers differ by emphasis, not by hue.
- Business is $99/month ($990/year) per PLAN §7. "External approvers don't use a seat" note under the seats row.
- The "Included in every plan" row for security features (four-eyes, verification, step-up, audit log, solo mode with a trusted contact) uses `success` check icons with text, never colour alone;
  `null` limits render as "Unlimited" / «Без ограничений».

## Component overrides

- `PlanCard`: name (`title-md`), price (`display-lg` tabular, period suffix `body-sm`), yearly note, 5 key limits, feature list with `Check` icons,
  CTA (`lg`). For a logged-in workspace the current plan shows a disabled "Current plan" / «Текущий тариф» button.
- Prices come from the `plans` prop (never hard-coded), formatted with Intl in the page locale.

## Copy

H1 EN "Pricing that pays for itself with one invoice" / RU «Тарифы, которые окупаются одним оплаченным счётом».
Note EN "Security features — four-eyes approvals, bank-detail verification, audit log — are included in every plan." /
RU «Функции безопасности — принцип четырёх глаз, проверка реквизитов, журнал аудита — есть во всех тарифах».
