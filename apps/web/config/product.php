<?php

declare(strict_types=1);
use App\Assistant\Actions\EscalateAction;
use App\Domain\Privacy\PayflowAccountDeletionHook;
use App\Domain\Privacy\PayflowExportContributor;
use App\Domain\Privacy\PayflowWorkspacePurgeHook;

return [
    'slug' => env('PRODUCT_SLUG', 'app'),

    'tagline' => env('PRODUCT_TAGLINE', ''),

    'support_email' => env('PRODUCT_SUPPORT_EMAIL', 'support@example.com'),

    'telegram' => [
        'bot_username' => env('TELEGRAM_BOT_USERNAME') ?: null,

        'link_ttl_minutes' => (int) env('TELEGRAM_LINK_TTL_MINUTES', 15),
    ],

    'assistant' => [
        'actions' => [
            'escalate' => EscalateAction::class,
        ],
    ],

    'privacy' => [
        'export_contributors' => [
            PayflowExportContributor::class,
        ],
        'account_deletion_hooks' => [
            PayflowAccountDeletionHook::class,
        ],
        'workspace_purge_hooks' => [
            PayflowWorkspacePurgeHook::class,
        ],
    ],

    'email' => [
        'primary' => env('PRODUCT_EMAIL_PRIMARY', '#059669'),
        'ink' => env('PRODUCT_EMAIL_INK', '#0B1324'),
        'accent' => env('PRODUCT_EMAIL_ACCENT', '#7C3AED'),
        'background' => env('PRODUCT_EMAIL_BACKGROUND', '#F7F8FA'),
        'background_dark' => env('PRODUCT_EMAIL_BACKGROUND_DARK', '#070B14'),
    ],

    'workspace' => [
        'default_timezone' => env('PRODUCT_DEFAULT_TIMEZONE', 'UTC'),
        'default_currency' => env('PRODUCT_DEFAULT_CURRENCY', 'USD'),
        'currencies' => ['USD', 'EUR', 'GBP', 'RUB', 'KZT', 'UAH', 'TRY', 'AED', 'GEL', 'AMD', 'UZS', 'CNY'],
    ],

    'assistant_suggestions' => [
        'en' => [
            'Which invoice is the most overdue?',
            'When should we remind Helios Media?',
            'What is the late-payment penalty in the Baltic Logistics contract?',
            'Which bank details did PixelForge change?',
        ],
        'ru' => [
            'Какой счёт просрочен сильнее всего?',
            'Когда напомнить Helios Media?',
            'Какой штраф по договору с Балтийской логистикой?',
            'Какие реквизиты поменял PixelForge?',
        ],
    ],
];
