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
    // Wildcard headers equally safe for the same reason.
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
