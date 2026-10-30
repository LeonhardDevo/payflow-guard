# Invoices Overrides (`invoices/Index`, `invoices/Show`, `invoices/Create`, `invoices/Edit`)

> UX: bulk actions, table handling, number formatting, inline validation, confirmation dialogs; shadcn DataTable + Form
> patterns. Overrides `../MASTER.md` for these pages.

## Index

- Filter bar: search (number, client, PO; `/` focuses), segmented tabs with counts `All · Outstanding · Overdue · Due soon · Draft · Paid · Disputed`,
  popovers for client, currency, risk, aging, due-date range; active filters as removable chips.
- Totals strip under the filters: outstanding and overdue per currency (tabular).
- DataTable columns: checkbox · number (`font-medium`) · client + class badge · issue date · due date · status badge (+ partial chip) · days overdue ·
  balance (right-aligned) · risk badge · next reminder (relative) · row menu. Sticky header; compact toggle.
- Bulk bar (sticky bottom): Send reminder · Pause reminders · Resume · Mark as sent · Export CSV; "{n} selected" with correct RU plurals.
- Mobile (< md): card rows — number + status (line 1), client (line 2), balance + due (line 3).
- The CLI's "comparison table vs competitors" and "yellow highlight rows" suggestions do not apply and are rejected.

## Show (detail)

- Header: number, status badge (+ partial chip), client link, `amount-lg` balance due with "of {total}"; primary action depends on state
  (draft → "Send invoice"; open/overdue → "Send reminder"; partially paid → "Record payment"); secondary: Record payment, Copy client link,
  Download PDF, More (Dispute, Pause reminders, Apply late fee, Void, Write off).
- Right column decision cards (top → bottom): Risk (score, band, top-3 factors with contribution bars, expected pay date) · Tone suggestion
  (chip + reasons) · Next reminder (workspace-tz time, tone, "Send now" / "Skip") · Late fee (amount, terms source, "Apply") · Payment link
  (status, amount, copy/refresh) · Client snapshot (class, avg days late, on-time %).
- Left column: items table (tabular, totals block), payments, reminders (status chips, open preview), timeline.
- Reminder preview sheet: client-locale preview (subject + rendered HTML in a sandboxed container), tone switcher, AI draft button (`insight`
  variant, Pro), warnings (recent reminder, no email, paused), Send.

## Create / Edit

- Two columns ≥ lg: form left (client combobox, dates with terms helper "Net 30 → due 21 Nov" / «30 дней → срок 21 нояб.», currency, locale, PO,
  items editor, notes/terms), live document preview right (same component as the public invoice); on mobile the preview becomes a tab.
- Items editor rows: description, quantity, unit price (`MoneyInput`), tax %, amount (read-only, tabular); Enter adds a row; drag to reorder with a
  keyboard alternative (move up/down buttons).
- Footer actions: "Save draft" (secondary) · "Save & mark as sent" (outline) · "Save & send" (primary). Plan-limit warning inline at 80 %.
