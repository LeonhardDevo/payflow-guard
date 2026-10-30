---
title: 'Privacy Policy'
version: '1.1'
effective_date: '2026-10-23'
summary_of_changes: 'Added the PayFlow Guard product sections.'
requires_acceptance: true
---

<!-- template-notice -->

This Privacy Policy explains how {{company.name}} ("we", "us") processes personal data in connection with {{product.name}} at {{product.url}} (the "Service"). It provides the information required by Articles 13 and 14 of the EU General Data Protection Regulation (GDPR), the UK GDPR and, in section 15, by Russian Federal Law No. 152-FZ "On Personal Data".

## 1. Who we are and how to contact us

- Controller: {{company.name}}, registration number {{company.registration}}, {{company.address}}.
- Privacy contact: {{company.privacy_email}}.
- Data protection officer: {{company.dpo}}.
- Representative in the European Union (GDPR Art. 27): {{company.eu_representative}}.
- General contact: {{company.email}}.

## 2. Our two roles: controller and processor

The Service is used by organisations ("customers") that create workspaces and invite members.

- **We are the controller** for data we need to run our relationship with customers and users: account data, authentication and security data, billing data, support correspondence, service e-mails and technical logs.
- **We are a processor** for the content customers put into their workspaces ("customer content"): uploaded documents, questions to the assistant and answers, workspace configuration, data about people the customer manages in the Service. The customer is the controller of customer content and decides why and how it is processed. We process it only on the customer's documented instructions under our Data Processing Agreement ({{product.url}}/legal/dpa). If your personal data is part of customer content (for example you are an employee or a client of one of our customers), please contact that customer first; we will forward your request to them and support them in answering it.

## 3. Data we collect

| Category                          | Examples                                                                                                                                                       | Source                                      |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| Account data                      | name, e-mail address, hashed password, interface language, appearance preference                                                                               | you                                         |
| Authentication and security data  | two-factor secret and recovery codes (encrypted), passkey public keys, session identifiers, IP address, user agent, sign-in times                              | you, your device                            |
| Workspace membership              | workspace name, role, invitations (invitee e-mail, inviter, expiry)                                                                                            | you, the workspace owner                    |
| Billing data                      | plan, subscription status, billing period, payment-provider customer and subscription identifiers, invoices; card data is handled only by the payment provider | you, the payment provider                   |
| Messaging channels                | if you link Telegram: Telegram user ID, chat ID, username, language code, link time                                                                            | you, Telegram                               |
| Usage and assistant logs          | questions, answers, cited sources, channel, model and provider used, response time                                                                             | you, the Service                            |
| Audit log                         | security-relevant actions in a workspace (who, what, when, IP address, user agent)                                                                             | the Service                                 |
| Delivery logs                     | status of e-mails and messages sent by the Service (channel, status, provider message ID, errors)                                                              | the Service, e-mail and messaging providers |
| Legal records                     | which version of the Terms, Privacy Policy, consent or DPA you accepted, when, from which IP address and user agent, in which language                         | you                                         |
| Support correspondence            | your messages and attachments                                                                                                                                  | you                                         |
| Customer content (processor role) | documents, extracted text and search indexes, conversations with the assistant                                                                                 | customers and their members                 |

We do not intentionally collect special categories of personal data. Customers must not upload such data unless they have a lawful basis and have configured the Service appropriately.

## 4. Purposes and legal bases

| Purpose                                                                          | Data                                        | Legal basis (GDPR Art. 6(1))                                                                           |
| -------------------------------------------------------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Creating and operating accounts and workspaces, providing the Service            | account, membership, workspace data         | (b) contract                                                                                           |
| Authentication, preventing fraud and abuse, securing the Service, audit trail    | authentication and security data, audit log | (f) legitimate interests in security; (c) legal obligation where security measures are required by law |
| Subscriptions, invoicing, tax and accounting                                     | billing data                                | (b) contract; (c) legal obligation (tax and accounting law)                                            |
| Service e-mails (verification, password reset, security alerts, billing notices) | account data, delivery logs                 | (b) contract; (f) legitimate interests                                                                 |
| Telegram linking and answers in Telegram                                         | messaging channel data, assistant logs      | (b) contract, at your request                                                                          |
| Assistant answers, citations and quality monitoring                              | assistant logs                              | (b) contract; (f) legitimate interests in reliability                                                  |
| Recording acceptance of legal documents                                          | legal records                               | (c) legal obligation; (f) legitimate interests in proving consent and agreement                        |
| Support                                                                          | support correspondence                      | (b) contract; (f) legitimate interests                                                                 |
| Exercising or defending legal claims                                             | relevant records                            | (f) legitimate interests                                                                               |
| Processing customer content                                                      | customer content                            | on the customer's instructions (GDPR Art. 28); the customer determines the legal basis                 |

Providing your account data (name, e-mail address, password) is a contractual requirement: without it we cannot create an account or provide the Service. Billing data is required by law and by the contract for paid plans; without it a paid plan cannot be activated. Everything else (Telegram link, profile details, AI features) is optional, and not providing it only means the related feature is unavailable (GDPR Art. 13(2)(e)).

Where we rely on legitimate interests you may object (section 8). We do not use personal data for advertising, we do not sell or share it for cross-context behavioural advertising, and we do not make decisions that produce legal or similarly significant effects based solely on automated processing.

## 5. Retention

We keep personal data only as long as necessary. The default periods below are enforced by an automated daily purge job; workspace owners can shorten some of them in Settings within their plan.

| Data                                                     | Retention (days)                       |
| -------------------------------------------------------- | -------------------------------------- |
| Assistant question and answer logs, conversation history | {{retention.ai_queries}}               |
| Audit log entries                                        | {{retention.audit_logs}}               |
| E-mail and message delivery logs                         | {{retention.deliveries}}               |
| Inbound webhook events (payment and messaging providers) | {{retention.webhook_events}}           |
| Dispatched internal queue messages                       | {{retention.outbox_dispatched}}        |
| Failed background job records                            | {{retention.failed_jobs}}              |
| Data export archives (download link validity)            | {{retention.data_exports}}             |
| Grace period before an account is permanently deleted    | {{retention.account_deletion_grace}}   |
| Grace period before a workspace is permanently deleted   | {{retention.workspace_deletion_grace}} |

Other data is kept for the life of the account or workspace and deleted when it is deleted. Deleted documents are removed from storage and from search indexes immediately. Billing records and accepted legal-document records are kept for the period required by tax, accounting and limitation laws. After an account is deleted, audit entries that must be kept for security reasons are pseudonymised. Backups are overwritten in the ordinary rotation.

## 6. Recipients and sub-processors

We share personal data only with:

- service providers acting on our behalf (sub-processors), under written contracts with confidentiality and security obligations — the current list with purpose, data and location is at {{product.url}}/legal/subprocessors;
- payment providers, which act as independent controllers for payment and anti-fraud processing;
- Telegram, if you choose to link it (Telegram processes messages under its own privacy policy);
- external AI providers, only if the workspace owner enables external providers; otherwise the assistant runs on local or built-in models and no content leaves our infrastructure for AI processing;
- authorities, courts or advisers where required by law or to protect rights;
- a successor in a merger or acquisition, subject to this Policy.

Within a workspace, members see data according to their roles; workspace owners and administrators can see members' names, e-mail addresses, roles, audit log entries and assistant usage in that workspace.

## 7. International transfers

Our providers may process data outside your country, including outside the European Economic Area, the United Kingdom or Russia. For transfers from the EEA or the UK to countries without an adequacy decision we use the European Commission's Standard Contractual Clauses (and the UK Addendum) together with supplementary measures such as encryption in transit and at rest. You can request a copy of the relevant safeguards from {{company.privacy_email}}. For the Russian market, see section 15.

## 8. Your rights

Subject to the conditions of applicable law, you have the right to:

- access your personal data and receive a copy;
- rectify inaccurate data;
- erase your data;
- restrict processing;
- data portability;
- object to processing based on legitimate interests;
- withdraw consent at any time, without affecting earlier processing;
- lodge a complaint with a supervisory authority, in particular in your country of residence or work.

**How to exercise your rights in the Service** (Settings → Privacy):

- **Download my data** creates an archive of your account data and files you own; a signed download link is e-mailed to you and expires after {{retention.data_exports}} days.
- **Delete my account** requires re-authentication. If you are the only owner of a workspace with other members, you must first transfer ownership. Deletion is scheduled after a grace period of {{retention.account_deletion_grace}} days, which you can cancel.
- **Workspace owners** can export or delete the whole workspace; deletion follows a grace period of {{retention.workspace_deletion_grace}} days.
- Profile data can be corrected in Settings → Profile.

For anything else, write to {{company.privacy_email}}. We answer within one month (extendable by two months for complex requests, with notice) and may need to verify your identity.

## 9. E-mail and Telegram

Service e-mails contain our identity and a link to this Policy. Automated messages sent on behalf of a customer to its contacts contain an opt-out link; after opting out, the address is placed on a suppression list and the customer is notified. In Telegram, the bot explains on first contact what is stored and who can see it; the /privacy command repeats this information and /unlink removes the link to your account. We do not keep Telegram message content beyond the assistant log retention period.

## 10. AI processing

The assistant retrieves relevant fragments of the workspace's documents and sends them, with the question, to the language model selected for the workspace. Before text is sent to an external provider, e-mail addresses, phone numbers, card numbers, IBANs and account numbers are masked (account numbers to the last four digits). The provider and model used are recorded for each answer. We do not use customer content to train models. Details are in the AI Transparency Notice at {{product.url}}/legal/ai.

## 11. Cookies

We use only strictly necessary cookies (session, security token, language, appearance, sidebar state, dismissal of the cookie notice). We use no analytics, advertising or third-party tracking. See the Cookie Policy at {{product.url}}/legal/cookies.

## 12. Security

We apply technical and organisational measures appropriate to the risk, including encryption in transit (TLS) and of sensitive fields at rest, hashed passwords, two-factor authentication and passkeys, role-based access, signed service-to-service requests, least-privilege access for staff, audit logging, backups and regular dependency updates. In case of a personal data breach we notify the competent supervisory authority within 72 hours where required, notify affected individuals without undue delay where the breach is likely to result in a high risk, and notify customers without undue delay where we act as processor.

## 13. Children

The Service is intended for businesses and is not directed to children. We do not knowingly collect personal data of persons under 16 (or under 18 in Russia). If you believe a child has provided us data, contact {{company.privacy_email}} and we will delete it.

## 14. Changes to this Policy

We may update this Policy. The current version and previous versions are available at {{product.url}}/legal. We notify you of material changes by e-mail or in the Service before they take effect and, where required, ask for your acknowledgement on your next sign-in.

## 15. Information for users in the Russian Federation (152-FZ)

15.1. For personal data of users in Russia, {{company.name}} acts as the operator within the meaning of Federal Law No. 152-FZ. For customer content we process personal data on behalf of the customer (the operator) under its instruction (Article 6(3) of 152-FZ), set out in the Data Processing Agreement.

15.2. Legal grounds: performance of a contract to which the data subject is a party (Article 6(1)(5)), compliance with legal obligations (Article 6(1)(2)), and the data subject's consent where required (Article 6(1)(1)), which users in the Russian locale give at registration in a separate consent form ({{product.url}}/legal/consent-ru).

15.3. Localisation (Article 18(5)): when personal data of Russian citizens is collected, recording, systematisation, accumulation, storage, clarification and retrieval are performed using databases located in the Russian Federation. A deployment of the Service that serves the Russian market is therefore operated in a Russian data centre region; the operator of that deployment is responsible for this.

15.4. Cross-border transfer: before transferring personal data abroad, the operator notifies Roskomnadzor as required by Article 12 of 152-FZ and transfers data only to countries and recipients that meet its requirements. External AI providers are disabled by default and are enabled only by a workspace owner's decision.

15.5. Data subjects have the rights set out in Articles 14–17 of 152-FZ, including the right to receive information about processing, to demand clarification, blocking or destruction of data, to withdraw consent and to appeal to Roskomnadzor or a court. Requests are answered within 10 business days (extendable by 5 business days with a reasoned notice). Requests can be sent to {{company.privacy_email}} or exercised in Settings → Privacy.

15.6. Breach notification: in case of unlawful transfer, provision, distribution of or access to personal data, the operator notifies Roskomnadzor within 24 hours of detection and provides the results of the internal investigation within 72 hours (Article 21(3.1) of 152-FZ).

15.7. The person responsible for organising personal data processing: {{company.dpo}}.

## PayFlow Guard: data about your clients and payees

For invoices, reminders, payments, vendors and payouts the workspace is the controller and we act as its processor under the Data Processing Agreement. The categories involved are client contact details, invoice and payment data, vendor contacts, payee bank details (stored encrypted and shown masked), approval evidence (approver, time, IP address and user agent) and trusted-contact e-mail addresses.

**View tracking and late-payer scoring.** If the workspace keeps view tracking on, we record whether and when a public invoice page was opened. The workspace's payment history with a client is used to classify late payers and estimate a late-payment probability. These estimates are decision support for the workspace team only: they are never shown to the client, never used for automated decisions with legal effect, and recipients can stop automated reminders at any time.

**Telegram and AI.** Notifications are sent to Telegram only for members who linked their account; they never contain full bank details, holder names, invoice PDFs or reminder texts. Data sent to language models is limited to what a tool needs, with account numbers masked and holder names omitted; external providers are used only if the workspace allows them.

**Retention.** Reminder e-mail bodies are deleted after 365 days, imported CSV files 30 days after processing, cached invoice PDFs after 7 days, IP addresses and user agents of approvals after 365 days, bank-change evidence three years after the version was replaced or rejected, and resolved Payment Guard alerts after two years. Invoices, payments, payouts and approvals are financial records and stay until the workspace is deleted.
