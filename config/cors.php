<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_merge(
        [
            'https://app.sidest.co',
            'https://sidest.co',
            // Intentionally allowed in prod: frontend devs run a local Vite/Next
            // server on :3000 against the deployed Laravel Cloud API. Safe because
            // supports_credentials => false and all writes still require a valid
            // Supabase JWT.
            'http://localhost:3000',
        ],
        in_array(env('APP_ENV'), ['local', 'development', 'testing']) ? [
            'http://localhost:5173',
        ] : []
    ),
    'allowed_origins_patterns' => array_merge(
        [
            '#^https://[a-z0-9-]+\.sidest\.co$#', // Brand storefronts (Hydrogen on *.sidest.co)
            // Hydrogen Oxygen preview/staging URLs — used during brand onboarding
            // before Cloudflare DNS is pointed at the Oxygen project.
            '#^https://[a-z0-9][a-z0-9-]*\.o2\.myshopify\.dev$#',
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
    // Wildcard is safe here because supports_credentials => false — the
    // browser's wildcard+credentials restriction doesn't apply. Restricting to
    // an explicit list broke legitimate preflights where the frontend sends
    // headers outside the four allowed (e.g. XSRF, Supabase client headers).
    // Server-side auth still enforced via Authorization: Bearer JWT regardless.
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
