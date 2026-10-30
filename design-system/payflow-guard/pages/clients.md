# Clients Overrides (`clients/Index`, `clients/Show`, `clients/Create`, `clients/Edit`)

> Charts: compare categories → sorted bars. UX: forms, number formatting. Overrides `../MASTER.md`.

## Index

- Segmented filter by payment behaviour: All · Systematically late · Sometimes late · Reliable · New (with counts), plus owner ("My clients") and paused.
- Columns: name + legal name · class badge · avg days late (tabular) · on-time % · open balance · overdue balance · open invoices · owner · paused icon.
- Default sort: overdue balance desc. Mobile: card rows.

## Show

- Header: client name, class badge (e.g. "Systematically late" / «Систематически платит поздно»), locale + currency chips; actions: Create invoice
  (primary), Pause reminders, Edit.
- KPI strip: open balance, overdue, avg days late, on-time rate, paid invoices.
- Payment-behaviour chart: one column per closed invoice (last 12) showing days late, reference line at 3 days (grace), bars coloured by
  `--aging-*` severity, table-view toggle.
- Terms card: payment terms, reminder policy (with "inherited" badge), tone ceiling, late-fee terms with source ("from contract" → KB document link).
- Tabs: Invoices · Reminders · Timeline · Notes.

## Form

Sections: Company & contacts · Billing (currency, locale, terms) · Collections (policy, tone ceiling, pause) · Late-fee override (toggle reveals the
fields plus a live example "€3,850.00, 7 days late → €26.95") · Contract document (KB picker) · Owner & tags · Notes.
