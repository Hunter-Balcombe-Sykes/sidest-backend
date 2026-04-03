<?php

return [
    'public_domain' => env(
        'SIDEST_PUBLIC_DOMAIN',
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

    'section_block_types' => ['gallery', 'services', 'shop', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info'],

    'professional_types' => [
        'brand' => 'Brand',
        'professional' => 'Professional',
        'influencer' => 'Influencer',
    ],

    'waitlist' => [
        'enabled' => (bool) env('SIDEST_WAITLIST_ENABLED', false),
        'types' => [
            'influencer' => 'Influencer',
            'professional' => 'Professional',
            'brand' => 'Brand',
            'other' => 'Other',
        ],
        'industries' => [
            'mens_grooming' => 'Mens Grooming',
            'womens_haircare' => 'Womens Haircare',
            'beauty_products' => 'Beauty Products',
            'vitamins_and_supplements' => 'Vitamins and Supplements',
            'services_and_software' => 'Services and Software',
            'other' => 'Other',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Account type defaults – applied during registration
    |----------------------------------------------------------------------
    | 'professional' is the base type. 'influencer' inherits from it.
    | 'brand' has its own distinct config.
    | 'affiliate' is an overlay applied when a professional/influencer
    | connects to a brand (via invite or manual connection).
    */
    'account_type_defaults' => [
        // Influencer is the base type (most basic account)
        'influencer' => [
            'allowed_sections'    => ['shop', 'services', 'gallery'],
            'default_sections'    => ['shop', 'services', 'gallery'],
            'is_published'        => true,
            'allowed_theme_count' => 3,
            'custom_links_allowed' => false,
            'default_contact' => [
                'full_name'  => 'Charlie',
                'email'      => 'charlie@ai.com',
                'phone'      => '1234 567 890',
                'source'     => 'system_default',
                'subscribed' => true,
            ],
        ],
        // Professional inherits influencer + adds booking, analytics, custom links
        'professional' => [
            'inherits'            => 'influencer',
            'allowed_sections'    => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info'],
            'default_sections'    => ['shop', 'services', 'gallery'],
            'custom_links_allowed' => true,
        ],
        'brand' => [
            'allowed_sections'   => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info'],
            'default_sections'   => [],
            'is_published'       => false,
            'allowed_theme_count' => null, // unlimited
            'custom_links_allowed' => true,
            'enforce_handle_equals_display_name' => true,
            'default_checkout_mode' => null,
            'default_site_settings' => [
                'design' => [
                    // System default colours, in-house font
                ],
            ],
        ],
        // Overlay applied when professional/influencer connects to a brand
        'affiliate' => [
            'auto_enable_sections'         => ['shop'],
            'use_brand_affiliate_theme'    => true,
            'use_brand_affiliate_products' => true,
        ],
    ],

    'soft_delete_retention_days' => (int) env('SOFT_DELETE_RETENTION_DAYS', 30),

    'analytics_raw_event_retention_days' => (int) env('ANALYTICS_RAW_EVENT_RETENTION_DAYS', 90),

    'throttle' => [
        'enabled' => (bool) env('SIDEST_THROTTLE_ENABLED', true),
    ],

    'media_disk' => env('SIDEST_MEDIA_DISK', 'media'),

    /*
    |----------------------------------------------------------------------
    | Image pools – per-professional limits
    |----------------------------------------------------------------------
    | gallery = showcase images (portfolio, work samples)
    | content = broad-use images (icon, headshot, banner, etc. – frontend assigns purpose)
    */
    'image_pools' => [
        'gallery' => ['max' => (int) env('SIDEST_GALLERY_IMAGE_MAX', 5)],
        'content' => ['max' => (int) env('SIDEST_CONTENT_IMAGE_MAX', 5)],
        'product' => ['max' => (int) env('SIDEST_PRODUCT_IMAGE_MAX', 5)],
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
            'quality' => (int) env('SIDEST_IMAGE_QUALITY', 92),
            'min_quality' => (int) env('SIDEST_IMAGE_MIN_QUALITY', 60),
            'target_kb' => (int) env('SIDEST_IMAGE_TARGET_KB', 500),
        ],
        'maximized' => [
            'format' => 'webp',
            'preserve_resolution' => true,
            'quality' => (int) env('SIDEST_IMAGE_MAXIMIZED_QUALITY', 100),
        ],
    ],

    'image_max_upload_size' => (int) env('SIDEST_IMAGE_MAX_UPLOAD_KB', 10240), // 10 MB

    /*
    |----------------------------------------------------------------------
    | Video uploads – feature flag + processing config
    |----------------------------------------------------------------------
    | Set SIDEST_VIDEO_UPLOADS_ENABLED=true only after dedicated video
    | workers are running on the "videos" queue.
    |
    | video_max_upload_size  = max video file size accepted (KB)
    | video_max_duration_seconds = max video length (seconds)
    | ffmpeg_binary / ffprobe_binary = absolute paths or commands on $PATH
    |
    | video_queue.connection = Laravel queue connection name for video jobs
    | video_queue.name       = queue name to dispatch video jobs onto
    | video_queue.timeout    = worker --timeout (seconds); must exceed
    |                          worst-case transcode time for your machine
    |
    | video_variants define the two MP4 output tiers.  HLS streams are
    | packaged from these MP4 files (no extra re-encode).
    */
    'video_uploads_enabled' => (bool) env('SIDEST_VIDEO_UPLOADS_ENABLED', false),

    'video_max_upload_size'      => (int) env('SIDEST_VIDEO_MAX_UPLOAD_KB', 512000), // 500 MB
    'video_max_duration_seconds' => (int) env('SIDEST_VIDEO_MAX_DURATION_SECONDS', 300), // 5 min

    'ffmpeg_binary'  => env('SIDEST_FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('SIDEST_FFPROBE_BINARY', 'ffprobe'),

    'video_queue' => [
        'connection' => env('SIDEST_VIDEO_QUEUE_CONNECTION', 'redis_video'),
        'name'       => env('SIDEST_VIDEO_QUEUE_NAME', 'videos'),
        'timeout'    => (int) env('SIDEST_VIDEO_QUEUE_TIMEOUT', 3600),
    ],

    'video_variants' => [
        'optimized' => [
            'resolution'       => env('SIDEST_VIDEO_OPTIMIZED_RESOLUTION', '1280x720'),
            'video_bitrate_kbps' => (int) env('SIDEST_VIDEO_OPTIMIZED_BITRATE', 2000),
            'audio_bitrate_kbps' => (int) env('SIDEST_VIDEO_OPTIMIZED_AUDIO_BITRATE', 128),
        ],
        'maximized' => [
            'resolution'       => env('SIDEST_VIDEO_MAXIMIZED_RESOLUTION', '1920x1080'),
            'video_bitrate_kbps' => (int) env('SIDEST_VIDEO_MAXIMIZED_BITRATE', 5000),
            'audio_bitrate_kbps' => (int) env('SIDEST_VIDEO_MAXIMIZED_AUDIO_BITRATE', 192),
        ],
    ],

    'professional_only_section_types' => [
        'barbershop_info',
        'sitepage_analytics',
        'booking',
    ],

    'store' => [
        'default_commission_rate' => (float) env('SIDEST_STORE_DEFAULT_COMMISSION', 15),
        'max_featured_products'   => (int) env('SIDEST_STORE_MAX_FEATURED', 10),
        'checkout_session_ttl_minutes' => (int) env('SIDEST_STORE_CHECKOUT_SESSION_TTL_MINUTES', 120),
        'payout_hold_days' => (int) env('SIDEST_STORE_PAYOUT_HOLD_DAYS', 7),
        'min_payout_hold_days' => 7,
        'platform_fee_percent' => (float) env('SIDEST_STORE_PLATFORM_FEE_PERCENT', 3),
    ],

    'form_timing' => [
        'min_ms' => (int) env('FORM_TIMING_MIN_MS', 2500),      // 2.5s minimum fill time
        'max_ms' => (int) env('FORM_TIMING_MAX_MS', 43200000),  // 12h max (stale form)
    ],

    'notification_retention_days' => [
        'policy_update'        => 365,
        'incident'             => 14,
        'feature_announcement' => 30,
        'default'              => 30,
        'invite'               => 90,
        'commission'           => 365,
        'payout'               => 365,
        'integration'          => 60,
        'analytics_weekly'     => 30,
        'analytics_milestones' => 90,
        'profile_task'         => 180,
        'catalog_change'       => 60,
        'brand_status'         => 60,
        'subscription'         => 365,
        'brand_link'           => 60,
    ],

    'notifications' => [
        'email_enabled' => (bool) env('NOTIFICATIONS_EMAIL_ENABLED', false),
    ],
];
