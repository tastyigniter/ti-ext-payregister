<?php

return [
    'gateways' => [
        'stripe' => [
            'default_tenant_fee' => env('STRIPE_DEFAULT_TENANT_FEE', 2),
            'live' => [
                'publishableKey' => env('STRIPE_LIVE_PUBLISHABLE_KEY'),
                'secretKey' => env('STRIPE_LIVE_SECRET_KEY'),
            ],
            'test' => [
                'publishableKey' => env('STRIPE_TEST_PUBLISHABLE_KEY'),
                'secretKey' => env('STRIPE_TEST_SECRET_KEY'),
            ],
        ],
    ],
];
