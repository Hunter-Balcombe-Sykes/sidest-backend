<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // Wildcard origin is safe because supports_credentials => false — the
    // browser's wildcard+credentials restriction doesn't apply. All auth is
    // via Authorization: Bearer JWT, not cookies. This allows the Shopify
    // admin extension sandbox, local dev servers, and any future frontend
    // to call the API without CORS preflight rejections.
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    // Wildcard headers are safe for the same reason as allowed_origins above:
    // supports_credentials => false means the browser's wildcard+credentials
    // restriction does not apply. If supports_credentials is ever set to true,
    // both allowed_origins and allowed_headers MUST be locked to explicit values.
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    // Cache CORS preflight responses for 24h. Browsers floor this to their own
    // caps (Chromium 2h, Safari 10min, Firefox 24h). Without this, every fetch
    // pays a fresh OPTIONS round-trip — observed as ~140 redundant preflights
    // per dashboard page load.
    'max_age' => 86400,
    'supports_credentials' => false,
];
