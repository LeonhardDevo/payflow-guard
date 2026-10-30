# PayFlow Guard — Brand & UI Master Spec

> **LOGIC:** When building a specific page, first check `design-system/payflow-guard/pages/<page>.md`.
> If that file exists, its rules **override** this Master file for that page. Otherwise follow this file.
> Page overrides: `landing`, `pricing`, `dashboard`, `invoices`, `clients`, `payment-guard`, `assistant`, `public-invoice`.

**Product:** PayFlow Guard — collections autopilot + payout guard for agencies and studios.
**Binding rules:** PLATFORM.md §0 (no CDN, RU + EN, light + dark, rich UI, a11y baseline, offline) and §9 (brand anchors).
**Stack:** Tailwind 4 + shadcn-vue (reka-ui) + `@lucide/vue` + vue-echarts. Tokens live in `apps/web/resources/css/brand.css` — a **superset**
of the foundation token set (§3.0, §14). Revision 2 (2026-10-22): non-absolute tagline, verified-only statistics, full legal footer, token
superset migration. Revision 3: own layout language (§5.1 — top-bar "ledger" shell, not the foundation sidebar), payment wording per
ADR-0013 (Payment Links on the customer's own Stripe account; no Connect in the MVP).

Design direction: brand anchors are emerald / ink navy / amber / violet, both themes are first-class, fonts are self-hosted
Manrope / Inter / JetBrains Mono, and we never show fabricated testimonials or client logos. Patterns used: bento + product-demo landing
structure, social proof via sourced statistics, forecast-with-band and bullet/gauge chart guidance, table alternatives for charts, inline
validation on blur, confirm dialogs for irreversible actions, skeletons, 150–300 ms micro-interactions, reduced-motion support, shadcn
DataTable pattern, CSS-variable theming with `:root` + `.dark`.

---

## 1. Personality & voice

**Personality:** confident, "money in motion", calm control. The product feels like a sharp finance colleague who never panics: it
moves money forward (emerald, flowing lines) and stops it decisively when something is off (amber/red, crisp borders, plain language).

| Trait | Means | Doesn't mean |
|---|---|---|
| Confident | short declarative copy, big clear numbers, one primary action per view | hype, exclamation marks, "revolutionary" |
| In motion | flow lines, progress, timelines, forecasts; status that visibly advances | constant animation, bouncing elements |
| Calm control | neutral surfaces, generous spacing around risky decisions, explicit consequences | alarmist red everywhere, fear-based copy |
| Honest | "reduces risk", "helps verify", cites sources for stats | "guaranteed", "100 % safe", "fraud-proof" |

**Voice — EN:** plain business English, second person, verbs first ("Send reminder", "Verify by phone"). Numbers with units and currency.
**Voice — RU:** деловой, но живой русский, обращение на «вы», без канцелярита («осуществить оплату» → «оплатить»), кавычки «ёлочки»,
тире «—» с пробелами, неразрывный пробел между числом и единицей (`12 400,00 $`, `52 дня`). Russian strings run ~15–25 % longer: no fixed
widths on buttons/tabs; truncation only with tooltip.

**Naming:** "PayFlow Guard" (never "Payflow" or "PFG" in UI). Modules: "Receivables" / «Дебиторка», "Payment Guard" / «Защита платежей»,
"Assistant" / «Ассистент». Tagline EN "Get paid on time. Stop risky payouts before they leave." RU «Деньги — вовремя. Рискованные выплаты — под контролем.»
Taglines and headlines are never absolute (no "never pay a fraudster", "fraud-proof", «мошенникам — ни копейки»): Payment Guard reduces risk
and adds a second check; the payment-guard disclaimer says so.

## 2. Logo

Mark = a rounded **shield** (guard) carrying a **flow line that turns into an arrow** (money in motion). Wordmark = "PayFlow" (Manrope 800)
+ "Guard" (Manrope 600 in `--primary`). Reference SVG for `resources/js/components/brand/AppLogoMark.vue` (brand-landing refines curves,
keeps proportions):

```html
<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="pfg-logo-title">
  <title id="pfg-logo-title">PayFlow Guard</title>
  <defs>
    <linearGradient id="pfg-logo-g" x1="5" y1="3" x2="27" y2="29" gradientUnits="userSpaceOnUse">
      <stop offset="0" style="stop-color: var(--logo-from)" />
      <stop offset="1" style="stop-color: var(--logo-to)" />
    </linearGradient>
  </defs>
  <path d="M16 2.75 5.5 6.6v8.15c0 6.55 4.37 11.92 10.5 13.85 6.13-1.93 10.5-7.3 10.5-13.85V6.6L16 2.75Z" fill="url(#pfg-logo-g)" />
  <path d="M10 18.5c2.4 0 3.5-1.6 4.6-3.4 1.2-1.9 2.4-3.6 5.1-3.6H22" fill="none" stroke="var(--logo-flow)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
  <path d="m19.6 9 2.6 2.5-2.6 2.5" fill="none" stroke="var(--logo-flow)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
</svg>
```

Tokens: `--logo-from: oklch(0.65 0.14 163)`, `--logo-to: oklch(0.58 0.11 190)`, `--logo-flow: oklch(0.99 0.01 165)` (both themes).
Clear space = shield height × 0.25. Minimum size 16 px (favicon uses a heavier 2.8 px flow stroke). Monochrome variant: shield in
`currentColor`, flow in `--background`. Favicons (`favicon.svg`, `.ico`, `apple-touch-icon.png`) are generated locally from this SVG.
Never place the mark on busy gradients without a solid backplate.

## 3. Colour system

### 3.0 Token names — superset of the foundation `brand.css` (binding)

The foundation UI already uses these tokens (counts from `resources/js` at commit 3c70f65): `bg-primary-soft` / `text-primary-soft-foreground`,
`*-soft-foreground` for success/warning/info/destructive/ai (≈ 110 uses), `--ai*` (`bg-ai`, `bg-ai-soft` …), `--brand-font-display/sans/mono`,
`--chart-1..6`, `--chart-axis`, `--chart-grid`, `--aurora-1..4`, `--glass`, `--glass-border`, `--gradient-brand`, `--gradient-brand-text`,
`--hero-glow`, `--pattern-dot`, `--shadow-color`, `--sidebar-background`, `--warning-foreground`. **Every existing name stays** with the same role;
this spec only retunes values and adds tokens. Implementation rules:

| Role | Foundation name (kept, canonical) | Former MASTER name (do not create) |
|---|---|---|
| Status fill / strong icon (≥ 3:1 graphics) | `--success`, `--warning` (amber fill), `--info`, `--destructive`, `--ai` | `--warning-fill`, `--insight` |
| Text on that fill | `--success-foreground`, `--warning-foreground`, `--info-foreground`, `--destructive-foreground`, `--ai-foreground` | `--warning-fill-foreground`, `--insight-foreground` |
| Badge / soft background | `--success-soft`, `--warning-soft`, `--info-soft`, `--destructive-soft`, `--ai-soft`, `--primary-soft` | `--insight-soft` |
| Text/icon on soft background **and on cards** (≥ 4.5:1) | `--success-soft-foreground`, `--warning-soft-foreground`, `--info-soft-foreground`, `--destructive-soft-foreground`, `--ai-soft-foreground`, `--primary-soft-foreground` | `--warning` (as text colour), `--insight` (as text colour) |
| Fonts | `--brand-font-display`, `--brand-font-sans`, `--brand-font-mono` (mapped to `--font-*` by `@theme inline` in `app.css`) | `--font-*` defined in `brand.css` |

**New tokens added by PayFlow** (light + dark): `--brand`, `--ink`, `--ink-foreground`, `--aging-0..4`, `--risk-low|medium|high` (aliases of
`--success|--warning|--destructive`) with `--risk-*-soft` / `--risk-*-soft-foreground` aliases, `--chart-7`, `--chart-8`, `--logo-from|to|flow`,
`--gradient-guard`, `--texture-noise`, `--shadow-brand`. `--insight*` may exist **only** as aliases (`--insight: var(--ai)` …) for readability in
this document; components use `ai` utilities.

In §3.2–§3.4 below, the value listed for a "text" role is the target for the corresponding `*-soft-foreground` token, and the value listed for
an amber fill is the target for `--warning`. When an existing value already passes `scripts/contrast-check.mjs`, brand-landing may keep it —
the checker over the merged file is the acceptance gate, not byte-equality with this table.

### 3.1 Principles

- Emerald = money that arrived / primary action. Ink navy = structure and authority. Amber = overdue / attention. Red = risk / failure /
  destructive. Violet = AI insight and disputes (things that need judgement). Sky blue = informational / scheduled.
- **Designed, not inverted:** dark mode has its own lightness ladder; status hues get lighter in dark and darker in light to keep ≥ 4.5:1.
- Colour is never the only signal: every status/risk/tone badge has an icon and a text label; charts have a table view and decal patterns for "at risk".
- `--brand` (`#059669` anchor) is for the logo, illustrations and large display accents; **text and buttons use `--primary`** (darker in light
  mode) because white on `#059669` is only 3.77:1.

### 3.2 Tokens — light (`:root`)

Contrast = WCAG 2.x ratio computed from the OKLCH values (sRGB, gamut-clipped). Text pairs ≥ 4.5:1, UI/graphics ≥ 3:1.

| Token | OKLCH | ≈ Hex | Use | Contrast notes |
|---|---|---|---|---|
| `--background` | `oklch(0.982 0.003 255)` | `#F8F9FB` | app/page background (anchor `#F7F8FA`) | — |
| `--foreground` | `oklch(0.19 0.035 264)` | `#0C1323` | body text, headings (anchor ink `#0B1324`) | 17.6 on bg · 18.5 on card |
| `--card` / `--popover` | `oklch(1 0 0)` | `#FFFFFF` | cards, popovers, dialogs | — |
| `--card-foreground` / `--popover-foreground` | = foreground | | | |
| `--muted` | `oklch(0.958 0.006 255)` | `#EEF1F5` | subtle fills, table header, skeleton | — |
| `--muted-foreground` | `oklch(0.47 0.03 262)` | `#525B6C` | secondary text, labels | 6.5 bg · 6.8 card · 6.0 muted |
| `--border` | `oklch(0.912 0.009 258)` | `#DEE2E8` | dividers, card borders | decorative (1.23 on bg) |
| `--input` | `oklch(0.86 0.012 258)` | `#CCD1D9` | input borders | always paired with label + focus ring |
| `--ring` | `oklch(0.56 0.12 165)` | `#008A63` | focus ring (2 px + 2 px offset) | 4.15 on bg (≥ 3 ✓) |
| `--primary` | `oklch(0.51 0.105 166)` | `#037958` | primary buttons, links, active nav | 5.2 on bg · 5.4 on card |
| `--primary-foreground` | `oklch(0.99 0.01 165)` | `#F6FEFA` | text on primary | 5.3 |
| `--brand` | `oklch(0.596 0.127 163)` | `#099669` | logo, illustrations, display accents ≥ 24 px | 3.8 on card (large text/graphics only) |
| `--secondary` | `oklch(0.945 0.01 258)` | `#E9EDF4` | secondary buttons, neutral chips | — |
| `--secondary-foreground` | `oklch(0.24 0.035 264)` | `#171F30` | text on secondary | 14.0 |
| `--accent` | `oklch(0.955 0.025 165)` | `#E2F6EC` | hover/selected rows, user chat bubble, partial-paid chip | — |
| `--accent-foreground` | `oklch(0.3 0.06 166)` | `#033727` | text on accent | 11.8 |
| `--ink` | `oklch(0.2 0.04 264)` | `#0D1528` | Guard band, device frames | — |
| `--ink-foreground` | `oklch(0.97 0.005 250)` | `#F3F5F8` | text on ink | 16.6 |
| `--destructive` | `oklch(0.53 0.2 26)` | `#C51D24` | destructive buttons, high risk, failures | 5.9 card · 5.1 soft |
| `--destructive-foreground` | `oklch(0.99 0.005 20)` | `#FFFAFA` | text on destructive | 5.7 |
| `--destructive-soft` | `oklch(0.96 0.025 22)` | `#FFECEA` | badge/alert background | — |
| `--success` | `oklch(0.5 0.11 160)` | `#0B764D` | paid, verified, low risk (text/icons) | 5.7 card · 5.1 soft |
| `--success-foreground` | `oklch(0.99 0.01 160)` | `#F6FEF9` | text on success fill | 5.5 |
| `--success-soft` | `oklch(0.96 0.035 162)` | `#DEFAEB` | badge background | — |
| `--warning-soft-foreground` | `oklch(0.53 0.12 62)` | `#9C5906` | overdue, medium risk, on hold — text/icons on cards and on `--warning-soft` | 5.5 card · 4.9 soft |
| `--warning` | `oklch(0.77 0.16 70)` | `#F49F1E` | amber fill (icon tiles, banners, chart marks, dots) — amber anchor `#F59E0B` | graphics only |
| `--warning-foreground` | `oklch(0.25 0.06 55)` | `#371801` | text on the amber fill | 7.6 |
| `--warning-soft` | `oklch(0.965 0.04 85)` | `#FFF2D6` | badge background | — |
| `--info` | `oklch(0.5 0.13 245)` | `#0068A7` | scheduled, due soon, info | 5.9 card · 5.3 soft |
| `--info-foreground` | `oklch(0.99 0.01 245)` | `#F6FDFF` | text on info fill | 5.8 |
| `--info-soft` | `oklch(0.96 0.025 240)` | `#E3F5FF` | badge background | — |
| `--ai` (+ `--ai-soft-foreground` same value) | `oklch(0.51 0.22 293)` | `#713BD5` | AI insights, disputes (violet anchor `#7C3AED`) | 6.4 card · 5.7 soft |
| `--ai-foreground` | `oklch(0.99 0.01 293)` | `#FCFBFF` | text on insight fill | 6.2 |
| `--ai-soft` | `oklch(0.96 0.03 295)` | `#F3EEFF` | insight cards/badges | — |
| `--risk-low` / `--risk-medium` / `--risk-high` | = success / warning / destructive | | risk badges, gauges | |
| `--chart-1` | `oklch(0.58 0.125 163)` | `#029165` | collected / expected / primary series | 4.0 on card |
| `--chart-2` | `oklch(0.36 0.06 264)` | `#2D3C5D` | invoiced / comparison | 10.9 |
| `--chart-3` | `oklch(0.665 0.16 64)` | `#D57A00` | overdue / on-time line | 3.2 |
| `--chart-4` | `oklch(0.55 0.21 293)` | `#7B4CDE` | AI / insight series | 5.3 |
| `--chart-5` | `oklch(0.6 0.13 237)` | `#058AC3` | scheduled / info series | 3.9 |
| `--chart-6` | `oklch(0.62 0.19 15)` | `#E14660` | at-risk / failures | 4.0 |
| `--chart-7` | `oklch(0.6 0.1 195)` | `#0F9293` | auxiliary | 3.8 |
| `--chart-8` | `oklch(0.6 0.02 260)` | `#79818D` | "other" / baseline | 4.0 |
| `--aging-0` … `--aging-4` | `oklch(0.6 0.12 163)` · `oklch(0.66 0.135 90)` · `oklch(0.66 0.16 62)` · `oklch(0.6 0.19 42)` · `oklch(0.52 0.2 25)` | `#21966B` `#B28D00` `#D57700` `#D84B00` `#C21725` | aging buckets current → 90+ | 3.7 · 3.1 · 3.2 · 4.3 · 6.1 on card |
| `--sidebar` | `oklch(0.972 0.005 255)` | `#F3F6F9` | sidebar surface | — |
| `--sidebar-foreground` | `oklch(0.27 0.035 264)` | `#1E2638` | nav text | 13.9 |
| `--sidebar-primary` / `-foreground` | = primary / primary-foreground | | workspace avatar, active indicator bar | |
| `--sidebar-accent` | `oklch(0.94 0.03 165)` | `#DAF2E6` | active / hover nav item | — |
| `--sidebar-accent-foreground` | `oklch(0.36 0.08 166)` | `#004A33` | active nav text | 8.8 |
| `--sidebar-border` / `--sidebar-ring` | = border / ring | | | |

### 3.3 Tokens — dark (`.dark`)

| Token | OKLCH | ≈ Hex | Contrast notes |
|---|---|---|---|
| `--background` | `oklch(0.155 0.022 265)` | `#080C16` | anchor `#070B14` |
| `--foreground` | `oklch(0.955 0.006 250)` | `#EDF0F4` | 17.2 bg · 16.1 card |
| `--card` / `--popover` | `oklch(0.195 0.025 265)` | `#0F1520` | raised surface (lighter than bg) |
| `--muted` | `oklch(0.235 0.024 265)` | `#181E2A` | |
| `--muted-foreground` | `oklch(0.72 0.022 258)` | `#9CA5B3` | 7.9 bg · 7.4 card · 6.7 muted |
| `--border` | `oklch(0.29 0.025 265)` | `#252B38` | decorative (1.38 on bg) |
| `--input` | `oklch(0.36 0.025 265)` | `#373D4B` | + label + ring |
| `--ring` | `oklch(0.78 0.14 163)` | `#4ED39D` | 10.4 on bg |
| `--primary` | `oklch(0.8 0.145 163)` | `#4FDAA3` | anchor `#34D399`; 11.1 on bg |
| `--primary-foreground` | `oklch(0.22 0.05 168)` | `#002216` | 9.7 on primary |
| `--brand` | `oklch(0.8 0.145 163)` | `#4FDAA3` | |
| `--secondary` | `oklch(0.26 0.026 265)` | `#1E2431` | |
| `--secondary-foreground` | `oklch(0.94 0.006 250)` | `#E8EBEF` | 13.1 |
| `--accent` | `oklch(0.27 0.04 170)` | `#0F2D23` | |
| `--accent-foreground` | `oklch(0.9 0.07 165)` | `#B3EDD3` | 11.3 |
| `--ink` | `oklch(0.115 0.02 265)` | `#03050C` | Guard band is darker than bg in dark mode |
| `--ink-foreground` | `oklch(0.97 0.005 250)` | `#F3F5F8` | 18.7 |
| `--destructive` | `oklch(0.7 0.17 22)` | `#F66C6D` | 6.3 card · 5.3 soft |
| `--destructive-foreground` | `oklch(0.18 0.04 20)` | `#20090A` | 6.6 |
| `--destructive-soft` | `oklch(0.27 0.06 22)` | `#3F1919` | |
| `--success` | `oklch(0.8 0.145 163)` | `#4FDAA3` | 10.4 card · 8.7 soft |
| `--success-foreground` | `oklch(0.22 0.05 168)` | `#002216` | 9.7 |
| `--success-soft` | `oklch(0.26 0.05 165)` | `#052B1E` | |
| `--warning-soft-foreground` | `oklch(0.82 0.15 75)` | `#FCB442` | 10.3 card · 8.3 soft |
| `--warning` / `--warning-foreground` | `oklch(0.8 0.16 75)` / `oklch(0.22 0.05 60)` | `#F9AD26` / `#2B1401` | 9.1 |
| `--warning-soft` | `oklch(0.28 0.05 70)` | `#382409` | |
| `--info` | `oklch(0.76 0.12 240)` | `#62BBF5` | 8.7 card · 7.1 soft |
| `--info-foreground` | `oklch(0.2 0.05 245)` | `#00182B` | 8.6 |
| `--info-soft` | `oklch(0.27 0.05 245)` | `#0E293D` | |
| `--ai` (+ `--ai-soft-foreground`) | `oklch(0.75 0.15 295)` | `#B599FF` | 7.8 card · 6.4 soft |
| `--ai-foreground` | `oklch(0.2 0.06 295)` | `#190F2E` | 7.8 |
| `--ai-soft` | `oklch(0.28 0.07 295)` | `#2C2047` | |
| `--chart-1` … `--chart-8` | `oklch(0.78 0.14 163)` · `oklch(0.75 0.06 255)` · `oklch(0.8 0.15 75)` · `oklch(0.72 0.17 295)` · `oklch(0.76 0.12 230)` · `oklch(0.72 0.17 15)` · `oklch(0.8 0.1 190)` · `oklch(0.62 0.02 260)` | `#4ED39D` `#95B0D4` `#F5AE39` `#AE8BFF` `#51BFEE` `#FC7182` `#67D2CC` `#7F8793` | all ≥ 5.0 on card |
| `--aging-0` … `--aging-4` | `oklch(0.76 0.14 163)` · `oklch(0.86 0.13 95)` · `oklch(0.8 0.15 72)` · `oklch(0.72 0.17 48)` · `oklch(0.66 0.19 25)` | `#46CC97` `#ECD065` `#F8AC3D` `#F77E35` `#F05653` | 9.1 · 12.0 · 9.6 · 7.0 · 5.4 |
| `--sidebar` | `oklch(0.135 0.02 265)` | `#050810` | |
| `--sidebar-foreground` | `oklch(0.88 0.01 255)` | `#D3D8DE` | 14.0 |
| `--sidebar-accent` | `oklch(0.25 0.045 168)` | `#05281D` | |
| `--sidebar-accent-foreground` | `oklch(0.86 0.12 163)` | `#80EABA` | 10.8 |
| `--sidebar-border` | `oklch(0.25 0.022 265)` | `#1C222C` | |

Some vivid tokens sit slightly outside sRGB; browsers clip them to the listed hex. Any token change must be re-checked with
`apps/web/scripts/contrast-check.mjs` (brand-landing ships it: parses `brand.css`, converts OKLCH → sRGB and fails below the thresholds above).

### 3.4 Semantic mappings (single source for badges, charts and Telegram wording; the foreground of every `X-soft` background is `X-soft-foreground`)

| Domain value | Token pair (bg / fg) | Lucide icon | EN / RU label |
|---|---|---|---|
| Invoice `draft` | muted / muted-foreground | `FilePen` | Draft / Черновик |
| `open` | secondary / secondary-foreground | `Send` | Sent / Выставлен |
| `due_soon` | info-soft / info-soft-foreground | `CalendarClock` | Due in {n} d / Срок через {n} дн. |
| `overdue` | warning-soft / warning-soft-foreground (amber = overdue) | `AlarmClock` | Overdue {n} d / Просрочен {n} дн. |
| `partially_paid` (chip) | accent / accent-foreground | `CircleDotDashed` | Partially paid / Оплачен частично |
| `paid` | success-soft / success-soft-foreground | `CircleCheck` | Paid / Оплачен |
| `disputed` | ai-soft / ai-soft-foreground | `MessageSquareWarning` | Disputed / Спорный |
| `uncollectible` | muted / muted-foreground | `FileX2` | Written off / Списан |
| `void` | muted / muted-foreground (label struck through) | `Ban` | Void / Аннулирован |
| Risk `low` · `medium` · `high` | success-soft/success-soft-foreground · warning-soft/warning-soft-foreground · destructive-soft/destructive-soft-foreground | `Gauge` (+ `TriangleAlert` for high) | Low · Medium · High risk / Низкий · Средний · Высокий риск — always with the score |
| Tone `friendly` · `neutral` · `firm` · `final` | success-soft · info-soft · warning-soft · destructive-soft | `MessageCircle` · `MessageSquare` · `MessageSquareText` · `MessageSquareX` | Friendly · Neutral · Firm · Final / Дружелюбный · Нейтральный · Твёрдый · Последнее |
| Client class `new` · `reliable` · `occasional` · `chronic` | secondary · success-soft · warning-soft · destructive-soft | `Sparkle` · `CircleCheck` · `CircleAlert` · `Hourglass` | New · Reliable · Sometimes late · Systematically late / Новый · Надёжный · Иногда опаздывает · Систематически платит поздно |
| Aging buckets | `--aging-0..4` | — | Current · 1–30 · 31–60 · 61–90 · 90+ / Текущие · 1–30 · 31–60 · 61–90 · 90+ |
| Payout `draft` · `pending_approval` · `approved` · `on_hold` · `released` · `rejected` · `canceled` | muted · info-soft · success-soft · warning-soft · secondary (bold) · destructive-soft · muted | `FilePen` · `Clock` · `BadgeCheck` · `CirclePause` · `Send` · `CircleX` · `Ban` | Draft · Awaiting approval · Approved · On hold · Released · Rejected · Canceled / Черновик · На согласовании · Согласована · Приостановлена · Проведена · Отклонена · Отменена |
| Bank account `pending_verification` · `verified` · `rejected` · `superseded` | warning-soft · success-soft · destructive-soft · muted | `ShieldQuestion` · `ShieldCheck` · `ShieldX` · `History` | Needs verification · Verified · Rejected · Replaced / Требует проверки · Подтверждены · Отклонены · Заменены |
| Alert `info` · `warning` · `critical` | info-soft · warning-soft · destructive-soft (+ 3 px left border destructive) | `Info` · `TriangleAlert` · `ShieldAlert` | |
| Reminder `scheduled` · `queued` · `sent` · `failed` · `skipped` · `canceled` | info-soft · info-soft · success-soft · destructive-soft · muted · muted | `CalendarClock` · `Loader` · `MailCheck` · `MailX` · `SkipForward` · `Ban` | |

### 3.5 Gradients, textures, surfaces

```css
--aurora: radial-gradient(60% 50% at 18% 8%, oklch(0.8 0.145 163 / 0.16), transparent 70%),
          radial-gradient(48% 42% at 86% 0%, oklch(0.62 0.2 293 / 0.12), transparent 70%),
          radial-gradient(70% 60% at 50% 105%, oklch(0.36 0.06 264 / 0.10), transparent 70%);
/* .dark --aurora alphas: 0.26 / 0.20 / 0.35 */
--gradient-brand: linear-gradient(135deg, oklch(0.62 0.13 163), oklch(0.6 0.11 190));
--gradient-guard: linear-gradient(180deg, oklch(0.23 0.04 264), oklch(0.16 0.03 264));   /* ink band, both themes */
--texture-dots: radial-gradient(oklch(0.19 0.035 264 / 0.07) 1px, transparent 1px) 0 0 / 22px 22px;   /* .dark: oklch(1 0 0 / 0.06) */
--texture-noise: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/></filter><rect width='100%' height='100%' filter='url(%23n)' opacity='.35'/></svg>");
```
- Implementation keeps the foundation names: the aurora above is composed from `--aurora-1..4`, the dot colour is `--pattern-dot`,
  the hero glow `--hero-glow`, glass is `--glass` / `--glass-border`; `--gradient-guard` and `--texture-noise` are new (§14).
- Aurora only behind the hero, final CTA, auth side panel and locked-feature states — never behind data tables.
- Dots texture masked with `mask-image: radial-gradient(closest-side, black, transparent)`; noise at 3–4 % opacity on hero and the Guard band.
- Gradient text only for display ≥ 36 px, with a solid `--foreground` fallback under `forced-colors`.
- Glass (backdrop blur) only for the sticky marketing header: light `oklch(1 0 0 / 0.78)`, dark `oklch(0.155 0.022 265 / 0.72)` + border.

## 4. Typography

| Role | Package (npm, self-hosted) | Family | Cyrillic |
|---|---|---|---|
| Display (landing, page titles) | `@fontsource-variable/manrope` 5.x | `Manrope Variable` | ✓ `cyrillic` + `cyrillic-ext` subsets verified in the 5.3.0 tarball |
| UI / body / numbers | `@fontsource-variable/inter` 5.x | `Inter Variable` | ✓ verified |
| Mono (IBAN, references, keys) | `@fontsource-variable/jetbrains-mono` 5.x | `JetBrains Mono Variable` | ✓ verified |

Imports (already at the top of `resources/css/brand.css`; the starter kit's Bunny `fonts:` option and `@fonts` directive stay removed):
```css
@import '@fontsource-variable/manrope/wght.css';
@import '@fontsource-variable/inter/wght.css';
@import '@fontsource-variable/jetbrains-mono/wght.css';
```
Stacks (defined in `brand.css` as `--brand-font-display/sans/mono` and mapped to `--font-*` by `@theme inline` — keep those names): `--font-display: 'Manrope Variable', 'Inter Variable', ui-sans-serif, system-ui, sans-serif;`
`--font-sans: 'Inter Variable', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;`
`--font-mono: 'JetBrains Mono Variable', ui-monospace, 'SFMono-Regular', Consolas, monospace;`
Invoice PDFs use DejaVu Sans bundled with dompdf (full Cyrillic) — no remote fonts.

| Token | Font | Size / line-height (px) | Weight | Tracking | Use |
|---|---|---|---|---|---|
| `display-2xl` | Manrope | 64/68 (mobile 40/44) | 800 | −0.03em | landing hero H1 |
| `display-xl` | Manrope | 48/54 (mobile 32/38) | 800 | −0.025em | landing section titles |
| `display-lg` | Manrope | 36/42 (mobile 28/34) | 700 | −0.02em | pricing hero, landing stats |
| `title-lg` | Manrope | 24/32 | 700 | −0.015em | app page title (H1) |
| `title-md` | Manrope | 20/28 | 700 | −0.01em | section headers, dialog titles |
| `title-sm` | Inter | 16/24 | 600 | 0 | card titles |
| `body-lg` | Inter | 18/28 | 400 | 0 | landing body |
| `body` | Inter | 14/20 (inputs 16/24 below 768 px) | 400 | 0 | app body, tables |
| `body-sm` | Inter | 13/18 | 400/500 | 0 | meta, helper text |
| `caption` | Inter | 12/16 | 500 | 0.01em | badges, axis labels (minimum size) |
| `kpi` | Inter | 30/36 | 650 | −0.01em | KPI numbers |
| `amount-lg` | Inter | 36/40 | 700 | −0.015em | invoice/payout amount on detail pages |
| `mono` | JetBrains Mono | 13/20 | 500 | 0.02em | IBAN, references, keys |

Numeric rules: amounts, counts and dates in tables/KPIs use `tabular-nums` (`font-feature-settings: "tnum" 1, "case" 1` on Inter), amounts are
right-aligned in tables, currency formatting only through `useFormat().money()` (Intl) — never string concatenation; negative amounts exactly as
Intl formats them (`-$12.50`, never parentheses); IBANs in mono grouped by 4; percentages with ≤ 1 decimal. Line length ≤ 72ch (marketing) / ≤ 90ch (app text blocks).

## 5. Layout, spacing, radii, elevation

- Spacing scale (4 px): `1=4, 2=8, 3=12, 4=16, 5=20, 6=24, 8=32, 10=40, 12=48, 16=64, 24=96`. App content padding 24 (16 below 768).
- Breakpoints: 375 (min supported), `sm 640`, `md 768`, `lg 1024`, `xl 1280`, `2xl 1440`. No horizontal page scroll at any width; wide tables
  become card rows below `md` (only the pricing comparison table scrolls inside its own container with an edge shadow).
- App shell: **two-tier top-bar "ledger" shell** (§5.1) — tier 1 56 px, tier 2 44 px, no persistent left sidebar ≥ `lg`; content max 1440.
  Marketing container max 1200 (+ 24 gutters), hero max 1280.
- z-index: `sticky 10`, `header 20`, `dropdown 30`, `overlay 40`, `dialog 50`, `toast 60`.
- Radii: `--radius: 0.75rem` → `sm 8`, `md 10`, `lg 12` (inputs, buttons), `xl 16` (cards), `2xl 24` (bento tiles, device frames), `full` (pills).
- Shadows light: `--shadow-xs: 0 1px 2px oklch(0.2 0.04 264 / 0.06)`; `--shadow-sm: 0 1px 3px oklch(0.2 0.04 264 / 0.08), 0 1px 2px oklch(0.2 0.04 264 / 0.04)`;
  `--shadow-md: 0 8px 20px -6px oklch(0.2 0.04 264 / 0.14)`; `--shadow-lg: 0 18px 44px -14px oklch(0.2 0.04 264 / 0.20)`;
  `--shadow-brand: 0 0 0 1px oklch(0.596 0.127 163 / 0.25), 0 10px 30px -10px oklch(0.596 0.127 163 / 0.40)` (landing CTA, public Pay button, Pro plan).
- Shadows dark: hierarchy via lighter surfaces + borders; `--shadow-md: 0 10px 24px -8px oklch(0 0 0 / 0.55)`, `--shadow-lg: 0 20px 48px -16px oklch(0 0 0 / 0.7)`;
  cards get `inset 0 1px 0 oklch(1 0 0 / 0.04)`.

### 5.1 Layout language — PayFlow's own UI, not a recolor (PLATFORM §0.10, §9)

PayFlow is about money moving in one direction and being stopped in the other, so its layout reads like a **ledger with two lanes**.
These rules are binding for every app page and are what visually separates PayFlow from the other two products of the platform:

| Dimension | **PayFlow Guard** (this spec) | OpsBot (for contrast) | ScamPause Business (for contrast) |
|---|---|---|---|
| Shell | **Two-tier top bar**, no left sidebar on ≥ `lg`: tier 1 = logo, workspace switcher, **module switcher** (segmented: Receivables · Payment Guard · Assistant), ⌘K, alerts bell, locale, theme, user; tier 2 = the active module's tabs with count pills + a right-aligned **cash strip** (Outstanding · Overdue · Awaiting my approval, `xl`+) | chat-first, rounded left sidebar | ink "console" sidebar in both themes |
| Lanes | **Money in** (Receivables) = emerald accent line under tier 2 and emerald active tab; **money out** (Payment Guard) = ink tier-2 band with amber active indicator in **both themes** — you always see which lane you are in | — | — |
| Density | comfortable-dense: table rows 44 px (36 px compact on dashboard lists), card padding 20, 12-column grid, 24 gaps | airy, conversational | very dense forensic grids |
| Radii | soft fintech: inputs/buttons 12, cards 16, bento/device frames 24, pills full | clay 20 everywhere | sharp 2–6 |
| Typography | Manrope display + Inter UI + JetBrains Mono for references; **amount-first headers** (`amount-lg` next to identifier) and tabular numbers | Unbounded titles + Onest | IBM Plex + mono data |
| Signature motifs | the emerald **flow line** connecting stage chips (Sent → Viewed → Reminded → Paid), the **two-key approval stepper**, bank-detail **diff table** in an ink strip | citation chips, chat bubbles | hazard stripes, grid lines, case files |
| Detail pages | 8/4 grid: document on the left, **decision rail** (risk, tone, next reminder, link, approvals) on the right; on mobile the rail comes first | thread + sources | exhibits in tabs |
| Illustration | inline SVG, 1.5 px strokes, two-tone emerald/ink fills, one flow line per drawing (§8) | soft clay shapes | forensic line diagrams |
| Landing | aurora bento + live product-demo hero (real components) + Guard band in ink + Telegram device frame (§11) | chat-led hero | console-led hero |

**Responsive shell.** `lg`+: both tiers visible. `md`: tier 2 tabs that don't fit go into a "More" menu (never a horizontal scroller).
Below `md`: tier 1 keeps logo, module switcher as an icon+label select, bell and user menu; tier 2 is replaced by a **bottom tab bar** (64 px,
safe-area aware): Overview · Invoices · Guard · Assistant · More (sheet with the remaining items and settings). Touch targets ≥ 44 px.

**Implementation.** `resources/js/layouts/PayflowAppLayout.vue` (+ `components/pf/shell/{ModuleSwitcher,ModuleTabs,CashStrip,BottomTabBar}.vue`)
replaces the foundation `AppLayout.vue` (shadcn `SidebarProvider` + `AppSidebar`) for all authenticated pages through the layout map in
`resources/js/app.ts` (DOMAIN_CONTRACT K.4 F9). It renders the **same data** as `appNavigation()` (D.5 groups → modules, items → tabs, badges
from `pf.nav_counts`), keeps `SkipLink`, `CommandPalette`, `AppToaster`, breadcrumbs (tier 2, left of the tabs on `xl`) and the foundation
`data-test` hooks. Settings pages render the foundation settings sub-nav inside this shell. Focus pages (step-up, onboarding) keep
`FocusLayout`; public invoice and trusted-confirmation pages use their own document layouts (§10).

## 6. Motion

| Token | Value | Use |
|---|---|---|
| `--duration-instant` | 80 ms | press feedback |
| `--duration-fast` | 150 ms | hover colours, focus ring |
| `--duration-base` | 220 ms | dropdowns, tabs, accordion, toasts, status cross-fades |
| `--duration-slow` | 320 ms | dialogs, sheets, stepper fill |
| `--duration-reveal` | 600 ms | landing section reveal, KPI count-up |
| `--duration-hero` | 1200 ms | hero flow-line draw |
| `--ease-out` | `cubic-bezier(0.16, 1, 0.3, 1)` | entering |
| `--ease-in` | `cubic-bezier(0.7, 0, 0.84, 0)` | exiting |
| `--ease-in-out` | `cubic-bezier(0.65, 0, 0.35, 1)` | moving between states |

Rules: animate `transform`/`opacity` only; at most 2 animated elements per app view; no infinite decorative loops (spinners only); KPI count-up only
on first page visit (not partial reloads); status changes (e.g. invoice → Paid) = 220 ms colour cross-fade + icon swap; approval stepper fills its
segment in 320 ms; the "awaiting your approval" dot pings **twice** then rests; hover never scales cards — border/shadow change, inner elements may
translate ≤ 2 px. Under `prefers-reduced-motion: reduce`: durations → `0.01ms` except opacity fades ≤ 120 ms; count-ups show final values; flow lines
render fully drawn; aurora drift and scroll-linked effects off; ECharts `animation: false`. Under `prefers-contrast: more`: chart decals on, solid badges.

## 7. Iconography

`@lucide/vue` for every UI icon (no emoji, no stickers, no icon fonts). Third-party brand marks (Telegram, Stripe, Visa, Mastercard) come only
from the `simple-icons` npm package (local SVG paths, PLATFORM §0.8), only next to the brand name and only where the brand is the subject
(integration cards, the public pay panel). 16 px in dense UI, 20 px in buttons/nav, 24 px in cards and
empty states; `stroke-width` 1.75 (2 at 16 px); colour inherits text. Icon-only buttons get `aria-label` + tooltip. Mapping (in addition to §3.4):

| Concept | Icon | Concept | Icon |
|---|---|---|---|
| Overview | `LayoutDashboard` | Payout | `HandCoins` |
| Invoice | `FileText` | Vendor | `Store` |
| Client | `Building2` | Bank details / change | `Landmark` / `ArrowLeftRight` |
| Reminder | `BellRing` | Lookalike domain | `AtSign` |
| Analytics | `ChartColumn` | Holder name mismatch | `UserX` |
| Forecast | `ChartSpline` | Country changed | `Globe` |
| Payment link | `Link2` | Urgency / secrecy | `Timer` / `EyeOff` |
| Card payment | `CreditCard` | Cooling-off | `Hourglass` |
| Guard | `ShieldCheck` | Callback verification | `PhoneCall` |
| Approve / reject | `BadgeCheck` / `CircleX` | Release | `Send` |
| AI / insight | `Sparkles` / `Lightbulb` | Citation | `Quote` |
| Live data | `Activity` | Telegram | `Send` |
| Import / export | `Upload` / `Download` | Audit | `ScrollText` |

## 8. Illustration

Inline SVG Vue components in `resources/js/components/illustrations/` — no stock photos, no photos of people, no raster art. Style: 1.5 px strokes in
`currentColor` (`--foreground` at 70 %), two-tone fills from `--accent` and `--brand` (light) / `--accent` and `--primary` (dark), one emerald
**flow line** motif per illustration, 12 px rounded geometry, 160×120 viewBox for empty states, `aria-hidden="true"` (adjacent text carries meaning).
Set: `EmptyInvoices` (stacked papers + flow line), `EmptyClients`, `EmptyReminders` (bell on a timeline), `EmptyVendors`, `EmptyPayouts`
(shield + coin), `AllCaughtUp` (check with radiating flow lines), `NoResults`, `LockedFeature` (padlock on aurora), `ErrorState` (broken link),
`PaymentReceived` (receipt + check, public thanks page), `GuardShield` (landing Guard band), `HeroFlow` (landing hero line system).
Every illustration is checked on both themes and must not rely on a white background.

## 9. Components (shadcn-vue base, domain extensions in `resources/js/components/pf/`)

**Buttons** — variants `default` (primary), `secondary`, `outline`, `ghost`, `link`, `destructive`, `insight` (violet `ai` tokens; AI actions only). Heights
`sm 32` (dense desktop tables; hit area padded to 44 on touch), `default 40`, `lg 48` (landing, public Pay). Radius `lg`. Loading: spinner replaces
the leading icon, label stays, `aria-busy`, disabled. One primary per region. Destructive actions always confirm. Public Pay button: `lg`, full width on
mobile, `--shadow-brand` in light.

**Badges** — pill, `caption` text, 6 px icon gap, soft bg + strong fg (§3.4), min height 22; never colour-only. `RiskBadge` = band + score;
`ToneChip`, `ClassBadge`, `AgingChip`, `ApprovalProgress` (`1/2` with segment dots), `StatusBadge` per domain enum.

**Cards** — `rounded-xl border bg-card shadow-xs` (light) / border + inner highlight (dark). Variants: `KpiCard` (label, `kpi` number, delta chip
coloured by meaning — e.g. DSO down is good — and optional sparkline), `InsightCard` (ai-soft, `Sparkles`, one action link), `AlertCard`
(severity left border), `GuardCard` (ink header strip in both themes), `LockedCard` (blurred real component + upgrade CTA, never fake data).

**Tables** — shadcn DataTable (@tanstack/vue-table). Row height 44 (comfortable) / 36 (compact toggle); header `muted` bg, `body-sm` 500; numeric
columns right-aligned tabular; row hover `accent/40`, selected `accent`; bulk bar slides up (220 ms) with count + actions; below `md` rows render as
stacked cards (identifier, client/vendor, amount, status).

**Forms** — label above, helper below, validation on blur, errors in `destructive` with icon + `role="alert"`; required `*` with sr-only text.
`MoneyInput`: currency-code prefix chip, `inputmode="decimal"`, locale-aware separators on blur, emits a decimal string. `DateInput` in workspace
locale; `Combobox` for clients/vendors with recent items. Inputs 40 px (44 on touch).

**Dialogs & sheets** — dialog max 520 (forms) / 640 (previews); right sheet 480 for reminder preview and record payment. Focus trap, `Esc` closes,
initial focus on the first field or the safest action. Irreversible confirmations state what, amount, recipient (last4) and consequence; releasing a
high-risk payout requires typing its number. **StepUpDialog** (every Guard decision, B.9.11): title "Confirm it's you" / «Подтвердите,
что это вы», states the action being confirmed, passkey button first, 6-digit TOTP field (`inputmode="numeric"`, `autocomplete="one-time-code"`),
password only when the workspace allows it; a missing second factor shows a setup link instead.

**BankDiffTable** — Before / After columns per field; changed rows `warning-soft` with `ArrowLeftRight`; account values masked in mono; holder name
compared inline with the vendor's legal name ("doesn't match PixelForge Studio sp. z o.o." / «не совпадает с PixelForge Studio sp. z o.o.»).
**SignalList** — icon, translated label, points as a mini bar (0–40 scale) + number; sorted by points; total score with band badge.
**CallbackPanel** — known phone in `title-md` mono with copy button, last verification date, warning "never use contacts from the request",
4 required checkboxes, method select, note. **ApprovalStepper** — one segment per required approval with approver initials, channel icon, time;
the viewer's slot highlighted; a trusted-contact approval shows `Mail` + the contact's name; approvers still inside the trust delay show
"eligible from {date}" / «может согласовать с {date}»; cooling-off shown as an amber striped segment with a countdown. **Timeline** — vertical, 2 px `border` line,
type-coloured dots, relative time with absolute tooltip, actor chip.

**Charts** (vue-echarts; theme built at runtime from CSS variables by `useChartTheme()`, rebuilt on theme change): Inter 12 px, axis labels
`muted-foreground`, split lines `border` at 60 %, no outer borders, tooltip = card surface + border + `shadow-md`, radius 10, tabular numbers,
Intl currency. Bars radius `[4,4,0,0]`, max width 28 px; lines 2 px with 15 % area; forecast "at risk" uses decal stripes; legends top-right and
clickable; empty data → illustration instead of empty axes. Each chart card has a "Table view" toggle and an `aria-label` summary sentence.

**Assistant chat & citations** — user bubble right (`accent` / `accent-foreground`), assistant left (card + border), max 72ch, safe Markdown.
Citation chip: pill `secondary` + border + `Quote` 14 px, "[1] Contract NSD-2025-031"; hover/focus opens a popover with snippet + page; click opens
the document. "Live PayFlow data · 10:42" / «Данные PayFlow · 10:42» chip in `info-soft` with `Activity`. Refused answer: muted card with
`ShieldAlert`, explanation and "Escalate" / "Open in app" outline button. Typing indicator = three fading dots (reduced motion → static "…").
Suggested questions as outline chips above the composer.

**Toasts** (vue-sonner) — bottom-right desktop, top on mobile; errors persist until dismissed; max 3 visible.

**States** — skeletons mirror the final layout; empty = illustration + one sentence + primary action; error = `ErrorState` + retry; filtered-empty
differs from truly-empty.

## 10. App page patterns

- **Shell**: the two-tier ledger shell of §5.1 — module switcher and tabs from DOMAIN_CONTRACT D.5 with count pills (warning / destructive /
  primary), cash strip, ⌘K, Guard alerts bell, RU/EN switch, light/dark/system toggle, workspace switcher in tier 1, user menu (links to Terms,
  Privacy, Cookies, DPA, Sub-processors, AUP, AI notice, Security, Contact — PLATFORM §10.2).
- **Page header**: `title-lg` + one-line muted subtitle; right: primary action + secondary dropdown (below `md` only the primary stays visible).
- **List pages**: filter bar (search with `/` shortcut, segmented status tabs with counts, filter popovers, currency select), DataTable, sticky bulk bar,
  pagination with page size.
- **Detail pages** (invoice, client, vendor, payout): identifier + status badge + `amount-lg` header; 8/4 grid ≥ `lg` — left content (items, payments,
  timeline), right decision cards (risk, tone, next reminder, late fee, payment link / bank account, approvals). On mobile decision cards come first.
- **Guard "forensic" variant** (bank-change review, payout detail): `--ink` header strip in both themes ("money leaves here"), mono identifiers, diff +
  signals first, sticky decision bar stating the consequence.
- **Settings**: left sub-nav, cards with title + description + form; danger zone with destructive border. Settings → Payments shows one gateway
  card (Stripe: restricted-key form with the permission checklist and per-permission check results, `…last4`, endpoint status, "Verify" and
  "Replace key"; or YooKassa shop credentials and notification URL; or Demo), the currencies it supports and the plain money-flow notice
  (payments settle in your own account, PayFlow never holds money); Settings → Guard shows eligible approvers with "eligible from", the trusted contact and
  the second-factor requirement (warning banner when off).
- **Public invoice**: document card (max 880), company + client blocks, items, totals, Pay CTA, bank-transfer details, PDF download, legal footer (privacy contact, reminder-recipients notice, "Already paid / stop automated reminders");
  print stylesheet
  forces light theme and hides chrome.
- **Auth**: split layout — form left, aurora panel with a live product mock (real components, demo data) right.

## 11. Landing page (`/`) — section by section (layout overrides in `pages/landing.md`)

| # | Section | Layout & content | Motion | Copy direction (EN / RU) |
|---|---|---|---|---|
| 1 | Header | floating sticky glass bar (16 px from top ≥ md); logo; nav Product · Payment Guard · Pricing · Security; locale + theme switch; "Sign in" ghost; "Start free" primary | blur/opacity on scroll > 8 px | Product / Продукт · Payment Guard / Защита платежей · Pricing / Тарифы · Security / Безопасность · Sign in / Войти · Start free / Начать бесплатно |
| 2 | Hero | copy 5 cols / visual 7 cols ≥ lg, stacked on mobile; aurora + dot texture; visual = real components: invoice card moving Sent → Viewed → Reminder (friendly) → **Paid**, floating Guard card "Bank details changed · High risk 100/100 · Needs 2 approvals", Telegram-style bubble "Approve PO-2026-0089?" | flow line draws (1200 ms); stage chips stagger 120 ms; badge cross-fades Overdue → Paid once at 2.4 s; Guard card slides 16 px + fades (400 ms, delay 600) | Eyebrow "For agencies & studios that bill clients and pay contractors" / «Для агентств и студий, которые выставляют счета и платят подрядчикам». H1 "Get paid on time. Stop risky payouts before they leave." / «Деньги — вовремя. Рискованные выплаты — под контролем.» Sub "PayFlow Guard chases overdue invoices in the right tone, predicts who will pay late and makes every risky payout wait for a second pair of eyes — next to the invoicing tool you already use." / «PayFlow Guard сам напоминает клиентам об оплате в нужном тоне, предсказывает, кто заплатит поздно, и не даёт провести рискованный платёж без второй пары глаз — рядом с вашей привычной системой счетов.» CTAs "Start free" / «Начать бесплатно», "Explore the demo" / «Посмотреть демо». Micro "No card required · Import from QuickBooks, Xero, FreshBooks" / «Без карты · Импорт из QuickBooks, Xero, FreshBooks» |
| 3 | Proof bar — **rendered only for entries in `config('marketing.stats_verified')`** (exact report title + year, plain text, no links to external hosts); hidden when empty | up to 3 stat tiles + source footnote | count-up once when in view (600 ms) | "59% of small businesses have invoices more than 30 days overdue" / «У 59% малых компаний есть счета с просрочкой больше 30 дней» · "$17.7k average outstanding" / «17,7 тыс. $ — средняя задолженность» · "39% say one late payment already hurt payroll or bills" / «39% говорят, что одна просрочка уже мешала платить зарплату или счета». Footnote from the verified entry: "Source: {report title}, {year}." / «Источник: {название отчёта}, {год}.» — the candidate figures above stay unpublished until verified (PLAN §4) |
| 4 | The chase today vs with PayFlow | two timelines (stacked on mobile): left muted dashed — copy the email, attach the PDF, set a calendar note, repeat every 4–5 days, hope the "new bank details" email is real; right emerald continuous | right line draws when in view | H2 "Stop chasing invoices by hand" / «Хватит вручную гоняться за оплатой» |
| 5 | Bento grid | 4 cols ≥ lg (2 on md, 1 on mobile), 8 tiles: (a, 2×2) tone ladder + reminder timeline with live RU/EN subject preview; (b) risk gauge 97/100 + factor chips; (c) systematically late clients mini bars; (d) payment link → Paid; (e, 2×1) bank-detail diff PL → LT with signals; (f) approval stepper 1/2 → 2/2; (g) assistant bubble with citation chip; (h) 8-week cash-in forecast | inner elements animate on hover/focus (chips shift, stepper fills); tiles never scale; reveal 600 ms, stagger 80 ms | H2 "Everything between 'invoice sent' and 'money in the bank'" / «Всё между „счёт отправлен“ и „деньги на счёте“». Tiles: "Reminders that know when to get firm" / «Напоминания, которые знают, когда стать твёрже»; "Risk you can explain" / «Риск, который можно объяснить»; "Who pays late — systematically" / «Кто платит поздно — систематически»; "Two keys for risky payouts" / «Два ключа для рискованных выплат»; "Ask your contracts" / «Спросите свои договоры» |
| 6 | How it works | 4 steps joined by a flow line (horizontal ≥ lg, vertical otherwise) | line draws on scroll; steps fade up | Import or create invoices · PayFlow plans reminders in each client's language · Clients pay by link, you get a Telegram ping · Payouts wait for the right approvals / Импортируйте или создайте счета · PayFlow планирует напоминания на языке клиента · Клиент платит по ссылке, вы получаете уведомление в Telegram · Выплаты ждут нужных согласований |
| 7 | Payment Guard band | full-bleed `--gradient-guard` (both themes) + noise; left copy + red-flag checklist (lookalike domains, holder mismatch, new country, urgency, shared accounts, cooling-off); right real BankDiffTable + SignalList + ApprovalStepper | signal bars grow one by one (stagger 60 ms) | H2 "Two keys for every risky payment" / «Два ключа для каждого рискованного платежа». Body "When a vendor 'changes bank details', PayFlow holds their payouts, scores the request and asks a second person to call the number you already trust." / «Когда поставщик „меняет реквизиты“, PayFlow приостанавливает выплаты, оценивает риск запроса и просит второго сотрудника позвонить по номеру, которому вы уже доверяете.» Micro "Reduces risk — the decision always stays with your team." / «Снижает риск — решение всегда остаётся за вашей командой.» |
| 8 | Telegram + AI | CSS device frame (no image) with a bot conversation in the current locale answering the four headline questions with a source line; side list of commands `/overdue`, `/approvals`, `/changes`, `/remind` | messages appear sequentially once (300 ms apart) | H2 "Ask in Telegram. Get answers with sources." / «Спросите в Telegram — получите ответ со ссылкой на источник.» |
| 9 | Works with your stack | text chips (brand marks only from simple-icons, next to the name): CSV from QuickBooks · Xero · FreshBooks, Stripe, YooKassa (RU), Telegram, your own AI provider — or none | — | "Keep your invoicing tool. Add the follow-through." / «Ваша система счетов остаётся. Добавляется доведение до оплаты.» |
| 10 | Pricing teaser | 4 plan cards from the `plans` prop, Pro highlighted, monthly/yearly toggle | toggle cross-fade | "Simple pricing from $19/month" / «Простые тарифы от 19 $ в месяц» |
| 11 | Security & trust | 6 tiles: encrypted bank details, four-eyes approvals, audit log, card data stays with Stripe, works without AI keys, data export | — | link to `/security` |
| 12 | FAQ | accordion, 6 questions: does it replace QuickBooks? where does client money go? (straight to your own Stripe account through Payment Links PayFlow creates with a restricted key you control, or your own YooKassa shop — PayFlow never holds it; refunds, disputes and fees stay between you and your provider) can it guarantee no fraud? (honest "no — it reduces risk", links the payment-guard disclaimer) Russian support? need Stripe? need AI? | 220 ms | |
| 13 | Final CTA | aurora band, H2 + primary CTA + demo link | — | "Your next invoice can get paid on time — without you chasing it." / «Следующий счёт может оплатиться вовремя — без ваших напоминаний.» |
| 14 | Footer | product, company, legal: Terms · Privacy · Cookies · DPA · Sub-processors · AUP · AI notice · Security · Contact, plus Reminder recipients and Payment Guard disclaimer; locale + theme switch; © | — | PLATFORM §10.2 |

SEO per locale with `hreflang`: title EN "PayFlow Guard — get paid on time, stop risky payouts", RU «PayFlow Guard — оплата счетов вовремя и контроль
рискованных выплат»; description ≤ 155 chars; OG image generated locally from the hero composition (one per locale). Rules: no fabricated
testimonials, ratings or customer logos; every statistic is verified (`marketing.stats_verified`) and names its source; no "guaranteed"/"100 %" claims; content renders from initial props; zero
external requests.

## 12. Pricing & security pages

**Pricing**: hero (`display-lg`), monthly/yearly toggle ("2 months free" / «2 месяца в подарок»), cards Free · Starter $19 · Pro $49 (highlighted,
`--shadow-brand`, "Most popular" / «Популярный») · Business $99, comparison table generated from `config/billing.php` limit keys (sticky header,
own scroll container on mobile), note "Security features are included in every plan" / «Функции безопасности есть во всех тарифах», FAQ.
**Security**: Encryption · Four-eyes approvals · Audit log · Payments (Stripe / YooKassa hosted pages on your own account, we never see card data, we never hold or move your money; the restricted key can't move funds and you can revoke it) · AI (mock by
default, your provider optional, bank details never sent to AI) · Data location & retention · Privacy controls (export, deletion, recipient opt-out) · Payment Guard disclaimer · Responsible disclosure.

## 13. Accessibility checklist & anti-patterns

Checklist (both themes): text ≥ 4.5:1 and UI/graphics ≥ 3:1 (§3); visible `--ring` focus (2 px + 2 px offset) on every interactive element; full
keyboard paths for create invoice, record payment, verify bank change, approve payout; skip-to-content link; landmarks; `aria-live="polite"` for async
results (payment received, reminder queued), `role="alert"` for errors; labels on all inputs; 44×44 touch targets; `cursor-pointer` on clickables;
tooltips on icon buttons; chart table views; correct `lang` attribute per locale; RU hyphenation (`hyphens: auto`) only in long text blocks.

Never: load fonts/scripts/images from any CDN (Google/Bunny fonts, unpkg, hotlinked images, Gravatar); use emoji as icons; show colour-only status;
animate layout properties; loop decorative animations; place aurora behind data; use pure black surfaces; invert the light theme for dark; fabricate
testimonials, logos or ratings; promise "guaranteed" fraud prevention or use absolute claims ("never pay a fraudster"); show full bank details by default; put two primary buttons in one region;
use light greys (≈ `#94A3B8`) for body text in light mode.

## 14. Implementation (`resources/css/brand.css`, mapped via `@theme inline` in `app.css`) — superset migration

`brand.css` keeps its structure (`:root, .light { … }` and `.dark { … }`, fontsource imports at the top) and **every token it already defines**
(§3.0). brand-landing applies the following in one PR:

1. **Retune values** of existing tokens towards §3.2/§3.3 where the contrast checker or the brand anchors require it (e.g. `--warning-soft-foreground`
   light `oklch(0.53 0.12 62)`, `--ai-soft-foreground` light `oklch(0.51 0.22 293)`, dark values per §3.3). Keep `--warning` as the amber fill
   (`oklch(0.77 0.16 70)` light / `oklch(0.8 0.16 75)` dark) and `--warning-foreground` as text on it.
2. **Add new tokens** in both blocks:
```css
:root, .light {
  --brand: oklch(0.596 0.127 163);
  --ink: oklch(0.2 0.04 264);              --ink-foreground: oklch(0.97 0.005 250);
  --risk-low: var(--success);  --risk-medium: var(--warning);  --risk-high: var(--destructive);
  --risk-low-soft: var(--success-soft);  --risk-medium-soft: var(--warning-soft);  --risk-high-soft: var(--destructive-soft);
  --risk-low-soft-foreground: var(--success-soft-foreground);  --risk-medium-soft-foreground: var(--warning-soft-foreground);
  --risk-high-soft-foreground: var(--destructive-soft-foreground);
  --chart-7: oklch(0.6 0.1 195);   --chart-8: oklch(0.6 0.02 260);
  --aging-0: oklch(0.6 0.12 163); --aging-1: oklch(0.66 0.135 90); --aging-2: oklch(0.66 0.16 62); --aging-3: oklch(0.6 0.19 42); --aging-4: oklch(0.52 0.2 25);
  --logo-from: oklch(0.65 0.14 163); --logo-to: oklch(0.58 0.11 190); --logo-flow: oklch(0.99 0.01 165);
  --gradient-guard: linear-gradient(180deg, oklch(0.23 0.04 264), oklch(0.16 0.03 264));
  --shadow-brand: 0 0 0 1px oklch(0.596 0.127 163 / 0.25), 0 10px 30px -10px oklch(0.596 0.127 163 / 0.40);
  --texture-noise: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/></filter><rect width='100%' height='100%' filter='url(%23n)' opacity='.35'/></svg>");
  /* readability aliases only — components use the ai utilities */
  --insight: var(--ai); --insight-foreground: var(--ai-foreground); --insight-soft: var(--ai-soft); --insight-soft-foreground: var(--ai-soft-foreground);
}
.dark {
  --brand: oklch(0.8 0.145 163);
  --ink: oklch(0.115 0.02 265);            --ink-foreground: oklch(0.97 0.005 250);
  --chart-7: oklch(0.8 0.1 190);   --chart-8: oklch(0.62 0.02 260);
  --aging-0: oklch(0.76 0.14 163); --aging-1: oklch(0.86 0.13 95); --aging-2: oklch(0.8 0.15 72); --aging-3: oklch(0.72 0.17 48); --aging-4: oklch(0.66 0.19 25);
  --shadow-brand: 0 0 0 1px oklch(0.8 0.145 163 / 0.3), 0 10px 30px -10px oklch(0 0 0 / 0.6);
}
```
3. **`@theme inline` in `app.css`** gains `--color-<token>` for every new colour token (`brand`, `ink`, `ink-foreground`, `aging-0..4`, `risk-*`,
   `chart-7`, `chart-8`) so `bg-aging-3`, `text-ink-foreground`, `bg-risk-high-soft` work; existing mappings are untouched.
4. **Guards in CI** (brand-landing ships both, run in `npm run lint`):
   - `scripts/contrast-check.mjs` parses the merged `brand.css`, converts OKLCH → sRGB and fails below the thresholds of §3 (text pairs 4.5:1,
     graphics 3:1) for both themes, including every `X-soft` / `X-soft-foreground` and `X` / `X-foreground` pair.
   - `scripts/check-tokens.mjs` scans `resources/js/**/*.{vue,ts}` for colour utilities (`bg-`, `text-`, `border-`, `ring-`, `fill-`, `stroke-`,
     `from-`, `via-`, `to-`, `outline-`, `decoration-`, `shadow-`) whose token part is not a Tailwind palette colour and fails when
     `--color-<token>` is missing from `@theme` — so a renamed token can never silently drop backgrounds or text colours.

Components use utility classes with tokens only — no raw hex/oklch in `.vue` files (the only exception is the logo SVG, which reads `--logo-*`
variables). E-mails and PDFs cannot use CSS variables: they use the hex anchors from `config('product.email')`.
