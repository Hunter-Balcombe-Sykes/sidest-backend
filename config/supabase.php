<?php

return [
    'url' => env('SUPABASE_URL'),                       // e.g. https://abc123.supabase.co
    'anon_key' => env('SUPABASE_ANON_KEY'),

    // Issuer usually looks like: https://<project-ref>.supabase.co/auth/v1
    'jwt_issuer' => env('SUPABASE_JWT_ISSUER'),

    // Most user access tokens use aud = "authenticated"
    'jwt_audience' => env('SUPABASE_JWT_AUD', 'authenticated'),

    'jwks_url' => env('SUPABASE_JWKS_URL'),             // full URL
    'jwks_cache_seconds' => (int) env('SUPABASE_JWKS_CACHE_SECONDS', 300),

    // Service role key for server-side admin operations (user creation, etc.)
    'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
];
