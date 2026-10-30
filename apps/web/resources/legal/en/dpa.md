---
title: 'Data Processing Agreement'
version: '1.1'
effective_date: '2026-10-23'
summary_of_changes: 'Added the PayFlow Guard product sections.'
requires_acceptance: true
---

<!-- template-notice -->

This Data Processing Agreement ("DPA") forms part of the Terms of Service between {{company.name}}, {{company.address}} ("Processor"), and the customer that owns the workspace ("Controller") for the use of {{product.name}} (the "Service"). The workspace owner accepts this DPA on behalf of the Controller when creating a workspace. This DPA implements Article 28(3) of the GDPR and the UK GDPR and, for Russian personal data, constitutes the operator's instruction under Article 6(3) of Federal Law No. 152-FZ.

## 1. Definitions

Terms such as "personal data", "processing", "controller", "processor", "data subject", "personal data breach" and "supervisory authority" have the meanings given in the GDPR. "Customer Personal Data" means personal data in customer content that the Processor processes on behalf of the Controller. "Sub-processor" means a third party engaged by the Processor to process Customer Personal Data.

## 2. Subject matter, duration, nature and purpose

The Processor processes Customer Personal Data only to provide the Service under the Terms, for the duration of the Terms and until deletion under section 11. The details are described in Annex 1.

## 3. Controller instructions

3.1. The Processor processes Customer Personal Data only on documented instructions from the Controller, including with regard to transfers to third countries, unless required to do so by applicable law; in that case the Processor informs the Controller of that legal requirement before processing unless the law prohibits it. The Terms, this DPA, the Controller's configuration of the Service (for example roles, integrations, the external AI provider setting and retention settings) and actions of its members in the Service are the Controller's complete instructions.

3.2. The Processor immediately informs the Controller if, in its opinion, an instruction infringes applicable data protection law.

3.3. The Controller is responsible for the lawfulness of the processing, including having a legal basis and providing notices to data subjects.

## 4. Confidentiality

The Processor ensures that persons authorised to process Customer Personal Data have committed themselves to confidentiality or are under an appropriate statutory obligation of confidentiality, and have access only to the extent necessary.

## 5. Security

The Processor implements the technical and organisational measures in Annex 2 (Article 32 GDPR). The Processor may update the measures provided that the overall level of security is not reduced.

## 6. Sub-processors

6.1. The Controller grants general authorisation to engage the Sub-processors listed in Annex 3. Sub-processors that are conditional on a Controller setting (for example messaging or external AI providers) process Customer Personal Data only if the Controller enables them.

6.2. The Processor notifies the Controller of any intended addition or replacement of Sub-processors at least 30 days in advance by e-mail or in the Service and by updating {{product.url}}/legal/subprocessors. The Controller may object on reasonable data protection grounds within that period. If the parties cannot resolve the objection, the Controller may terminate the affected part of the Service and receive a prorated refund of prepaid fees.

6.3. The Processor imposes on each Sub-processor, by contract, data protection obligations that are no less protective than this DPA, and remains liable to the Controller for the Sub-processor's performance.

## 7. Assistance with data subject requests

Taking into account the nature of the processing, the Processor assists the Controller by appropriate technical and organisational measures in responding to data subject requests. The Service provides self-service tools for access, export, rectification and deletion. If the Processor receives a request directly, it forwards it to the Controller without undue delay and does not respond itself except to confirm the forwarding.

## 8. Assistance with compliance

The Processor assists the Controller in ensuring compliance with Articles 32 to 36 GDPR (security, breach notification, data protection impact assessments and prior consultation), taking into account the nature of processing and the information available to the Processor.

## 9. Personal data breaches

9.1. The Processor notifies the Controller without undue delay, and in any event within 48 hours, after becoming aware of a personal data breach affecting Customer Personal Data. For Russian personal data, the Processor notifies the Controller within 12 hours so that the Controller can meet the 24-hour notification deadline to Roskomnadzor.

9.2. The notification includes, to the extent available: the nature of the breach, categories and approximate number of data subjects and records concerned, likely consequences, measures taken or proposed, and a contact point. Information may be provided in phases.

9.3. The Processor takes reasonable steps to contain and remediate the breach and documents it.

## 10. International transfers

The Processor does not transfer Customer Personal Data to a third country except in accordance with section 3.1 and Chapter V GDPR. Where a transfer requires safeguards, the parties agree that the Standard Contractual Clauses adopted by Commission Implementing Decision (EU) 2021/914 (Module 2 controller-to-processor, or Module 3 processor-to-processor for Sub-processors) and, for the UK, the International Data Transfer Addendum, are incorporated by reference. For Russian personal data, localisation and cross-border transfer requirements of 152-FZ apply as described in the Privacy Policy.

## 11. Deletion and return

At the end of the provision of the Service, or earlier at the Controller's request, the Processor deletes Customer Personal Data in accordance with the Service's deletion process: the workspace owner can export data at any time before deletion; after a deletion request and a grace period of {{retention.workspace_deletion_grace}} days, database records, uploaded files, search indexes and embeddings, conversation history and queued jobs are permanently deleted. Backups are overwritten within the ordinary rotation. Data that the Processor must retain by law is kept only for the required period and protected.

## 12. Audits

The Processor makes available to the Controller all information necessary to demonstrate compliance with Article 28 GDPR, including security documentation and, where available, third-party audit reports. The Controller may conduct an audit, including an inspection, no more than once per year with 30 days' notice, during business hours, at its own cost, by itself or by an independent auditor bound by confidentiality, unless a breach or a supervisory authority requires otherwise.

## 13. Liability and precedence

The limitation of liability in the Terms applies to this DPA to the extent permitted by law. In case of conflict, the Standard Contractual Clauses prevail over this DPA, and this DPA prevails over the Terms with respect to the processing of Customer Personal Data.

## Annex 1. Details of processing

| Item                         | Description                                                                                                                                                                                                                                                                                                      |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Categories of data subjects  | Controller's members (employees, contractors); persons whose data appears in documents and data the Controller uploads (for example employees, clients, suppliers, contacts); recipients of automated messages sent on behalf of the Controller                                                                  |
| Categories of personal data  | identification and contact data (name, e-mail address, phone number, messenger identifiers); professional data (role, department); content of documents, questions and answers; usage, audit and delivery records (IP address, user agent, timestamps); any other personal data the Controller chooses to upload |
| Special categories           | none intended; the Controller must not upload special categories of data unless it has a lawful basis and has assessed the risk                                                                                                                                                                                  |
| Nature of processing         | storage, indexing (text extraction, optical character recognition, chunking, embedding), retrieval and display, generation of answers with language models, sending of notifications, export and deletion                                                                                                        |
| Purpose                      | providing the Service to the Controller under the Terms                                                                                                                                                                                                                                                          |
| Duration                     | for the term of the Terms and until deletion under section 11                                                                                                                                                                                                                                                    |
| Retention within the Service | per the Controller's retention settings, defaulting to: assistant logs {{retention.ai_queries}} days, audit log {{retention.audit_logs}} days, delivery logs {{retention.deliveries}} days; documents until deleted by the Controller                                                                            |

## Annex 2. Technical and organisational measures

- **Encryption:** TLS for all traffic to the Service and between services; sensitive fields (for example two-factor secrets and third-party API keys) encrypted at rest with application keys; passwords hashed with a modern adaptive algorithm.
- **Access control:** workspace isolation enforced on every query; role-based permissions; two-factor authentication and passkeys; re-authentication for sensitive actions; staff access on a need-to-know basis with individual accounts.
- **Service-to-service security:** internal requests signed with per-pair keys, timestamped and protected against replay; internal endpoints not exposed publicly.
- **Logging and monitoring:** audit log of security-relevant actions; structured application logs without secrets; health checks and alerting.
- **Data minimisation for AI:** external AI providers disabled by default; when enabled, e-mail addresses, phone numbers, payment card numbers, IBANs and account numbers are masked before text is sent; only the fragments needed to answer a question are sent; no use of customer content for training.
- **Availability and resilience:** regular backups, tested restoration, idempotent background jobs, retries with back-off.
- **Secure development:** code review, automated tests, dependency updates and vulnerability scanning, no third-party scripts in the browser, strict Content Security Policy.
- **Retention and deletion:** automated daily purge according to retention settings; documented deletion of workspaces and accounts.
- **Incident management:** documented breach response procedure with defined notification timelines.
- **Personnel:** confidentiality obligations, security awareness, prompt removal of access on departure.

## Annex 3. Sub-processors

The current list of Sub-processors, including purpose, data processed, location and the condition under which each is used, is maintained at {{product.url}}/legal/subprocessors and forms part of this Annex.

## Annex: PayFlow Guard processing

**Data subjects.** Contacts of the Controller's clients; vendor contacts and sole-proprietor payees; trusted contacts appointed by the Controller; members of the Controller's workspace.

**Categories of data.** Contact data (names, business e-mail addresses, phone numbers); invoice, payment and reminder data; payee bank details (encrypted at rest and masked in the interface); approval evidence including IP address and user agent.

**Purposes.** Issuing invoices and collecting receivables (reminders, payment links, analytics) and preventing payment fraud (bank-change verification, approvals, alerts).

**Sub-processors.** Hosting, e-mail delivery, Stripe or YooKassa (only in the Controller's own account), Telegram (only for members who link it) and language-model providers (only when enabled), as listed on the sub-processors page.

**Deletion.** When the workspace is deleted, active payment links are deactivated, the payment account is disconnected, scheduled reminders and pending confirmations are cancelled, imported files and cached PDFs are deleted, and all workspace data is purged after the grace period.
