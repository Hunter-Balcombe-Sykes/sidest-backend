<?php

return [
    'allowed_origins' => array_filter([
        'http://localhost:3000',
        'http://localhost:5173',  // Vite default
        env('FRONTEND_URL'),       // Add to .env
        env('APP_URL'),
    ]),
    'allowed_headers' => ['*'],
    'allowed_methods' => ['*'],
    'supports_credentials' => true,
];
