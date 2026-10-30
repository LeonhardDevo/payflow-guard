# Assistant Overrides (`assistant/Index`, `assistant/History`)

> Chat layout: narrow focused column, typing indicator, context cards. Overrides `../MASTER.md`.

## Layout overrides

- Chat column max 800 px centered; ≥ xl adds a 320 px right rail with "Sources in this conversation" (citation list) and "Try asking" chips.
- Composer sticky at the bottom with safe-area padding on mobile; Enter sends, Shift+Enter adds a line; character counter appears at 80 % of the limit.
- Empty state: `Sparkles` illustration + 4 suggested questions from `suggestions` (RU/EN): most overdue invoice, when to remind Helios Media,
  penalty under the Baltic Logistics contract, which bank details PixelForge changed.

## Component overrides

- Assistant messages show in order: answer (Markdown), citation chips, "Live PayFlow data · HH:MM" / «Данные PayFlow · ЧЧ:ММ» chip when
  `tools_used` is not empty, action buttons (e.g. "Open in app"), small meta (provider/model) in muted `caption`.
- Refused answers use the muted refusal card (`ShieldAlert`), never red.
- Typing indicator: three fading dots (no bounce); reduced motion → static "…".
- No auto-scroll when the user has scrolled up — show a "New message" pill instead.

## History page

Filters: status (answered/refused), channel (web/Telegram), search; table with question, confidence (bullet bar 0–1), citations count, tools-used chips,
channel icon, time. Stats strip: total, answered, refused, average confidence.
