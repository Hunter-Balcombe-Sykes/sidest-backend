<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://www.hunterbalcombesykes.com',
        'https://hunterbalcombesykes.com',
        'http://localhost:3000',
        'http://localhost:5173',
    ],
    'allowed_origins_patterns' => [
        '#^https://.*\.vercel\.app$#', // For Vercel preview deployments
        '#^https?://localhost(?::\d+)?$#',
        '#^https?://127\.0\.0\.1(?::\d+)?$#',
        '#^https?://192\.168\.\d{1,3}\.\d{1,3}(?::\d+)?$#',
        '#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(?::\d+)?$#',
        '#^https?://172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}(?::\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
