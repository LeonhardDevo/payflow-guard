<?php

declare(strict_types=1);

return [
    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP', true),
        'vite_dev_server' => env('VITE_DEV_SERVER_URL', 'http://localhost:5173'),
        'connect_src' => array_values(array_filter(explode(',', (string) env('SECURITY_CSP_CONNECT_SRC', '')))),
    ],

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS', false),
        'max_age' => 63072000,
    ],

    'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',

    'referrer_policy' => 'strict-origin-when-cross-origin',
];
