<?php

namespace App\Services\Cache\Concerns;

trait JitteredTtl
{
    /**
     * Apply ±20% jitter to an integer TTL.
     *
     * mt_rand(0, 4000) / 10000.0 gives [0.0, 0.4]; adding 0.8 shifts to [0.8, 1.2].
     * Applied independently per call so primary and stale copies expire at different
     * wall-clock seconds across the fleet.
     */
    protected static function applyJitter(int $ttl): int
    {
        return (int) round($ttl * (0.8 + mt_rand(0, 4000) / 10000.0));
    }
}
