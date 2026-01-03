<?php

return [
    'public_domain' => env(
        'COMET_PUBLIC_DOMAIN',
        parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'
    ),
    'reserved_subdomains' => [
        'www', 'api', 'admin', 'app', 'staff', 'dashboard',
        'support', 'help', 'billing', 'static', 'cdn', 'assets',
        'auth', 'docs', 'status',
    ],
    'link_block_icon_keys' => [
        'scissors',
        'calendar',
        'map',
        'phone',
        'instagram',
        'facebook',
        'tiktok',
        'youtube',
        'website',
        'link',
        'email',
        'whatsapp',
    ],
    'link_block_settings_keys' => [
        'open_in_new_tab',
        'rel_nofollow',
        'rel_sponsored',
        'rel_ugc',
        'highlight',
        'note',
    ],

    'section_block_types' => ['gallery','services','education','social','booking', 'bio',
        'work_history', 'promotional_text'],

    'soft_delete_retention_days' => (int) env('SOFT_DELETE_RETENTION_DAYS', 30),

    'media_bucket' => env('COMET_MEDIA_BUCKET', 'media'),

    'notification_retention_days' => [
        'policy_update' => 365,
        'incident' => 14,
        'feature_announcement' => 30,
        'default' => 30,
    ],
];
