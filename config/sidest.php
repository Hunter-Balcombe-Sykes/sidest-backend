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
        // Functional / custom-link icons
        'scissors',
        'calendar',
        'map',
        'phone',
        'website',
        'link',
        'email',
        'whatsapp',
        // Social platform icons (mirrored in social_platforms registry below)
        'instagram',
        'facebook',
        'linkedin',
        'youtube',
        'tiktok',
        'x',
        'spotify',
        'soundcloud',
    ],
    'link_block_settings_keys' => [
        'open_in_new_tab',
        'rel_nofollow',
        'rel_sponsored',
        'rel_ugc',
        'highlight',
        'note',
        // Social link tagging — set by SocialLinkNormalizer when a brand-controlled
        // platform is selected. Soft tag in JSONB rather than a column; promote to
        // a real column (Option B) only when query-ability matters. See docs/social-links.md.
        'platform',
        'handle',
    ],

    /*
    |--------------------------------------------------------------------------
    | Social platform registry
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the social platforms surfaced in the link block
    | UI. Each entry tells the system how to validate, normalize, and render
    | links for one platform. The frontend reads a sanitised version of this
    | registry via GET /api/public/config/social-platforms and uses it to drive
    | the platform picker, input affordance, and display labels.
    |
    | Adding a new platform = one entry here + one icon_key above. No frontend
    | deploy needed — clients pick it up on next bootstrap.
    |
    | Security notes:
    | - All `handle_pattern` regexes are ASCII-only ([a-zA-Z0-9...]) to prevent
    |   Cyrillic/Greek homoglyph impersonation attacks.
    | - All `url_template` values are https:// — even http:// inputs get upgraded
    |   to https when the canonical URL is rebuilt.
    | - All quantifiers are bounded ({1,30} etc.) and non-nested → ReDoS-safe.
    | - `host_allowlist` is plain ASCII; punycoded IDN hosts won't match, which
    |   blocks a class of phishing attacks where a lookalike domain is registered.
    | - `handle_pattern`, `host_allowlist`, and `url_path_extractor` are stripped
    |   from the public registry response — they are server-side only.
    |
    | See docs/social-links.md for the full conceptual model.
    */
    'social_platforms' => [
        'instagram' => [
            'display_name'       => 'Instagram',
            'icon_key'           => 'instagram',
            'placeholder'        => '@yourname',
            'handle_pattern'     => '/^[a-zA-Z0-9._]{1,30}$/',
            'url_template'       => 'https://instagram.com/{handle}',
            'host_allowlist'     => ['instagram.com', 'www.instagram.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9._]{1,30})/?$#',
        ],
        'facebook' => [
            'display_name'       => 'Facebook',
            'icon_key'           => 'facebook',
            'placeholder'        => 'yourname',
            'handle_pattern'     => '/^[a-zA-Z0-9.]{5,50}$/',
            'url_template'       => 'https://facebook.com/{handle}',
            'host_allowlist'     => ['facebook.com', 'www.facebook.com', 'fb.com', 'm.facebook.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9.]{5,50})/?$#',
        ],
        'linkedin' => [
            'display_name'       => 'LinkedIn',
            'icon_key'           => 'linkedin',
            'placeholder'        => 'yourname',
            'handle_pattern'     => '/^[a-zA-Z0-9-]{3,100}$/',
            'url_template'       => 'https://linkedin.com/in/{handle}',
            'host_allowlist'     => ['linkedin.com', 'www.linkedin.com'],
            // Matches both /in/{handle} (personal) and /company/{handle} (company pages)
            'url_path_extractor' => '#^/(?:in|company)/([a-zA-Z0-9-]{3,100})/?$#',
        ],
        'youtube' => [
            'display_name'       => 'YouTube',
            'icon_key'           => 'youtube',
            'placeholder'        => '@yourname',
            'handle_pattern'     => '/^[a-zA-Z0-9._-]{3,30}$/',
            'url_template'       => 'https://youtube.com/@{handle}',
            'host_allowlist'     => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
            'url_path_extractor' => '#^/@([a-zA-Z0-9._-]{3,30})/?$#',
        ],
        'tiktok' => [
            'display_name'       => 'TikTok',
            'icon_key'           => 'tiktok',
            'placeholder'        => '@yourname',
            'handle_pattern'     => '/^[a-zA-Z0-9._]{2,24}$/',
            'url_template'       => 'https://tiktok.com/@{handle}',
            'host_allowlist'     => ['tiktok.com', 'www.tiktok.com', 'vm.tiktok.com'],
            'url_path_extractor' => '#^/@([a-zA-Z0-9._]{2,24})/?$#',
        ],
        'x' => [
            'display_name'       => 'X',
            'icon_key'           => 'x',
            'placeholder'        => '@yourname',
            // X handles are limited to 15 chars (Twitter legacy constraint)
            'handle_pattern'     => '/^[a-zA-Z0-9_]{1,15}$/',
            'url_template'       => 'https://x.com/{handle}',
            'host_allowlist'     => ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_]{1,15})/?$#',
        ],
        'spotify' => [
            'display_name'       => 'Spotify',
            'icon_key'           => 'spotify',
            'placeholder'        => 'yourname',
            'handle_pattern'     => '/^[a-zA-Z0-9._-]{3,40}$/',
            'url_template'       => 'https://open.spotify.com/user/{handle}',
            'host_allowlist'     => ['open.spotify.com', 'spotify.com'],
            // Matches /user/{handle} (profiles) and /artist/{id} (artist pages)
            'url_path_extractor' => '#^/(?:user|artist)/([a-zA-Z0-9._-]{3,40})/?$#',
        ],
        'soundcloud' => [
            'display_name'       => 'SoundCloud',
            'icon_key'           => 'soundcloud',
            'placeholder'        => 'yourname',
            'handle_pattern'     => '/^[a-zA-Z0-9_-]{3,40}$/',
            'url_template'       => 'https://soundcloud.com/{handle}',
            'host_allowlist'     => ['soundcloud.com', 'www.soundcloud.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,40})/?$#',
        ],
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
        'brand_gallery' => ['max' => (int) env('SIDEST_BRAND_GALLERY_IMAGE_MAX', 5)],
        'product_custom' => ['max' => (int) env('SIDEST_PRODUCT_CUSTOM_PHOTO_MAX', 1)],
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
