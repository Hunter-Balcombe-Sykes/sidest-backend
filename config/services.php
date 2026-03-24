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
    ],

    'shopify' => [
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
        'app_scopes' => env('SHOPIFY_APP_SCOPES', ''),
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),
        'fallback_secret' => env('SHOPIFY_FALLBACK_SECRET'),
        'webhook_orders_topic' => env('SHOPIFY_WEBHOOK_ORDERS_TOPIC', 'orders/paid'),
    ],

];
