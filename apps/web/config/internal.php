<?php

declare(strict_types=1);

return [
    'service' => 'web',

    'keys' => [
        'worker' => env('INTERNAL_KEY_WEB_WORKER') ?: null,
        'ai' => env('INTERNAL_KEY_WEB_AI') ?: null,
    ],

    'secret' => env('INTERNAL_API_SECRET') ?: null,

    'dev_fallback_secret' => 'dev-internal-secret-not-for-production',

    'tolerance' => (int) env('INTERNAL_API_TOLERANCE', 300),

    'allowed_callers' => ['worker', 'ai'],

    'rate_limit' => (int) env('INTERNAL_API_RATE_LIMIT', 1200),

    'services' => [
        'worker' => [
            'url' => env('WORKER_URL', 'http://127.0.0.1:8101'),
        ],
        'ai' => [
            'url' => env('AI_URL', 'http://127.0.0.1:8101'),
        ],
    ],

    'timeout' => (int) env('INTERNAL_HTTP_TIMEOUT', 30),
];
