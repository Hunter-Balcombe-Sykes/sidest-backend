<?php

namespace App\Listeners;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

// Counts cache hits, misses, and writes per key prefix into an hourly Redis hash
// so AggregateCacheMetricsJob can surface hit-rate trends and SLO violations to
// Nightwatch. Overhead is one HINCRBY + conditional EXPIRE per cache operation.
class RecordCacheMetrics
{
    // Internal prefixes that add noise without insight (lock acquisition, heartbeat keys).
    public const SKIP_PREFIXES = ['lock', 'scheduler'];

    // Hot-path prefixes whose hit rate is tracked against the SLO.
    public const SLO_PREFIXES = ['site', 'pro'];

    public const SLO_MIN_HIT_RATE = 0.9;

    // Redis hash key pattern. One key per UTC hour, expired after 48 h so yesterday's
    // data is still queryable when the next day's aggregation job runs.
    public const BUCKET_TTL_SECONDS = 172800; // 48 h

    public function handle(CacheHit|CacheMissed|KeyWritten $event): void
    {
        $prefix = $this->extractPrefix($event->key);

        if ($prefix === null) {
            return;
        }

        $bucket = now('UTC')->format('Y-m-d-H');
        $type = match (true) {
            $event instanceof CacheHit => 'hits',
            $event instanceof CacheMissed => 'misses',
            default => 'writes', // KeyWritten
        };

        try {
            $bucketKey = "cache_metrics:{$bucket}";
            $newValue = Redis::hIncrBy($bucketKey, "{$prefix}:{$type}", 1);

            // Set TTL only when the field is brand new, preventing per-request EXPIRE calls.
            // HINCRBY returning 1 means this field was just created. If another prefix
            // already exists in this bucket the hash (and its TTL) was already initialised.
            if ($newValue === 1) {
                // NX semantics: only set expiry when it hasn't been set yet. EXPIRE with
                // no options would reset the clock on every new prefix; use XX=false guard
                // instead since phpredis doesn't support EXPIRENX uniformly. Two concurrent
                // first-writes may both call EXPIRE — that's harmless (idempotent for same TTL).
                Redis::expire($bucketKey, self::BUCKET_TTL_SECONDS);
            }
        } catch (\Throwable $e) {
            // Never let a metrics write fail a cache operation.
            Log::warning('cache.metrics.record_failed', ['error' => $e->getMessage()]);
        }
    }

    private function extractPrefix(string $key): ?string
    {
        $prefix = explode(':', $key)[0];

        if (in_array($prefix, self::SKIP_PREFIXES, true)) {
            return null;
        }

        return $prefix;
    }
}
