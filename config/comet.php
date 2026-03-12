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

    'professional_types' => [
        'barber' => 'Barber',
        'hairdresser' => 'Hairdresser',
        'influencer' => 'Influencer',
        'promoter' => 'Promoter',
        'barbershop' => 'Barbershop',
        'salon' => 'Salon',
    ],

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

    'legal' => [
        'site_scheme' => env('COMET_LEGAL_SITE_SCHEME', 'https'),
        'defaults' => [
            'contact_name' => env('COMET_LEGAL_DEFAULT_CONTACT_NAME', 'Customer Support'),
            'support_email' => env('COMET_LEGAL_DEFAULT_SUPPORT_EMAIL', 'support@comet.app'),
            'support_phone' => env('COMET_LEGAL_DEFAULT_SUPPORT_PHONE', 'N/A'),
        ],
        'templates' => [
            'privacy_policy' => <<<'TPL'
## Privacy Policy

**Effective date:** {{effective_date}}

**1.** **{{professional_legal_name}}**, trading as **{{barbershop_name}}** ("we", "us", "our"), operates this website at **{{site_url}}** to help customers learn about our barber services, make enquiries, book appointments, and, where available, browse or buy products. This short Privacy Policy explains how we handle personal information you provide when interacting with our business online.

**2.** We may collect personal information such as your name, phone number, email address, booking details, order details, messages, and information about how you use our website, including page views, clicks, browser/device information, and similar usage data. We collect this information when you fill out forms, make bookings, place orders, contact us, or interact with our site.

**3.** We use this information to run our barber business, respond to enquiries, manage bookings, send confirmations or updates, provide customer support, process purchases, improve our website, understand customer interest in our services or products, and protect our business from misuse or fraud. We may also use it for marketing where permitted by law, and you can opt out of marketing messages at any time.

**4.** Our website may be powered by Comet and may also use third-party tools for hosting, booking, analytics, communications, payments, or online store functions. Because of this, your information may be processed by trusted service providers acting on our behalf, and in some cases by third-party retailers or payment providers where you purchase products or use linked services.

**5.** We take reasonable steps to keep your personal information secure, but no online system is completely risk-free. Some of our service providers may store or process information outside Australia. You may contact us to request access to the personal information we hold about you, ask for corrections, or make a privacy complaint.

**6.** If you have any privacy questions or requests, please contact **{{contact_name}}** at **{{support_email}}** and **{{support_phone}}**. We may update this Privacy Policy from time to time, and the latest version will be published on our website.
TPL,
            'terms_and_conditions' => <<<'TPL'
## Terms and Conditions

**Effective date:** {{effective_date}}

**1.** These Terms and Conditions apply to your use of the website operated by **{{professional_legal_name}}**, trading as **{{barbershop_name}}**, at **{{site_url}}**. By using our website, making an enquiry, booking an appointment, or purchasing a product through our site or linked pages, you agree to these Terms.

**2.** Our website is designed to give customers access to our barber services, business information, branding, contact details, booking options, social links, and, where enabled, product listings or an online shop. We aim to keep all information accurate and current, but service descriptions, prices, availability, promotions, and features may change from time to time.

**3.** If bookings are available through our website, all bookings are subject to availability and confirmation. You must provide accurate information when booking, and we may contact you about confirmations, changes, reminders, cancellations, or no-shows. Any cancellation, rescheduling, deposit, or no-show rules shown on our website or at the time of booking will form part of these Terms.

**4.** If our website includes products, product recommendations, or an online shop, product availability and pricing may change without notice. Some products or payments may be handled by third-party platforms, retailers, or payment providers, and additional terms or policies may apply to those transactions.

**5.** We may use Comet and other third-party providers to power parts of our website, including hosting, booking tools, analytics, communications, payments, or store functionality. To the extent permitted by law, we are not responsible for interruptions, third-party platform issues, or external websites linked from our site, but nothing in these Terms excludes your rights under the Australian Consumer Law.

**6.** If you have questions about these Terms, bookings, products, or your dealings with **{{barbershop_name}}**, please contact us at **{{support_email}}** and **{{support_phone}}**. We may update these Terms from time to time, and the latest version will be published on our website.
TPL,
        ],
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
