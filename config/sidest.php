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
        // Booking platform icons
        'fresha',
        'booksy',
        'timely',
        'calendly',
        'square',
        // Education platform icons
        'stan',
        'skool',
        'kajabi',
        'circle',
        // Event platform icons
        'eventbrite',
        'humanitix',
        'luma',
        'partiful',
        // Content platform icons
        'apple_podcasts',
        'substack',
        'bandcamp',
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
        // Link category — one of config('sidest.link_categories'). Required on every
        // write; resolved from the platform's default_category when not supplied.
        // Same JSONB-first rationale as `platform` above.
        'category',
    ],

    /*
    |--------------------------------------------------------------------------
    | Link categories
    |--------------------------------------------------------------------------
    |
    | Fixed enum applied to every link block in site.blocks.settings.category.
    | One source of truth — imported by the Form Requests, the public registry
    | endpoint response, and the backfill command. Do not add values without
    | updating the frontend category picker and confirming the public mini-site
    | renderer handles the new value.
    */
    'link_categories' => ['social', 'booking', 'education', 'content', 'events', 'other'],

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
            'display_name' => 'Instagram',
            'icon_key' => 'instagram',
            'placeholder' => '@yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._]{1,30}$/',
            'url_template' => 'https://instagram.com/{handle}',
            'host_allowlist' => ['instagram.com', 'www.instagram.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9._]{1,30})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'facebook' => [
            'display_name' => 'Facebook',
            'icon_key' => 'facebook',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9.]{5,50}$/',
            'url_template' => 'https://facebook.com/{handle}',
            'host_allowlist' => ['facebook.com', 'www.facebook.com', 'fb.com', 'm.facebook.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9.]{5,50})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'linkedin' => [
            'display_name' => 'LinkedIn',
            'icon_key' => 'linkedin',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,100}$/',
            'url_template' => 'https://linkedin.com/in/{handle}',
            'host_allowlist' => ['linkedin.com', 'www.linkedin.com'],
            // Matches both /in/{handle} (personal) and /company/{handle} (company pages)
            'url_path_extractor' => '#^/(?:in|company)/([a-zA-Z0-9-]{3,100})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'youtube' => [
            'display_name' => 'YouTube',
            'icon_key' => 'youtube',
            'placeholder' => '@yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._-]{3,30}$/',
            'url_template' => 'https://youtube.com/@{handle}',
            'host_allowlist' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
            'url_path_extractor' => '#^/@([a-zA-Z0-9._-]{3,30})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'tiktok' => [
            'display_name' => 'TikTok',
            'icon_key' => 'tiktok',
            'placeholder' => '@yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._]{2,24}$/',
            'url_template' => 'https://tiktok.com/@{handle}',
            'host_allowlist' => ['tiktok.com', 'www.tiktok.com', 'vm.tiktok.com'],
            'url_path_extractor' => '#^/@([a-zA-Z0-9._]{2,24})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'x' => [
            'display_name' => 'X',
            'icon_key' => 'x',
            'placeholder' => '@yourname',
            // X handles are limited to 15 chars (Twitter legacy constraint)
            'handle_pattern' => '/^[a-zA-Z0-9_]{1,15}$/',
            'url_template' => 'https://x.com/{handle}',
            'host_allowlist' => ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_]{1,15})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'spotify' => [
            'display_name' => 'Spotify',
            'icon_key' => 'spotify',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._-]{3,40}$/',
            'url_template' => 'https://open.spotify.com/user/{handle}',
            'host_allowlist' => ['open.spotify.com', 'spotify.com'],
            // Matches /user/{handle} (profiles) and /artist/{id} (artist pages)
            'url_path_extractor' => '#^/(?:user|artist)/([a-zA-Z0-9._-]{3,40})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'soundcloud' => [
            'display_name' => 'SoundCloud',
            'icon_key' => 'soundcloud',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,40}$/',
            'url_template' => 'https://soundcloud.com/{handle}',
            'host_allowlist' => ['soundcloud.com', 'www.soundcloud.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,40})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],

        // --- Booking platforms (default_category: booking) ---
        'fresha' => [
            'display_name' => 'Fresha',
            'icon_key' => 'fresha',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://fresha.com/a/{handle}',
            'host_allowlist' => ['fresha.com', 'www.fresha.com'],
            'url_path_extractor' => '#^/a/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'booksy' => [
            'display_name' => 'Booksy',
            'icon_key' => 'booksy',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,80}$/',
            'url_template' => 'https://booksy.com/en-us/{handle}',
            'host_allowlist' => ['booksy.com', 'www.booksy.com'],
            // Booksy URLs include a locale prefix (e.g. /en-us/12345_salon-name)
            'url_path_extractor' => '#^/[a-z]{2}-[a-z]{2}/([a-zA-Z0-9_-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'timely' => [
            'display_name' => 'Timely',
            'icon_key' => 'timely',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://book.gettimely.com/book/{handle}',
            'host_allowlist' => ['gettimely.com', 'book.gettimely.com', 'www.gettimely.com'],
            'url_path_extractor' => '#^/book/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'calendly' => [
            'display_name' => 'Calendly',
            'icon_key' => 'calendly',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{2,40}$/',
            'url_template' => 'https://calendly.com/{handle}',
            'host_allowlist' => ['calendly.com', 'www.calendly.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{2,40})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'square' => [
            'display_name' => 'Square',
            'icon_key' => 'square',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://book.squareup.com/appointments/{handle}',
            'host_allowlist' => ['book.squareup.com', 'squareup.com', 'www.squareup.com'],
            'url_path_extractor' => '#^/appointments/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],

        // --- Education platforms — path mode (default_category: education) ---
        'stan' => [
            'display_name' => 'Stan',
            'icon_key' => 'stan',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{2,40}$/',
            'url_template' => 'https://stan.store/{handle}',
            'host_allowlist' => ['stan.store', 'www.stan.store'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{2,40})/?$#',
            'default_category' => 'education',
            'handle_location' => 'path',
        ],
        'skool' => [
            'display_name' => 'Skool',
            'icon_key' => 'skool',
            'placeholder' => 'community-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,60}$/',
            'url_template' => 'https://skool.com/{handle}',
            'host_allowlist' => ['skool.com', 'www.skool.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{3,60})/?$#',
            'default_category' => 'education',
            'handle_location' => 'path',
        ],

        // --- Education platforms — subdomain mode (default_category: education) ---
        // Handle lives in the subdomain: {handle}.mykajabi.com / {handle}.circle.so
        // host_allowlist[0] = base domain; labelled-suffix match in normalizer.
        'kajabi' => [
            'display_name' => 'Kajabi',
            'icon_key' => 'kajabi',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.mykajabi.com/',
            'host_allowlist' => ['mykajabi.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'education',
            'handle_location' => 'subdomain',
        ],
        'circle' => [
            'display_name' => 'Circle',
            'icon_key' => 'circle',
            'placeholder' => 'community-name',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.circle.so/',
            'host_allowlist' => ['circle.so'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'education',
            'handle_location' => 'subdomain',
        ],

        // --- Event platforms (default_category: events) ---
        // Most event URLs are event-specific (/e/abc-123), not profile URLs.
        // The url_path_extractor targets the "organizer profile" shape; deep
        // links fall through to the lenient URL fallback — see docs/social-links.md §5.2.
        'eventbrite' => [
            'display_name' => 'Eventbrite',
            'icon_key' => 'eventbrite',
            'placeholder' => 'organizer-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://eventbrite.com/o/{handle}',
            'host_allowlist' => ['eventbrite.com', 'www.eventbrite.com'],
            'url_path_extractor' => '#^/o/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'humanitix' => [
            'display_name' => 'Humanitix',
            'icon_key' => 'humanitix',
            'placeholder' => 'organizer-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://humanitix.com/host/{handle}',
            'host_allowlist' => ['humanitix.com', 'www.humanitix.com', 'events.humanitix.com'],
            'url_path_extractor' => '#^/host/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'luma' => [
            'display_name' => 'Luma',
            'icon_key' => 'luma',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{2,40}$/',
            'url_template' => 'https://lu.ma/{handle}',
            'host_allowlist' => ['lu.ma', 'www.lu.ma'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{2,40})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'partiful' => [
            'display_name' => 'Partiful',
            'icon_key' => 'partiful',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,40}$/',
            'url_template' => 'https://partiful.com/u/{handle}',
            'host_allowlist' => ['partiful.com', 'www.partiful.com'],
            'url_path_extractor' => '#^/u/([a-zA-Z0-9-]{3,40})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],

        // --- Content platforms — path mode (default_category: content) ---
        // Apple Podcasts URLs always have the numeric ID as the stable identifier:
        //   https://podcasts.apple.com/us/podcast/{slug}/id{numeric-id}
        // The extractor captures the numeric id; most users will paste the full
        // URL, so the lenient fallback does most of the real work here.
        'apple_podcasts' => [
            'display_name' => 'Apple Podcasts',
            'icon_key' => 'apple_podcasts',
            'placeholder' => 'Paste the show URL',
            'handle_pattern' => '/^\d{5,15}$/',
            'url_template' => 'https://podcasts.apple.com/us/podcast/id{handle}',
            'host_allowlist' => ['podcasts.apple.com'],
            'url_path_extractor' => '#^/[a-z]{2}/podcast/[a-zA-Z0-9-]+/id(\d{5,15})/?$#',
            'default_category' => 'content',
            'handle_location' => 'path',
        ],

        // --- Content platforms — subdomain mode (default_category: content) ---
        'substack' => [
            'display_name' => 'Substack',
            'icon_key' => 'substack',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.substack.com/',
            'host_allowlist' => ['substack.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'content',
            'handle_location' => 'subdomain',
        ],
        'bandcamp' => [
            'display_name' => 'Bandcamp',
            'icon_key' => 'bandcamp',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.bandcamp.com/',
            'host_allowlist' => ['bandcamp.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'content',
            'handle_location' => 'subdomain',
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
            'allowed_sections' => ['shop', 'services', 'gallery'],
            'default_sections' => ['shop', 'services', 'gallery'],
            'is_published' => true,
            'allowed_theme_count' => 3,
            'custom_links_allowed' => false,
            'default_contact' => [
                'full_name' => 'Charlie',
                'email' => 'charlie@ai.com',
                'phone' => '1234 567 890',
                'source' => 'system_default',
                'subscribed' => true,
            ],
        ],
        // Professional inherits influencer + adds booking, analytics, custom links
        'professional' => [
            'inherits' => 'influencer',
            'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info'],
            'default_sections' => ['shop', 'services', 'gallery'],
            'custom_links_allowed' => true,
        ],
        'brand' => [
            'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info'],
            'default_sections' => [],
            'is_published' => false,
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
            'auto_enable_sections' => ['shop'],
            'use_brand_affiliate_theme' => true,
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
        // Affiliate sitepage gallery + content panels both expose 6 slots
        // in the dashboard — keep the env override available, default to 6.
        'gallery' => ['max' => (int) env('SIDEST_GALLERY_IMAGE_MAX', 6)],
        'content' => ['max' => (int) env('SIDEST_CONTENT_IMAGE_MAX', 6)],
        'product' => ['max' => (int) env('SIDEST_PRODUCT_IMAGE_MAX', 5)],
        'brand_gallery' => ['max' => (int) env('SIDEST_BRAND_GALLERY_IMAGE_MAX', 5)],
        'product_custom' => ['max' => (int) env('SIDEST_PRODUCT_CUSTOM_PHOTO_MAX', 1)],
    ],

    /*
    |----------------------------------------------------------------------
    | Image variants configuration
    |----------------------------------------------------------------------
    | - optimized: adaptive quality targeting ~500KB, capped at 2400px
    |   long edge. Serves in-page rendering and gallery thumbnails.
    | - maximized: higher quality cap at 4000px long edge. Serves hero
    |   images and 3x retina hi-DPI displays.
    |
    | width / height = pixel caps applied via 'inside' fit — never upscales,
    |                  preserves aspect ratio, caps the long edge to the
    |                  smaller of the two dimensions. Equal w/h = long-edge cap.
    | fit            = 'inside' (fit within bounds, no crop) or 'cover' (crop).
    | quality        = preferred WebP quality ceiling (1-100). 92 is visually
    |                  indistinguishable from 100 and ~30% smaller.
    | min_quality    = lowest allowed quality while targeting size.
    | target_kb      = target max file size in kilobytes (triggers binary-search
    |                  quality targeting when set).
    |
    | NOTE: the preserve_resolution flag is still honoured when explicitly set
    | on a variant definition, but is no longer the default. Originals are
    | always stored in full via storeOriginal() — variants are for delivery.
    */
    'image_variants' => [
        'optimized' => [
            'format' => 'webp',
            'width' => 2400,
            'height' => 2400,
            'fit' => 'inside',
            'quality' => (int) env('SIDEST_IMAGE_QUALITY', 92),
            'min_quality' => (int) env('SIDEST_IMAGE_MIN_QUALITY', 60),
            'target_kb' => (int) env('SIDEST_IMAGE_TARGET_KB', 500),
        ],
        'maximized' => [
            'format' => 'webp',
            'width' => 4000,
            'height' => 4000,
            'fit' => 'inside',
            'quality' => (int) env('SIDEST_IMAGE_MAXIMIZED_QUALITY', 92),
        ],
    ],

    'image_max_upload_size' => (int) env('SIDEST_IMAGE_MAX_UPLOAD_KB', 10240), // 10 MB

    /*
    |----------------------------------------------------------------------
    | Image decode ceiling — pixel count, not file size
    |----------------------------------------------------------------------
    | Refuses to decode any uploaded image whose width × height exceeds
    | this many pixels, BEFORE any bitmap memory is allocated. This is
    | the defense against image-bomb uploads (tiny file, huge resolution)
    | and against legitimate ultra-high-resolution sources that would
    | blow worker memory_limit.
    |
    | Default is 24 MP — above typical phone sensors (12-16 MP), below
    | flagship 48 MP sensors. Conservative for a 256 MB worker memory_limit;
    | can be raised to ~50 MP when workers have more headroom.
    */
    'image_max_pixels' => (int) env('SIDEST_IMAGE_MAX_PIXELS', 24_000_000), // 24 MP

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

    'video_max_upload_size' => (int) env('SIDEST_VIDEO_MAX_UPLOAD_KB', 512000), // 500 MB
    'video_max_duration_seconds' => (int) env('SIDEST_VIDEO_MAX_DURATION_SECONDS', 300), // 5 min

    'ffmpeg_binary' => env('SIDEST_FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('SIDEST_FFPROBE_BINARY', 'ffprobe'),

    'video_queue' => [
        'connection' => env('SIDEST_VIDEO_QUEUE_CONNECTION', 'redis_video'),
        'name' => env('SIDEST_VIDEO_QUEUE_NAME', 'videos'),
        'timeout' => (int) env('SIDEST_VIDEO_QUEUE_TIMEOUT', 3600),
    ],

    'video_variants' => [
        'optimized' => [
            'resolution' => env('SIDEST_VIDEO_OPTIMIZED_RESOLUTION', '1280x720'),
            'video_bitrate_kbps' => (int) env('SIDEST_VIDEO_OPTIMIZED_BITRATE', 2000),
            'audio_bitrate_kbps' => (int) env('SIDEST_VIDEO_OPTIMIZED_AUDIO_BITRATE', 128),
        ],
        'maximized' => [
            'resolution' => env('SIDEST_VIDEO_MAXIMIZED_RESOLUTION', '1920x1080'),
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
        'max_featured_products' => (int) env('SIDEST_STORE_MAX_FEATURED', 10),
        'checkout_session_ttl_minutes' => (int) env('SIDEST_STORE_CHECKOUT_SESSION_TTL_MINUTES', 120),
        'payout_hold_days' => (int) env('SIDEST_STORE_PAYOUT_HOLD_DAYS', 7),
        'min_payout_hold_days' => 7,
        'platform_fee_percent' => (float) env('SIDEST_STORE_PLATFORM_FEE_PERCENT', 20),
        'grace_period_days' => (int) env('SIDEST_STORE_GRACE_PERIOD_DAYS', 30),
        'commission_void_window_days' => (int) env('SIDEST_STORE_COMMISSION_VOID_WINDOW_DAYS', 30),
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
        'invite' => 90,
        'commission' => 365,
        'payout' => 365,
        'integration' => 60,
        'analytics_weekly' => 30,
        'analytics_milestones' => 90,
        'profile_task' => 180,
        'catalog_change' => 60,
        'brand_status' => 60,
        'subscription' => 365,
        'brand_link' => 60,
    ],

    'notifications' => [
        'email_enabled' => (bool) env('NOTIFICATIONS_EMAIL_ENABLED', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Launch feature flags
    |----------------------------------------------------------------------
    | Master switches for functionality that's coded but not yet live.
    | All default to false; flip in .env once the feature is ready.
    |
    | smart_booking  — gates all /booking/* routes (professional, public,
    |                  analytics) and forbids selecting booking_mode='smart'.
    |                  When off, only manual booking (redirect link) works.
    | square_sync    — gates Square integration (/square/* routes, webhook,
    |                  observer dispatch, sync jobs).
    | fresha_sync    — gates Fresha integration (/fresha/* routes, webhook,
    |                  observer dispatch, sync jobs).
    |
    | Square/Fresha ONLY power smart booking — if smart_booking is off, their
    | flags are largely redundant but kept separate so we can enable one
    | provider before the other post-launch.
    */
    'features' => [
        'smart_booking' => (bool) env('SIDEST_SMART_BOOKING_ENABLED', false),
        'square_sync' => (bool) env('SIDEST_SQUARE_SYNC_ENABLED', false),
        'fresha_sync' => (bool) env('SIDEST_FRESHA_SYNC_ENABLED', false),
    ],
];
