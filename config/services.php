<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Google Maps / Places — used client-side by the Hydrogen storefront
    // for address autocomplete. Key is HTTP-referrer-restricted in
    // Google Cloud, so it's safe to expose via /public/config/integrations.
    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'square' => [
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'client_secret' => env('SQUARE_CLIENT_SECRET'),
        'environment' => env('SQUARE_ENVIRONMENT', 'production'),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        'webhook_notification_url' => env('SQUARE_WEBHOOK_NOTIFICATION_URL'),
    ],

    'fresha' => [
        'client_id' => env('FRESHA_CLIENT_ID'),
        'client_secret' => env('FRESHA_CLIENT_SECRET'),
        'environment' => env('FRESHA_ENVIRONMENT', 'production'),
        'webhook_signature_key' => env('FRESHA_WEBHOOK_SIGNATURE_KEY'),
        'webhook_notification_url' => env('FRESHA_WEBHOOK_NOTIFICATION_URL'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'hydrogen' => [
        'api_key' => env('HYDROGEN_API_KEY'),
    ],

    // Cloudflare DNS API — used to provision platform subdomains (brand.sidest.co)
    // for Hydrogen storefronts. Zone must correspond to the sidest.co domain.
    'cloudflare' => [
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    // Cloudflare Turnstile — bot-protection for public lead-capture endpoints.
    // Only required when PARTNA_CAPTCHA_ENABLED=true.
    'turnstile' => [
        'secret_key' => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
    ],

    // Shared key for Sidest-Embedded Shopify app → backend calls.
    // Set in both .env (Laravel) and PARTNA_EMBEDDED_API_KEY env var in the Remix app.
    'embedded' => [
        'api_key' => env('PARTNA_EMBEDDED_API_KEY', env('SIDEST_EMBEDDED_API_KEY')),
    ],

    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID'),
        'client_secret' => env('TWITCH_CLIENT_SECRET'),
    ],
    'kick' => [
        'client_id' => env('KICK_CLIENT_ID'),
        'client_secret' => env('KICK_CLIENT_SECRET'),
    ],

    'shopify' => [
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
        'app_scopes' => env('SHOPIFY_APP_SCOPES', ''),
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_API_SECRET')),
        'fallback_secret' => env('SHOPIFY_FALLBACK_SECRET'),
        'webhook_orders_topic' => env('SHOPIFY_WEBHOOK_ORDERS_TOPIC', 'orders/paid'),
        // Must match `handle` in Sidest-Embedded/shopify.app.toml. Shopify's
        // admin routes apps under /store/<shop>/apps/<app_handle>, so a
        // mismatch 404s the post-install redirect. Override via env when
        // multiple app builds share this codebase.
        'app_handle' => env('SHOPIFY_APP_HANDLE', 'side-st-hydrogen'),

        // Admin API throttle client config. Shopify standard-plan GraphQL
        // bucket is 1000 points, restoring at 100 pts/sec. Plus is 2000/200.
        // We learn the actual values from throttleStatus on every response.
        'throttle' => [
            'default_max_capacity' => (int) env('SHOPIFY_THROTTLE_MAX', 1000),
            'default_restore_rate' => (int) env('SHOPIFY_THROTTLE_RESTORE_RATE', 100),
            'default_estimated_cost' => (int) env('SHOPIFY_THROTTLE_DEFAULT_COST', 10),
            'max_inprocess_retries' => (int) env('SHOPIFY_THROTTLE_MAX_RETRIES', 3),
            'max_wait_ms' => (int) env('SHOPIFY_THROTTLE_MAX_WAIT_MS', 5000),
            'default_timeout' => (int) env('SHOPIFY_HTTP_TIMEOUT', 20),
            'bucket_ttl_seconds' => 60,
            'bulk_lock_ttl_seconds' => 3600,
        ],
    ],

];
