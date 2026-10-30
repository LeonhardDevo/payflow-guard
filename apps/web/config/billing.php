<?php

declare(strict_types=1);

return [
    'driver' => env('BILLING_DRIVER', 'fake'),

    'allow_fake_in_production' => (bool) env('BILLING_ALLOW_FAKE_IN_PRODUCTION', false),

    'currency' => env('BILLING_CURRENCY', 'USD'),

    'default_plan' => 'free',

    'active_statuses' => ['trialing', 'active', 'past_due'],

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    'trial_ending_notice_days' => 3,

    'gateways' => [
        'stripe' => 'App\Billing\StripeGateway',
        'yookassa' => 'App\Billing\YooKassa\YooKassaGateway',
        'fake' => 'App\Billing\Fake\FakeGateway',
    ],

    'payment_gateways' => [
        'stripe' => 'App\Billing\Payments\StripePaymentGateway',
        'yookassa' => 'App\Billing\Payments\YooKassaPaymentGateway',
        'fake' => 'App\Billing\Payments\FakePaymentGateway',
    ],

    'payments' => [
        'webhook_base_url' => env('PAYMENTS_WEBHOOK_BASE_URL') ?: null,
        'reconcile_after_minutes' => 25,
        'reconcile_window_days' => 30,
        'reconcile_batch' => 200,
    ],

    'gateway_requirements' => [
        'yookassa' => ['billing.yookassa.shop_id', 'billing.yookassa.secret_key'],
    ],

    'markets' => [
        'default_country' => env('BILLING_DEFAULT_COUNTRY', 'US'),
        'default_gateway' => 'stripe',
        'currency_gateways' => [
            'RUB' => 'yookassa',
        ],
        'country_gateways' => [
            'RU' => 'yookassa',
        ],
        'gateway_currencies' => [
            'stripe' => ['USD', 'EUR'],
            'yookassa' => ['RUB'],
            'fake' => ['USD', 'EUR', 'RUB'],
        ],
        'country_currencies' => [
            'RU' => 'RUB',
            'AT' => 'EUR', 'BE' => 'EUR', 'HR' => 'EUR', 'CY' => 'EUR', 'EE' => 'EUR', 'FI' => 'EUR',
            'FR' => 'EUR', 'DE' => 'EUR', 'GR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR', 'LV' => 'EUR',
            'LT' => 'EUR', 'LU' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR', 'PT' => 'EUR', 'SK' => 'EUR',
            'SI' => 'EUR', 'ES' => 'EUR',
        ],
        'locale_currencies' => [
            'ru' => 'RUB',
        ],
        'countries' => [
            'US', 'GB', 'CA', 'AU', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'AT', 'IE', 'PT', 'FI', 'EE',
            'LV', 'LT', 'PL', 'CZ', 'SE', 'NO', 'DK', 'CH', 'RU', 'KZ', 'AM', 'GE', 'UZ', 'AE', 'TR',
            'IL', 'IN', 'SG', 'JP', 'BR', 'MX',
        ],
    ],

    'plans' => [
        'free' => [
            'highlighted' => false,
            'features' => ['seats', 'documents', 'assistant', 'telegram'],
            'limits' => [
                'seats' => 2,
                'external_approvers' => 1,
                'clients' => 5,
                'invoices_per_month' => 10,
                'vendors' => 3,
                'payouts_per_month' => 5,
                'documents' => 3,
                'storage_mb' => 100,
                'ai_queries_monthly' => 30,
                'reminder_policies' => 1,
                'imports_per_month' => 0,
            ],
            'entitlements' => ['payment_links', 'telegram_bot', 'payment_guard', 'rag_assistant'],
            'prices' => [
                'USD' => ['month' => 0, 'year' => 0],
                'EUR' => ['month' => 0, 'year' => 0],
                'RUB' => ['month' => 0, 'year' => 0],
            ],
        ],
        'starter' => [
            'highlighted' => false,
            'features' => ['seats', 'documents', 'assistant', 'telegram', 'audit'],
            'limits' => [
                'seats' => 3,
                'external_approvers' => 2,
                'clients' => 50,
                'invoices_per_month' => 60,
                'vendors' => 20,
                'payouts_per_month' => 30,
                'documents' => 25,
                'storage_mb' => 1024,
                'ai_queries_monthly' => 300,
                'reminder_policies' => 2,
                'imports_per_month' => 2,
            ],
            'entitlements' => ['payment_links', 'telegram_bot', 'payment_guard', 'rag_assistant', 'csv_import', 'risk_scoring'],
            'prices' => [
                'USD' => ['month' => 1900, 'year' => 19000],
                'EUR' => ['month' => 1700, 'year' => 17000],
                'RUB' => ['month' => 149000, 'year' => 1490000],
            ],
        ],
        'pro' => [
            'highlighted' => true,
            'features' => ['seats', 'documents', 'assistant', 'telegram', 'audit', 'priority_support'],
            'limits' => [
                'seats' => 10,
                'external_approvers' => 5,
                'clients' => 300,
                'invoices_per_month' => 400,
                'vendors' => 100,
                'payouts_per_month' => 200,
                'documents' => 150,
                'storage_mb' => 10240,
                'ai_queries_monthly' => 2000,
                'reminder_policies' => 10,
                'imports_per_month' => 20,
            ],
            'entitlements' => [
                'payment_links', 'telegram_bot', 'payment_guard', 'rag_assistant', 'csv_import', 'risk_scoring', 'custom_templates', 'ai_drafts',
                'analytics_advanced',
            ],
            'prices' => [
                'USD' => ['month' => 4900, 'year' => 49000],
                'EUR' => ['month' => 4500, 'year' => 45000],
                'RUB' => ['month' => 399000, 'year' => 3990000],
            ],
        ],
        'business' => [
            'highlighted' => false,
            'features' => ['seats', 'documents', 'assistant', 'telegram', 'audit', 'priority_support', 'sso'],
            'limits' => [
                'seats' => 50,
                'external_approvers' => 10,
                'clients' => null,
                'invoices_per_month' => null,
                'vendors' => 500,
                'payouts_per_month' => null,
                'documents' => 1000,
                'storage_mb' => 51200,
                'ai_queries_monthly' => 10000,
                'reminder_policies' => null,
                'imports_per_month' => null,
            ],
            'entitlements' => [
                'payment_links', 'telegram_bot', 'payment_guard', 'rag_assistant', 'csv_import', 'risk_scoring', 'custom_templates', 'ai_drafts',
                'analytics_advanced', 'audit_export', 'priority_support',
            ],
            'prices' => [
                'USD' => ['month' => 9900, 'year' => 99000],
                'EUR' => ['month' => 9200, 'year' => 92000],
                'RUB' => ['month' => 799000, 'year' => 7990000],
            ],
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET') ?: null,
        'dev_webhook_secret' => 'whsec_test_local',
        'api_base' => env('STRIPE_API_BASE') ?: null,
        'catalog' => env('STRIPE_CATALOG') ?: env('PRODUCT_SLUG', 'app'),
        'automatic_tax' => (bool) env('STRIPE_AUTOMATIC_TAX', false),
        'tax_id_collection' => (bool) env('STRIPE_TAX_ID_COLLECTION', true),
        'price_cache_seconds' => 600,
        'max_network_retries' => 2,
    ],

    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID') ?: null,
        'secret_key' => env('YOOKASSA_SECRET_KEY') ?: null,
        'api_base' => env('YOOKASSA_API_BASE') ?: 'https://api.yookassa.ru/v3',
        'receipts' => (bool) env('YOOKASSA_RECEIPTS', false),
        'vat_code' => (int) env('YOOKASSA_VAT_CODE', 1),
        'tax_system_code' => is_numeric(env('YOOKASSA_TAX_SYSTEM_CODE')) ? (int) env('YOOKASSA_TAX_SYSTEM_CODE') : null,
        'timeout_seconds' => 15,
        'retry_hours' => (int) env('YOOKASSA_RETRY_HOURS', 24),
        'grace_days' => (int) env('YOOKASSA_GRACE_DAYS', 7),
    ],

    'fake' => [
        'session_ttl_minutes' => 60,
    ],
];
