<?php

declare(strict_types=1);

return [
    'currencies' => [
        'USD' => ['gateways' => ['stripe', 'fake']],
        'EUR' => ['gateways' => ['stripe', 'fake']],
        'RUB' => ['gateways' => ['yookassa', 'fake']],
        'GBP' => [],
        'CAD' => [],
        'AUD' => [],
        'CHF' => [],
        'PLN' => [],
        'CZK' => [],
        'SEK' => [],
        'NOK' => [],
        'DKK' => [],
        'KZT' => [],
        'GEL' => [],
        'AMD' => [],
        'AED' => [],
        'TRY' => [],
    ],

    'dev_routes' => env('APP_ENV') !== 'production' && (bool) env('DEV_ROUTES', false),

    'allow_clock_override' => env('APP_ENV') !== 'production' && (bool) env('DEV_ROUTES', false),

    'allow_fake_payments' => (bool) env('PAYFLOW_ALLOW_FAKE_PAYMENTS', env('APP_ENV') !== 'production'),

    'yookassa_link_ttl_minutes' => (int) env('PAYFLOW_YOOKASSA_LINK_TTL', 60),

    'yookassa_vat_code' => (int) env('PAYFLOW_YOOKASSA_VAT_CODE', 1),

    'worker_public_url' => env('WORKER_PUBLIC_URL', 'http://localhost:8101'),

    'ai_url' => env('AI_URL') ?: null,

    'seed_documents_path' => env('PAYFLOW_SEED_DOCUMENTS_PATH') ?: base_path('../../docs/seed-documents'),

    'late_grace_days' => 3,

    'stepup_window_seconds' => 900,

    'trusted_confirmation_ttl_hours' => 24,

    'planner' => [
        'chunk' => 200,
        'lookahead_hours' => 26,
    ],

    'payment_links' => [
        'sync_after_minutes' => 30,
        'sync_grace_minutes' => 10,
        'sync_batch' => 100,
    ],

    'pdf' => [
        'disk' => 'local',
        'cache_days' => 7,
    ],

    'imports' => [
        'disk' => 'local',
        'max_bytes' => 5242880,
        'batch' => 200,
    ],

    'risk_weights' => [
        'intercept' => -2.2,
        'client_late_rate' => 3.0,
        'avg_days_late' => 0.04,
        'other_overdue' => 0.45,
        'large_amount' => 0.5,
        'long_terms' => 0.012,
        'already_overdue' => 0.035,
        'not_viewed' => 0.6,
        'dispute_history' => 0.5,
        'new_client' => 0.3,
    ],

    'iban_lengths' => [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22, 'BR' => 29,
        'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29,
        'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28,
        'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20,
        'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19,
        'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15, 'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29,
        'RO' => 24, 'RS' => 22, 'SA' => 24, 'SC' => 31, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'ST' => 25, 'SV' => 28,
        'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ],

    'two_level_tlds' => ['co.uk', 'org.uk', 'com.au', 'com.br', 'co.jp', 'com.tr', 'com.ua', 'co.il', 'com.cy'],

    'free_mail_domains' => [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'proton.me',
        'protonmail.com', 'gmx.de', 'gmx.net', 'mail.ru', 'bk.ru', 'list.ru', 'inbox.ru', 'yandex.ru', 'ya.ru', 'rambler.ru',
    ],
];
