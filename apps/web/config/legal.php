<?php

declare(strict_types=1);

$csv = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [
    'path' => (static fn (string $path): string => preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) === 1 ? $path : base_path($path))(
        (string) env('LEGAL_PATH', 'resources/legal'),
    ),

    'reviewed' => (bool) env('LEGAL_REVIEWED', false),

    'enforce_acceptance' => (bool) env('LEGAL_ENFORCE_ACCEPTANCE', true),

    'documents' => ['terms', 'privacy', 'cookies', 'dpa', 'subprocessors', 'aup', 'ai', 'consent-ru', 'reminder-recipients', 'payment-guard-disclaimer'],

    'acceptance' => [
        'registration' => ['terms', 'privacy'],

        'registration_locale' => [
            'ru' => ['consent-ru'],
        ],

        'workspace' => ['dpa'],
    ],

    'company' => [
        'name' => env('LEGAL_COMPANY_NAME', '[Company name]'),
        'registration' => env('LEGAL_COMPANY_REGISTRATION', '[Registration number]'),
        'address' => env('LEGAL_COMPANY_ADDRESS', '[Registered address]'),
        'email' => env('LEGAL_COMPANY_EMAIL', 'legal@example.com'),
        'privacy_email' => env('LEGAL_PRIVACY_EMAIL', 'privacy@example.com'),
        'dpo' => env('LEGAL_DPO', '[Data protection officer]'),
        'eu_representative' => env('LEGAL_EU_REPRESENTATIVE', '[EU representative or "not appointed"]'),
        'governing_law' => env('LEGAL_GOVERNING_LAW', '[Governing law]'),
        'support_hours' => env('LEGAL_SUPPORT_HOURS', 'Mon–Fri, 09:00–18:00 UTC'),
    ],

    'llm_providers' => $csv((string) env('LEGAL_LLM_PROVIDERS', '')),

    'ai_discovery' => (bool) env('LEGAL_AI_DISCOVERY', true),

    'subprocessors' => [
        [
            'name' => env('LEGAL_HOSTING_PROVIDER', '[Hosting provider]'),
            'address' => env('LEGAL_HOSTING_ADDRESS', '[Registered address]'),
            'purpose' => ['en' => 'Application hosting and database', 'ru' => 'Хостинг приложения и базы данных'],
            'data' => ['en' => 'All service data', 'ru' => 'Все данные сервиса'],
            'location' => env('LEGAL_HOSTING_LOCATION', '[Region]'),
            'enabled' => true,
        ],
        [
            'name' => env('LEGAL_EMAIL_PROVIDER', '[E-mail delivery provider]'),
            'address' => env('LEGAL_EMAIL_ADDRESS', '[Registered address]'),
            'purpose' => ['en' => 'Transactional e-mail delivery', 'ru' => 'Доставка служебных писем'],
            'data' => ['en' => 'Name, e-mail address, message content', 'ru' => 'Имя, адрес e-mail, содержание письма'],
            'location' => env('LEGAL_EMAIL_LOCATION', '[Region]'),
            'enabled' => true,
        ],
        [
            'name' => 'Stripe, Inc.',
            'address' => '354 Oyster Point Blvd, South San Francisco, CA 94080, USA',
            'purpose' => [
                'en' => 'Subscription billing; client payments run in the customer\'s own Stripe account',
                'ru' => 'Оплата подписки; платежи клиентов проходят в собственном аккаунте Stripe клиента',
            ],
            'data' => ['en' => 'Billing contact, payment details (held by Stripe)', 'ru' => 'Платёжный контакт, платёжные данные (хранятся у Stripe)'],
            'location' => 'USA / EU',
            'enabled' => env('BILLING_DRIVER', 'fake') === 'stripe',
        ],
        [
            'name' => 'YooMoney LLC (YooKassa)',
            'address' => '82 Sadovnicheskaya St., bldg. 2, Moscow, 115035, Russia',
            'purpose' => [
                'en' => 'Payments for RU-market customers, in the customer\'s own YooKassa shop',
                'ru' => 'Платежи клиентов на рынке РФ в собственном магазине ЮKassa клиента',
            ],
            'data' => ['en' => 'Payer e-mail for receipts, payment details (held by YooKassa)', 'ru' => 'E-mail плательщика для чеков, платёжные данные (хранятся у ЮKassa)'],
            'location' => 'Russia',
            'enabled' => filled(env('YOOKASSA_SHOP_ID')),
        ],
        [
            'name' => 'Telegram Messenger Inc.',
            'address' => env('LEGAL_TELEGRAM_ADDRESS', '[Registered address]'),
            'purpose' => ['en' => 'Bot notifications and questions (only if the customer enables it)', 'ru' => 'Уведомления и вопросы через бота (только если клиент его подключил)'],
            'data' => ['en' => 'Telegram user id, chat id, messages', 'ru' => 'Идентификатор пользователя и чата Telegram, сообщения'],
            'location' => 'UAE / global',
            'enabled' => filled(env('TELEGRAM_BOT_USERNAME')),
        ],
        ...array_map(
            static fn (array $provider): array => [
                'ai_provider' => $provider[0],
                'name' => env('LEGAL_AI_'.$provider[4].'_NAME', $provider[1]),
                'address' => env('LEGAL_AI_'.$provider[4].'_ADDRESS', $provider[2]),
                'purpose' => ['en' => 'AI answers and embeddings (only when the workspace allows external AI providers)', 'ru' => 'Ответы ИИ и векторизация (только если рабочее пространство разрешило внешних ИИ-провайдеров)'],
                'data' => ['en' => 'Questions and document excerpts with personal data redacted', 'ru' => 'Вопросы и фрагменты документов с замаскированными персональными данными'],
                'location' => $provider[3],
                'enabled' => null,
            ],
            [
                ['anthropic', 'Anthropic PBC', '548 Market St, PMB 90375, San Francisco, CA 94104, USA', 'USA', 'ANTHROPIC'],
                ['openai', 'OpenAI OpCo, LLC', '1455 3rd Street, San Francisco, CA 94158, USA', 'USA', 'OPENAI'],
                ['deepseek', 'Hangzhou DeepSeek Artificial Intelligence Co., Ltd.', '[Registered address]', 'China', 'DEEPSEEK'],
                ['openrouter', 'OpenRouter, Inc.', '[Registered address]', 'USA', 'OPENROUTER'],
                ['nvidia', 'NVIDIA Corporation', '2788 San Tomas Expressway, Santa Clara, CA 95051, USA', 'USA', 'NVIDIA'],
                ['opencode-zen', '[OpenCode Zen operator]', '[Registered address]', '[Region]', 'OPENCODE_ZEN'],
                ['opencode-go', '[OpenCode Go operator]', '[Registered address]', '[Region]', 'OPENCODE_GO'],
            ],
        ),
    ],

    'security' => [
        'contact' => env('SECURITY_CONTACT', 'mailto:security@example.com'),
        'preferred_languages' => env('SECURITY_PREFERRED_LANGUAGES', 'en, ru'),

        'expires_days' => (int) env('SECURITY_TXT_EXPIRES_DAYS', 180),
        'encryption' => env('SECURITY_ENCRYPTION_URL') ?: null,
    ],
];
