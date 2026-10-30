---
title: 'AI Transparency Notice'
version: '1.1'
effective_date: '2026-10-23'
summary_of_changes: 'Added the PayFlow Guard product sections.'
requires_acceptance: false
---

<!-- template-notice -->

{{product.name}} includes an assistant that answers questions using the documents and data of your workspace. This notice explains, in plain language, how the AI features work, what data reaches which model, and how you stay in control. It is provided by {{company.name}} and complements the Privacy Policy and the Data Processing Agreement.

## 1. How an answer is produced

1. You ask a question in the web application or, if your workspace has linked it, in Telegram.
2. The Service searches the workspace's indexed documents (keyword and semantic search) and selects the most relevant fragments that your role is allowed to see.
3. If the fragments are not relevant enough, the assistant says that it does not know instead of guessing.
4. Otherwise the question and the selected fragments are sent to the language model configured for the workspace, which writes an answer in your language with citations to the source fragments.
5. The answer, the cited sources, the provider and model used and the response time are stored in the workspace's assistant log.

## 2. Which data reaches which model

| Processing step                                                                              | Data used                                                                   | Where it runs                                                                                                                                                                      |
| -------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Document indexing (text extraction, optical character recognition, splitting into fragments) | uploaded documents                                                          | our infrastructure                                                                                                                                                                 |
| Search index (embeddings)                                                                    | document fragments and questions                                            | our infrastructure with a local embedding model by default; an external embedding provider only if configured by the operator and allowed for the workspace                        |
| Answer generation                                                                            | the question, the selected fragments, recent turns of the same conversation | the built-in or local model by default; an external provider (for example OpenAI, Anthropic or an OpenAI-compatible service) only if the workspace owner allows external providers |
| Assistant log                                                                                | question, answer, citations, provider, model, timing                        | our infrastructure                                                                                                                                                                 |

The current list of AI providers that may act as sub-processors is at {{product.url}}/legal/subprocessors.

## 3. External providers are off by default

Each workspace has the setting **Allow external AI providers** (Settings → Privacy), which is off by default. While it is off, all AI processing uses built-in or local models and no content leaves our infrastructure for AI processing; answers may then be simpler, and the Service marks them as produced in restricted mode. Only a workspace owner can turn the setting on, and it can be turned off at any time.

## 4. Redaction before data leaves the Service

When an external provider is used, the text is redacted before it is sent: e-mail addresses, phone numbers, payment card numbers, IBANs and bank account numbers are masked (account numbers keep only the last four digits). Credentials and secrets are never included in prompts or logs. Redaction reduces risk but cannot recognise every kind of personal data in free text, so do not upload data you are not permitted to process.

## 5. No training on your data

We do not use your documents, questions or answers to train or fine-tune models. We select providers and API plans whose terms prohibit using submitted data for training, and we configure them for the shortest available data retention.

## 6. Accuracy, citations and human review

- Language models can be wrong, incomplete or out of date, even when an answer sounds confident and cites a source.
- Always check the cited fragments, especially for decisions about money, contracts, employment, security or compliance.
- Answers are not legal, financial, tax, medical or other professional advice.
- The assistant does not make decisions that have legal or similarly significant effects on individuals; a person in your organisation remains responsible for any such decision.

## 7. Who can see assistant activity

Your questions and the answers you receive are stored in the workspace's assistant log for {{retention.ai_queries}} days by default. Workspace owners and administrators can see the log of their workspace to improve the knowledge base and investigate incorrect answers. Members of other workspaces and other customers never see it.

## 8. Your choices

- Workspace owners: turn external providers on or off and shorten the assistant log retention in Settings → Privacy; delete a document to remove it from storage and from the search index immediately.
- Members: export your data or delete your account in Settings → Privacy.
- Telegram users: use /privacy to see what is stored and /unlink to disconnect your account.

## 9. Contact

Questions about AI processing: {{company.privacy_email}}.

## PayFlow Guard

AI tools may read invoice, client and payout data of your workspace to answer questions. Account numbers are masked to the last four digits and bank account holder names are never sent to a model. When the assistant drafts a reminder, personal names are replaced with placeholders before the text leaves the system and are restored afterwards; a person always reviews and sends the draft. External providers are used only when the workspace allows them.
