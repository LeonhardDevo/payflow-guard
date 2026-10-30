# Landing Page Overrides (`/`, `marketing/Landing`)

> **PROJECT:** PayFlow Guard · landing structure: "Product Demo + Features", "Bento Grid Showcase", "Hero + social proof"
> · Rules here **override** `../MASTER.md` for this page only. Section-by-section content and copy: MASTER §11.

## Layout overrides

- Container max 1200 px (+ 24 px gutters); hero max 1280 px; full-bleed bands for the Guard section (§7) and the final CTA.
- Section rhythm: 96 px vertical padding ≥ lg, 64 px md, 48 px mobile. Section titles `display-xl` (mobile 32/38).
- Hero: 12-col grid, copy cols 1–5, visual cols 6–12 (≥ lg); stacked below lg with the visual **after** the CTAs; visual height capped at 560 px.
- Bento: 4 columns ≥ lg (tile a 2×2, tile e 2×1), 2 columns md, 1 column mobile; tile radius `2xl`, padding 24, min height 220.
- Header floats 16 px from the top ≥ md (glass), full-width bar with a sheet menu on mobile; content never hides behind it (`scroll-padding-top: 88px`).

## Rejected CLI suggestions

- Horizontal-scroll "journey" section — rejected (keyboard/screen-reader cost; no-horizontal-scroll rule).
- Testimonial carousel — rejected (no real customers yet; fabricated quotes are forbidden). Social proof = statistics listed in `config('marketing.stats_verified')` with exact report title and year; the proof bar is hidden while
  none is verified (the QuickBooks 2026 figures from the brief are unverified).
- Gold/purple palette and OLED-dark-only — replaced by brand tokens; both themes designed.
- WebP hero imagery — not needed: the hero is built from real Vue components and inline SVG, no raster images.

## Colour overrides

- Hero background: `--background` + `--aurora` + masked `--texture-dots`; dark theme uses the dark aurora alphas.
- Guard band (§7) uses `--gradient-guard` with `--ink-foreground` text in **both** themes; inner cards `oklch(0.25 0.03 264)` with border `oklch(1 0 0 / 0.08)`.
- Primary CTA gets `--shadow-brand` in light theme only.

## Motion overrides (disabled or reduced under `prefers-reduced-motion`)

- Hero: flow-line draw 1200 ms (`--ease-out`), stage chips stagger 120 ms, a single Overdue → Paid cross-fade at 2.4 s, Guard card slide-in 400 ms (delay 600).
- Sections reveal once via IntersectionObserver (opacity 0 → 1, translateY 12 px → 0, 600 ms, stagger 80 ms); no re-trigger on scroll up.
- Proof-bar count-up once (600 ms). Telegram conversation messages appear 300 ms apart, once.
- At most one running animation group in the viewport at a time; nothing loops.

## Page-specific components (`resources/js/components/marketing/`)

`MarketingHeader`, `HeroFlowComposition` (real `InvoiceStatusBadge`, `ToneChip`, `RiskBadge`, `ApprovalStepper` with static demo props),
`ProofStats`, `ChaseTimelineCompare`, `BentoGrid` + 8 `Bento*` tiles, `HowItWorksFlow`, `GuardBand`, `TelegramDeviceMock` (CSS frame),
`StackChips`, `PricingTeaser` (reads `plans`), `TrustGrid`, `FaqAccordion`, `FinalCta`, `MarketingFooter`.

## Copy guardrails

- Hero H1 and SEO title use the non-absolute tagline (MASTER §1); Guard copy says "reduces risk" and links the payment-guard disclaimer.
- Footer carries the full legal link set (MASTER §11 #14); no third-party logos except simple-icons marks next to their names.

## Acceptance

Lighthouse ≥ 90 (performance, accessibility, best practices, SEO) for `/` in ru and en, light and dark; axe without serious/critical issues;
at 375 px no horizontal scroll and the hero CTA is above the fold; zero external requests; `hreflang` + localized OG tags present.
