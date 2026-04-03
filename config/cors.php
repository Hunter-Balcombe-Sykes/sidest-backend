<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_merge(
        [
            'https://app.sidest.co',
            'https://sidest.co',
            'https://hunterbalcombesykes.com',
            'https://www.hunterbalcombesykes.com',
            'http://localhost:3000',
        ],
        in_array(env('APP_ENV'), ['local', 'development', 'testing']) ? [
            'http://localhost:5173',
        ] : []
    ),
    'allowed_origins_patterns' => array_merge(
        [
            '#^https://.*\.vercel\.app$#', // For Vercel preview deployments
            '#^https?://localhost:3000$#',
        ],
        in_array(env('APP_ENV'), ['local', 'development', 'testing']) ? [
            '#^https?://localhost(?::\d+)?$#',
            '#^https?://127\.0\.0\.1(?::\d+)?$#',
            '#^https?://192\.168\.\d{1,3}\.\d{1,3}(?::\d+)?$#',
            '#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(?::\d+)?$#',
            '#^https?://172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}(?::\d+)?$#',
        ] : []
    ),
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
