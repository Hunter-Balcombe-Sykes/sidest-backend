<?php

namespace App\Http\Controllers\Concerns;

// V2: One-way HMAC-SHA256 hashing of client IP addresses using the app key for privacy-safe analytics storage.
trait HashesClientData
{
    protected function hashIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        return hash_hmac('sha256', $ip, config('app.key'));
    }
}
