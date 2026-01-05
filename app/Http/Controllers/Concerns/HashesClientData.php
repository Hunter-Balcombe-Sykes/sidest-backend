<?php

namespace App\Http\Controllers\Concerns;

trait HashesClientData
{
    protected function hashIp(? string $ip): ?string
    {
        if (! $ip) return null;
        return hash_hmac('sha256', $ip,  config('app.key'));
    }
}
