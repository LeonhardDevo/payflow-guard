# Public Invoice Overrides (`public/Invoice`, `public/PaymentThanks`)

> Trust signals, clear primary CTA, submit feedback. No "Enterprise Gateway / Contact Sales" structure. Overrides
> `../MASTER.md` for the client-facing pages.

## Layout overrides

- Minimal public layout: top bar with the **workspace** name (not PayFlow branding), locale switch (defaults to the invoice locale) and theme toggle;
  footer in muted `caption`: gateway line by `pay_label` ("Secure card payments by Stripe" / «Безопасная оплата через YooKassa» / "Demo
  payment — no real money"), "Sent by {company} via PayFlow Guard", privacy contact, links to Terms, Privacy and `/legal/reminder-recipients`,
  and "Already paid or want to stop automated reminders?" → `/i/{token}/reminders` (DOMAIN_CONTRACT B.6.8, D.4.8).
- Document card max 880 px, padding 40 (24 on mobile), radius `xl`, `shadow-md` in light / border in dark.
- Order: header (company block left; "Invoice NS-2026-0118" + status badge + dates right) → bill-to block → items table → totals (subtotal, tax,
  total, paid, **balance due** in `amount-lg`) → pay panel → bank-transfer details → notes/terms → PDF download.
- Pay panel: `lg` primary "Pay {amount}" / «Оплатить {amount}» with `CreditCard`, full width on mobile, `--shadow-brand` in light; a "Test mode — no real
  money" / «Тестовый режим — без реальных денег» ribbon when `provider_mode = test`.

## States

- Not payable online (`payable_reason`): bank-transfer details only, with a neutral line — "Online payment isn't available for this invoice"
  / «Онлайн-оплата для этого счёта недоступна» (no internal reason shown to the client).

- Overdue: amber notice "This invoice was due on {date}" / «Срок оплаты этого счёта — {date}» (factual, no threats).
- Paid: success banner + `PaymentReceived` illustration; pay panel hidden.
- Void: muted banner "This invoice was cancelled" / «Этот счёт аннулирован», amounts struck through. Disputed: info banner "Under review with {company}".
- Thanks page: spinner → "Payment received — thank you!" / «Оплата получена — спасибо!» within ≤ 60 s of polling; if still processing:
  "Your payment is being confirmed. You'll receive a receipt by email." / «Платёж подтверждается. Квитанция придёт на почту.»
  Never show internal data (risk, reminders, notes).
- Reminder choices page (`public/ReminderChoices`): same card layout; two clearly separated actions — "I've already paid" (optional date +
  reference) and "Stop automated reminders" (consequence stated: "{company} will be notified; you may still receive invoices and personal
  messages"); confirmation state after each; no dark patterns (equal visual weight, no pre-checked boxes).
- Trusted confirmation page (`trusted/Confirm`): workspace name, requester, vendor, amount, last 4 digits, risk band in words, Confirm /
  Decline with the consequence of each, disclaimer link; expired/used states.

## Print

`@media print`: force light tokens, hide the top bar, pay panel and toggles, show bank details and the URL of the online invoice.
