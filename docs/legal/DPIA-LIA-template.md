# DPIA / LIA template — PayFlow Guard processing (for customers acting as controllers)

> **Template — must be reviewed by a qualified lawyer for your jurisdiction before use.** PayFlow Guard's operator provides it to help
> workspaces (controllers) document two processing activities that PayFlow performs for them as processor: **bank-detail change fraud
> scoring / payout approval** and **late-payment profiling / automated payment reminders**. Fill the bracketed fields. A Russian translation is
> required before offering it to RU customers (DOMAIN_CONTRACT L.7).

## 0. Document control

| Field | Value |
|---|---|
| Controller (workspace legal entity) | [company name, registration no., address] |
| Contact for data protection | [name, e-mail — also used as `privacy_contact_email` in PayFlow] |
| Processor | [PayFlow operator from `config/legal.php`], under the PayFlow DPA |
| Version / date / next review | [v1.0 / YYYY-MM-DD / +12 months or on material change] |

## Part A — Payment Guard: bank-detail change scoring and payout approvals

### A.1 Description of the processing
- **Purpose:** prevent payments to fraudsters (business e-mail compromise, "our bank details changed" fraud) by scoring change requests,
  requiring call-back verification by a second person, cooling-off periods and four-eyes approval of outgoing payments.
- **Data subjects:** vendors and contractors who are natural persons or sole proprietors; vendor contact persons; workspace members who
  request, verify, approve and release payments; an optional trusted contact (solo mode).
- **Data:** vendor name, contact e-mail and phone, bank account details (encrypted, masked by default), account holder name, the text of
  the change request and the address/number it came from, risk signals and scores, verification method and notes, approval records with
  timestamp, channel, IP address and user agent.
- **Sources:** entered by workspace members; requests forwarded by vendors (e-mail, phone, documents).
- **Recipients:** workspace members according to role; PayFlow as processor; sub-processors listed at `/legal/subprocessors` (hosting,
  e-mail; Telegram only if members link it — receives masked data only; LLM providers only if enabled — receive no holder names or account
  numbers).
- **Retention:** bank-account versions are kept with the financial records [statutory period]; request text and requester contact are deleted
  3 years after the version is superseded or rejected; approval IP/user agent after 365 days (DOMAIN_CONTRACT L.2).

### A.2 Necessity and proportionality
- Lawful basis: legitimate interest (Art. 6(1)(f) GDPR) of the controller in preventing fraud and protecting its funds; for RU — [basis under
  152-ФЗ, e.g. performance of contract with the vendor / legitimate interest of the operator].
- Data minimisation: only data needed to pay and to detect inconsistent changes; full account numbers are revealed only to authorised roles
  after step-up authentication and the reveal is logged.
- Accuracy: changes are versioned; the previous verified details stay visible; verification requires calling the number recorded **before**
  the request.
- No solely automated decision with legal or similarly significant effect: the score only routes the request to people; a human always
  verifies, approves or rejects (Art. 22 GDPR not triggered). [Confirm.]

### A.3 Legitimate interest assessment (LIA)
| Test | Assessment |
|---|---|
| Purpose test — is the interest legitimate? | [Preventing payment fraud and protecting company funds — yes.] |
| Necessity test — is the processing necessary, is there a less intrusive way? | [Scoring uses data the controller already holds to pay the vendor; manual checks without scoring were less reliable.] |
| Balancing test — reasonable expectations, impact, safeguards | [Vendors expect their bank details to be checked before payment; impact is limited to a delay; safeguards: encryption, masking, role-based access, audit, retention limits, no profiling outside payment safety.] |

### A.4 Risks to data subjects and mitigations
| Risk | Likelihood / severity | Mitigation in PayFlow | Residual |
|---|---|---|---|
| Disclosure of bank details | [low / high] | AES-256-GCM encryption, masked display, reveal with step-up + audit, never sent to Telegram, AI or e-mail | [low] |
| Incorrect "high risk" label delaying a legitimate payment | [medium / low] | explainable signals, human verification, quick resolution path, cooling-off limited to hours | [low] |
| Insider misuse (approving own changes, sockpuppet approvers) | [low / high] | verifier ≠ requester (DB constraint), owner-only privileged roles, 72 h trust delay, alerts to all owners, second-factor step-up | [low] |
| Excessive retention of request texts | [medium / low] | automatic purge (L.2) | [low] |

## Part B — Late-payment profiling and automated reminders

### B.1 Description of the processing
- **Purpose:** get invoices paid on time: plan reminders, choose a respectful tone, estimate the probability of late payment, show which
  clients pay late.
- **Data subjects:** client contact persons; clients who are natural persons or sole proprietors.
- **Data:** client and contact names, e-mail addresses, invoice and payment history, days late, whether and when the public invoice page was
  opened (if view tracking is enabled), reminder history, late-payer class and risk score.
- **Recipients:** workspace members; PayFlow as processor; e-mail delivery sub-processor; payment providers for online payments.
- **Retention:** invoices and payments per statutory period; reminder bodies 365 days; view timestamps with the invoice; provider event
  payloads 30 days.

### B.2 Necessity, transparency and choice
- Lawful basis: performance of the contract / legitimate interest in being paid (Art. 6(1)(b)/(f) GDPR) [confirm per relationship].
- Transparency: every invoice and reminder e-mail links to the controller's privacy contact and the PayFlow notice for reminder recipients
  (`/legal/reminder-recipients`), which explains view tracking and payment-behaviour scoring.
- Choice: recipients can report "already paid" or stop automated reminders with one click; the team is notified and can still contact them
  personally. View tracking can be disabled by the controller.
- No automated decision with legal effect: scores only influence the order and tone of reminders proposed by rules; late fees are applied
  only by a human and only when agreed in the contract.

### B.3 Risks to data subjects and mitigations
| Risk | Likelihood / severity | Mitigation in PayFlow | Residual |
|---|---|---|---|
| Harassment by excessive or aggressive reminders | [low / medium] | max reminders per policy, minimum interval, send window in the client's timezone, first automated contact never firmer than neutral, tone ceiling per client, opt-out | [low] |
| Unfair profiling ("systematically late") | [medium / low] | transparent rule-based classes with reasons, internal use only (never shown to the client), based on actual payment dates | [low] |
| Tracking without awareness | [medium / low] | disclosure in the notice; tracking can be turned off | [low] |
| Data sent to AI providers | [low / medium] | mock/local model by default; external providers only when allowed; PII redaction; placeholders instead of names in drafts | [low] |

## Part C — Conclusion and sign-off

| Question | Answer |
|---|---|
| Is the residual risk acceptable? | [yes / no — if no, consult the supervisory authority before processing] |
| Measures still to implement by the controller | [e.g. internal callback procedure, staff training, retention settings] |
| Approved by | [name, role, date] |
