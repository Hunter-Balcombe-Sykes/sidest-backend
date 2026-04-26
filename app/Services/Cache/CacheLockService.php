<?php

namespace App\Services\Cache;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Single-flight cache regeneration helper.
 *
 * Wraps Laravel's Cache::remember with a Cache::lock so that under concurrent
 * load only one request rebuilds an expired value while others wait and read
 * the freshly-filled cache. Models the proven pattern in
 * SiteCacheService::getPublicSitePayload.
 *
 * Use this for any cached value that:
 *   - is hot (likely to be requested concurrently when it expires), AND
 *   - is expensive to regenerate (multiple DB queries, joins, external calls).
 *
 * Closures must return a non-null value. Storing null requires sentinel
 * handling that this helper intentionally does not provide.
 */
class CacheLockService
{
    /**
     * Get the value at $key, or compute it via $callback under a single-flight lock.
     *
     * @param  string  $key       Cache key (the lock key is auto-derived as 'lock:'.$key)
     * @param  DateTimeInterface|int  $ttl  Same TTL semantics as Cache::remember (DateTime or seconds)
     * @param  Closure(): mixed  $callback  Closure that produces the value on miss; must not return null
     * @param  int  $lockSeconds   How long the lock is held before auto-expiring (must exceed worst-case closure runtime)
     * @param  int  $blockSeconds  How long a waiting request blocks for the lock before falling through
     */
    public function rememberLocked(
        string $key,
        DateTimeInterface|int $ttl,
        Closure $callback,
        int $lockSeconds = 10,
        int $blockSeconds = 5,
    ): mixed {
        // Fast path: value already cached, no lock needed.
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // Cache miss — acquire a per-key fill lock so only one process rebuilds.
        $lock = Cache::lock('lock:'.$key, $lockSeconds);

        try {
            $lock->block($blockSeconds);
        } catch (LockTimeoutException) {
            // Another process is filling the cache but took too long.
            // Return whatever is now cached, or fall through to compute as a last resort
            // so the user never gets nothing back. The stampede risk on this edge case
            // is bounded to requests that arrive in the timeout window.
            $warm = Cache::get($key);
            if ($warm !== null) {
                return $warm;
            }

            return $callback();
        }

        try {
            // Double-check: another process may have filled the cache while we waited.
            $rechecked = Cache::get($key);
            if ($rechecked !== null) {
                return $rechecked;
            }

            $value = $callback();
            Cache::put($key, $value, $ttl);

            return $value;
        } finally {
            // Always release — even if the closure threw — so we don't hold the lock
            // for its full TTL after a failure.
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock already released or driver doesn't support release-after-expiry; ignore.
            }
        }
    }
}
