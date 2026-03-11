<?php

return [
    'public_domain' => env(
        'COMET_PUBLIC_DOMAIN',
        parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'
    ),
    'reserved_subdomains' => [
        'www', 'api', 'admin', 'app', 'staff', 'dashboard',
        'support', 'help', 'billing', 'static', 'cdn', 'assets',
        'auth', 'docs', 'status', 'comet',
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

    'section_block_types' => ['gallery', 'services', 'shop', 'booking', 'barbershop_info'],

    'soft_delete_retention_days' => (int) env('SOFT_DELETE_RETENTION_DAYS', 30),

    'media_disk' => env('COMET_MEDIA_DISK', 'media'),

    /*
    |----------------------------------------------------------------------
    | Image pools – per-professional limits
    |----------------------------------------------------------------------
    | gallery = showcase images (portfolio, work samples)
    | content = broad-use images (icon, headshot, banner, etc. – frontend assigns purpose)
    */
    'image_pools' => [
        'gallery' => ['max' => (int) env('COMET_GALLERY_IMAGE_MAX', 5)],
        'content' => ['max' => (int) env('COMET_CONTENT_IMAGE_MAX', 5)],
    ],

    /*
    |----------------------------------------------------------------------
    | Image processing – dual full-resolution variants
    |----------------------------------------------------------------------
    | Every upload generates two WebP variants:
    | - optimized: adaptive quality target (~500KB by default)
    | - maximized: highest quality full-resolution WebP
    |
    | preserve_resolution = keep original dimensions (no resize cap).
    | quality             = preferred WebP quality ceiling (1-100).
    | min_quality         = lowest allowed quality while targeting size.
    | target_kb           = target max file size in kilobytes.
    */
    'image_variants' => [
        'optimized' => [
            'format' => 'webp',
            'preserve_resolution' => true,
            'quality' => (int) env('COMET_IMAGE_QUALITY', 92),
            'min_quality' => (int) env('COMET_IMAGE_MIN_QUALITY', 60),
            'target_kb' => (int) env('COMET_IMAGE_TARGET_KB', 500),
        ],
        'maximized' => [
            'format' => 'webp',
            'preserve_resolution' => true,
            'quality' => (int) env('COMET_IMAGE_MAXIMIZED_QUALITY', 100),
        ],
    ],

    'image_max_upload_size' => (int) env('COMET_IMAGE_MAX_UPLOAD_KB', 10240), // 10 MB

    'store' => [
        'default_commission_rate' => (float) env('COMET_STORE_DEFAULT_COMMISSION', 15),
        'max_featured_products'   => (int) env('COMET_STORE_MAX_FEATURED', 10),
    ],

    'form_timing' => [
        'min_ms' => (int) env('FORM_TIMING_MIN_MS', 2500),      // 2.5s minimum fill time
        'max_ms' => (int) env('FORM_TIMING_MAX_MS', 43200000),  // 12h max (stale form)
    ],

    'notification_retention_days' => [
        'policy_update' => 365,
        'incident' => 14,
        'feature_announcement' => 30,
        'default' => 30,
    ],
];
