<?php

/*
|--------------------------------------------------------------------------
| Marketing copy (product-specific) — English
|--------------------------------------------------------------------------
| PayFlow Guard landing, pricing, security and legal pages. This file is
| swapped when the foundation is cloned into another product. Lists are
| arrays of arrays so the UI can iterate them (see listIndices()).
*/

return [
    'meta' => [
        'title' => 'PayFlow Guard — get paid on time, stop payment fraud',
        'description' => 'Automated invoice reminders, late-payment prediction, vendor bank-detail change protection and 4-eyes approvals — with Telegram alerts and an AI assistant that cites your own policies.',
    ],

    'brand' => [
        'name' => 'PayFlow Guard',
    ],

    'nav' => [
        'label' => 'Main',
        'home' => 'PayFlow Guard home',
        'features' => 'Features',
        'how' => 'How it works',
        'pricing' => 'Pricing',
        'security' => 'Security',
        'faq' => 'FAQ',
        'login' => 'Log in',
        'get_started' => 'Get started',
        'dashboard' => 'Open dashboard',
        'open_menu' => 'Open menu',
    ],

    'auth' => [
        'headline' => 'Money in motion. Calm control.',
        'subhead' => 'Chase late invoices automatically, spot risky payments before they leave your account and keep the whole team in the loop.',
        'preview_label' => 'Examples of PayFlow Guard alerts',
        'cards' => [
            'paid' => [
                'title' => 'INV-1042 paid · $4,820',
                'body' => 'Northwind Studio paid 2 days after a friendly reminder',
            ],
            'reminder' => [
                'title' => 'Firm reminder scheduled',
                'body' => 'Blue Fjord Ltd · 12 days overdue · 81% late risk',
            ],
            'guard' => [
                'title' => 'Bank details change blocked',
                'body' => 'Kestrel Media changed IBAN — verify by phone first',
            ],
        ],
        'stat' => '59% of small businesses have invoices more than 30 days overdue.',
        'stat_source' => 'QuickBooks small-business survey, 2026',
    ],

    'hero' => [
        'eyebrow' => 'Accounts receivable on autopilot',
        'title_start' => 'Get paid on time.',
        'title_highlight' => 'Stop payment fraud',
        'title_end' => 'before it starts.',
        'subtitle' => 'PayFlow Guard sends the right reminder at the right moment, predicts who will pay late, and blocks suspicious vendor bank-detail changes — with alerts and approvals right in Telegram.',
        'cta_primary' => 'Start free',
        'cta_secondary' => 'See how it works',
        'trust' => [
            'free' => 'Free plan forever',
            'no_card' => 'No credit card',
            'bilingual' => 'English & Russian',
        ],
        'mock' => [
            'title' => 'Cash-flow overview',
            'collected' => 'Collected this month',
            'risk' => 'Late-payment risk',
            'risk_client' => 'Blue Fjord Ltd · INV-1038',
            'guard' => 'Bank details changed — on hold',
            'toast_title' => 'Payment received',
            'toast_body' => 'INV-1042 · :amount',
            'status' => [
                'draft' => 'Draft',
                'sent' => 'Sent',
                'reminded' => 'Reminded',
                'paid' => 'Paid',
            ],
            'hint' => [
                'draft' => 'Due in 14 days',
                'sent' => 'Delivered by email and Telegram',
                'reminded' => 'Friendly reminder sent automatically',
                'paid' => 'Paid 2 days after the reminder',
            ],
        ],
    ],

    'capabilities_label' => 'What PayFlow Guard does',
    'capabilities' => [
        'reminders' => 'Smart reminder ladder',
        'risk' => 'Late-payment prediction',
        'guard' => 'Bank-detail change guard',
        'approvals' => '4-eyes approvals',
        'telegram' => 'Telegram alerts',
        'assistant' => 'AI assistant with citations',
        'audit' => 'Tamper-evident audit log',
        'bilingual' => 'English & Russian',
    ],

    'problem' => [
        'eyebrow' => 'The problem',
        'title' => 'Late payments quietly drain small businesses',
        'subtitle' => 'Chasing invoices by hand is awkward, slow and easy to forget — and fraudsters target the same overloaded finance teams.',
        'stats' => [
            'overdue' => 'of small businesses have invoices more than 30 days overdue',
            'owed' => 'owed to the average small business in unpaid invoices',
            'payroll' => 'say a single late payment has already hurt their ability to make payroll',
        ],
        'source' => 'Source: QuickBooks small-business late payments survey, 2026.',
    ],

    'features' => [
        'eyebrow' => 'Features',
        'title' => 'Everything between “invoice sent” and “money safely in”',
        'subtitle' => 'Automation where it saves time, guardrails where mistakes are expensive.',
        'reminders' => [
            'title' => 'Automated reminders with a tone ladder',
            'body' => 'Friendly, then firm, then final — sent by email and Telegram on a schedule you control, in your client’s language. Paid invoices stop the sequence instantly.',
            'tones' => [
                'friendly' => 'Friendly',
                'firm' => 'Firm',
                'final' => 'Final',
            ],
            'samples' => [
                'friendly' => 'Just a quick nudge about INV-1042…',
                'firm' => 'INV-1042 is now 7 days overdue.',
                'final' => 'Final notice before late fees apply.',
            ],
        ],
        'probability' => [
            'title' => 'Late-payment probability',
            'body' => 'Every open invoice gets a risk score from payment history, amount and terms — so you chase the right ones first.',
        ],
        'chronic' => [
            'title' => 'Chronic late payers',
            'body' => 'See who is trending later every month and adjust terms or ask for deposits before it hurts.',
        ],
        'guard' => [
            'title' => 'Vendor bank-detail change guard',
            'body' => 'A changed IBAN is the #1 invoice-fraud trick. Payments to new details are held until someone verifies them out of band.',
            'blocked' => 'Held until verified by phone',
        ],
        'approval' => [
            'title' => '4-eyes approval for risky payments',
            'body' => 'Large or unusual payments need a second approver — from the app or with one tap in Telegram.',
            'approved' => 'Approved',
        ],
        'assistant' => [
            'title' => 'Telegram alerts & an assistant that cites your policies',
            'body' => 'Ask “can we pay this vendor?” and get an answer grounded in your own payment policy — with the exact source. If the documents don’t say, it refuses instead of guessing.',
            'question' => 'Can I pay Kestrel Media’s new bank account today?',
            'answer' => 'Not yet: bank details changed 2 hours ago, so policy requires phone verification and a second approver.',
            'source' => 'Payment policy v3 · p. 4',
        ],
    ],

    'how' => [
        'eyebrow' => 'How it works',
        'title' => 'Up and running in an afternoon',
        'subtitle' => 'Connect what you already use; PayFlow Guard does the chasing and the checking.',
        'diagram_label' => 'Invoices and bank data flow into PayFlow Guard, which sends reminders, Telegram alerts, guards payments and speeds up cash collection.',
        'steps' => [
            'connect' => [
                'title' => 'Connect invoices and bank data',
                'body' => 'Import open invoices and your vendor list. Upload payment policies so the assistant knows your rules.',
            ],
            'remind' => [
                'title' => 'Turn on the reminder ladder',
                'body' => 'Pick the tone and timing once. Reminders go out automatically in each client’s language.',
            ],
            'predict' => [
                'title' => 'Focus on what’s at risk',
                'body' => 'Risk scores and chronic-payer trends tell you exactly where to spend your attention.',
            ],
            'protect' => [
                'title' => 'Approve and protect on the go',
                'body' => 'Bank-detail changes and large payments come to Telegram for a second pair of eyes.',
            ],
        ],
        'nodes' => [
            'invoices' => 'Invoices',
            'bank' => 'Bank & vendors',
            'reminders' => 'Reminders',
            'telegram' => 'Telegram alerts',
            'guard' => 'Payment guard',
            'cash' => 'Cash collected',
        ],
    ],

    'telegram' => [
        'eyebrow' => 'Telegram-native',
        'title' => 'Your finance desk, in the chat you already use',
        'subtitle' => 'No new app to install. Alerts, approvals and answers arrive where your team already talks.',
        'demo_label' => 'Example Telegram conversation with the PayFlow Guard bot',
        'bot_name' => 'PayFlow Guard',
        'bot_status' => 'bot',
        'input' => 'Message',
        'points' => [
            'alerts' => 'Instant alerts when an invoice is paid, overdue or at risk.',
            'actions' => 'Inline buttons to send a reminder, snooze, approve or reject.',
            'ask' => 'Ask questions in plain language — answers cite your documents.',
            'private' => 'Each person sees only what their role allows.',
        ],
        'messages' => [
            'overdue' => 'INV-1038 from Blue Fjord Ltd is 12 days overdue ($7,300). Send the firm reminder?',
            'ask' => 'Can we pay the new Kestrel Media bank account?',
            'answer' => 'Not yet — the IBAN changed 2 hours ago. Policy requires phone verification first [1].',
            'approval' => 'Payment of $24,900 to Orchid Labs needs your approval (2 of 2).',
            'source' => '[1] Payment policy v3, p. 4',
        ],
        'buttons' => [
            'send_firm' => 'Send reminder',
            'snooze' => 'Snooze 3 days',
            'approve' => 'Approve',
            'reject' => 'Reject',
        ],
    ],

    'security' => [
        'eyebrow' => 'Security',
        'title' => 'Built for money, built for trust',
        'subtitle' => 'Security isn’t a feature we bolted on — it’s the reason the product exists.',
        'cta' => 'Read about our security',
        'items' => [
            'encryption' => [
                'title' => 'Encrypted sensitive data',
                'body' => 'Bank details, API keys and secrets are encrypted at rest; keys are only ever shown as the last four characters.',
            ],
            'audit' => [
                'title' => 'Append-only audit log',
                'body' => 'Every security-relevant action is recorded with who, what, when and the before/after values.',
            ],
            'approvals' => [
                'title' => '4-eyes approvals',
                'body' => 'Risky payments and bank-detail changes require a second, independent approver.',
            ],
            'auth' => [
                'title' => 'Strong sign-in',
                'body' => 'Two-factor authentication, passkeys and per-role permissions for every workspace member.',
            ],
            'keys' => [
                'title' => 'Signed internal traffic',
                'body' => 'Services talk to each other with signed, time-limited requests; webhooks are verified and deduplicated.',
            ],
            'cdn' => [
                'title' => 'No third-party trackers',
                'body' => 'Every font, script and image is served from our own servers under a strict Content Security Policy.',
            ],
        ],
    ],

    'pricing_teaser' => [
        'eyebrow' => 'Pricing',
        'title' => 'Start free. Upgrade when it pays for itself.',
        'subtitle' => 'One recovered invoice usually covers a year of PayFlow Guard.',
        'cta' => 'Compare all plans',
        'points' => [
            'free' => 'Free plan, no time limit',
            'trial' => 'Free trial on paid plans',
            'cancel' => 'Cancel or switch any time',
        ],
    ],

    'faq' => [
        'eyebrow' => 'FAQ',
        'title' => 'Questions, answered',
        'items' => [
            [
                'q' => 'Do I need to change my invoicing or accounting software?',
                'a' => 'No. PayFlow Guard works alongside the tools you already use: import open invoices and your vendor list, and we take care of reminders, risk scoring and payment checks.',
            ],
            [
                'q' => 'Will automated reminders annoy my clients?',
                'a' => 'You choose the tone ladder and timing. Reminders stop the moment an invoice is paid, are sent in the client’s language, and you can pause them for any client.',
            ],
            [
                'q' => 'How does the bank-detail change guard work?',
                'a' => 'When a vendor’s bank details change, payments to the new details are put on hold and the team is alerted. Someone must verify the change through a trusted channel (for example by phone) before money moves.',
            ],
            [
                'q' => 'Is the AI assistant going to make things up?',
                'a' => 'The assistant only answers from documents you’ve approved and cites every source. If it can’t find the answer with enough confidence, it says so and suggests escalating instead of guessing.',
            ],
            [
                'q' => 'Which AI models do you use?',
                'a' => 'Administrators can choose a provider — Anthropic, OpenAI-compatible APIs, OpenRouter, NVIDIA, DeepSeek or a local Ollama model — or run fully offline with the built-in mock model.',
            ],
            [
                'q' => 'Do I need Telegram?',
                'a' => 'No. Everything works in the web app and by email. Telegram is optional for people who want alerts and one-tap approvals on their phone.',
            ],
            [
                'q' => 'Is it available in Russian?',
                'a' => 'Yes. The interface, emails, Telegram messages and assistant answers are available in English and Russian, and every user picks their own language.',
            ],
            [
                'q' => 'Can I cancel any time?',
                'a' => 'Yes. Plans are month-to-month or yearly, you can downgrade to the free plan at any time, and your data stays exportable.',
            ],
        ],
    ],

    'final_cta' => [
        'title' => 'Stop chasing. Start collecting.',
        'subtitle' => 'Set up PayFlow Guard in minutes and let the reminders, risk scores and payment guard work for you.',
        'secondary' => 'See pricing',
    ],

    'footer' => [
        'legal' => 'Legal',
        'all_legal' => 'All documents',
        'contact' => 'Contact',
        'product' => 'Product',
        'trust' => 'Trust',
        'privacy' => 'Privacy Policy',
        'terms' => 'Terms of Service',
        'tagline' => 'Get paid on time and keep every payment safe.',
        'copyright' => '© :year :name. All rights reserved.',
        'made_for' => 'Made for small businesses and their finance teams.',
    ],

    'pricing' => [
        'meta_title' => 'Pricing',
        'meta_description' => 'Simple plans for small businesses: start free, upgrade for automated reminders, risk scoring, payment guard and Telegram approvals.',
        'title' => 'Simple pricing that pays for itself',
        'subtitle' => 'Start free. Upgrade when automation saves you more than it costs — usually the first recovered invoice.',
        'plans_label' => 'Plans',
        'unavailable' => 'Plans are loading — please refresh the page in a moment.',
        'note' => 'Prices in USD, excluding taxes. Cancel or switch plans any time.',
        'compare_title' => 'Compare plans',
        'feature' => 'Feature',
        'faq_title' => 'Billing questions',
        'faq' => [
            [
                'q' => 'Is there a free trial of paid plans?',
                'a' => 'Paid plans start with a free trial. You won’t be charged until the trial ends, and you can cancel before then.',
            ],
            [
                'q' => 'How does yearly billing work?',
                'a' => 'Pay for 12 months up front at a discount. You can switch between monthly and yearly at any time; changes are prorated.',
            ],
            [
                'q' => 'What happens if I hit a plan limit?',
                'a' => 'Nothing breaks. We’ll show you what’s close to the limit and suggest an upgrade; existing data always stays available.',
            ],
            [
                'q' => 'Which payment methods do you accept?',
                'a' => 'Card payments are processed securely by our payment provider. We never see or store your full card number.',
            ],
            [
                'q' => 'Can I get an invoice for my company?',
                'a' => 'Yes. Invoices and receipts are available in the billing portal for every payment.',
            ],
        ],
    ],

    'security_page' => [
        'meta_title' => 'Security',
        'meta_description' => 'How PayFlow Guard protects your money, your data and your team: encryption, 4-eyes approvals, audit trail, strong authentication and safe AI.',
        'title' => 'Security at PayFlow Guard',
        'subtitle' => 'We help you move money safely, so we hold ourselves to the same standard. Here’s how your data and payments are protected.',
        'pillars_label' => 'Security pillars',
        'pillars' => [
            'data' => [
                'title' => 'Data protection',
                'body' => 'Sensitive fields are encrypted and access is always scoped to your workspace.',
                'points' => [
                    ['text' => 'Bank details, API keys and secrets encrypted at rest'],
                    ['text' => 'Strict tenant isolation on every query'],
                    ['text' => 'Uploaded files stored privately with expiring download links'],
                ],
            ],
            'access' => [
                'title' => 'Access control',
                'body' => 'Every person gets exactly the access their role needs — nothing more.',
                'points' => [
                    ['text' => 'Two-factor authentication and passkeys'],
                    ['text' => 'Roles and permissions per workspace'],
                    ['text' => 'Rate-limited sign-in and confirm-password for sensitive areas'],
                ],
            ],
            'payments' => [
                'title' => 'Payment safety',
                'body' => 'Guardrails at the exact moments where fraud usually happens.',
                'points' => [
                    ['text' => 'Payments to changed bank details are held until verified'],
                    ['text' => '4-eyes approval for large or unusual payments'],
                    ['text' => 'Real-time alerts in the app and in Telegram'],
                ],
            ],
            'ai' => [
                'title' => 'Responsible AI',
                'body' => 'The assistant is grounded in your documents and designed to refuse rather than guess.',
                'points' => [
                    ['text' => 'Answers cite their sources; low confidence means a refusal'],
                    ['text' => 'Retrieval respects the same roles and departments as the app'],
                    ['text' => 'Retrieved text is treated as data, never as instructions'],
                    ['text' => 'Choose your provider, or run locally with no external AI at all'],
                ],
            ],
            'infra' => [
                'title' => 'Infrastructure',
                'body' => 'Defense in depth between every service.',
                'points' => [
                    ['text' => 'Signed, time-limited requests between internal services'],
                    ['text' => 'Verified, idempotent webhooks'],
                    ['text' => 'Strict Content Security Policy — no third-party CDNs or trackers'],
                ],
            ],
            'audit' => [
                'title' => 'Accountability',
                'body' => 'You can always see who did what, and when.',
                'points' => [
                    ['text' => 'Append-only audit log with before/after values'],
                    ['text' => 'IP address and actor recorded for every entry'],
                    ['text' => 'Filter and review activity by person, action and date'],
                ],
            ],
        ],
        'disclosure_title' => 'Found a vulnerability?',
        'disclosure_body' => 'We appreciate responsible disclosure. Please report security issues privately using the contact details in our Privacy Policy and give us reasonable time to fix them before going public.',
    ],

    'legal' => [
        'eyebrow' => 'Legal',
        'toc' => 'On this page',
        'index_title' => 'Legal & privacy',
        'index_description' => 'The terms that govern the service and how we protect the data you entrust to us. Every document is versioned; earlier versions stay available.',
        'meta_description' => ':title — current version, effective date and earlier versions.',
        'all_documents' => 'All legal documents',
        'effective' => 'Effective',
        'version' => 'Version',
        'versions' => 'Version history',
        'version_current' => ':version (current, :date)',
        'version_option' => ':version (:date)',
        'archived' => 'You are viewing an earlier version. The current version is :version.',
        'view_current' => 'View the current version',
        'print' => 'Print or save as PDF',
        'template_note' => 'Template — must be reviewed by a qualified lawyer for your jurisdiction before production use.',
        'security_hint' => 'Looking for how we protect your data or how to report a vulnerability? See',
        'blurbs' => [
            'terms' => 'Accounts, workspaces, subscriptions, acceptable use and liability.',
            'privacy' => 'What personal data we process, why, for how long and your rights.',
            'cookies' => 'The strictly necessary cookies we use — no tracking.',
            'dpa' => 'How we process your workspace data on your behalf (GDPR Art. 28).',
            'subprocessors' => 'Service providers that may process data and where.',
            'aup' => 'What you may not do with the service.',
            'ai' => 'What is sent to AI models, and how to keep processing local.',
            'consent_ru' => 'Consent to personal data processing under Russian Federal Law 152-FZ.',
        ],
    ],
];
