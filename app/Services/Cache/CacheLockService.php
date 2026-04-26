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
 * Two methods, depending on whether the closure can return null:
 *   - rememberLocked()         — closures that always return a non-null value
 *   - rememberLockedNullable() — closures that can return null (e.g. lookups that
 *                                may not find a row); uses a sentinel so cached
 *                                nulls survive across requests instead of triggering
 *                                a fresh DB hit each time.
 */
class CacheLockService
{
    /**
     * Sentinel cached by rememberLockedNullable when a closure returns null,
     * so the cache layer can distinguish "key absent" from "computed, was null".
     * Chosen as an unlikely-to-collide string rather than a constant object so
     * it survives Redis (de)serialisation cleanly.
     */
    private const NULL_SENTINEL = '__cache_lock_null_sentinel__';

    /**
     * Get the value at $key, or compute it via $callback under a single-flight lock.
     *
     * @param  string  $key  Cache key (the lock key is auto-derived as 'lock:'.$key)
     * @param  DateTimeInterface|int  $ttl  Same TTL semantics as Cache::remember (DateTime or seconds)
     * @param  Closure(): mixed  $callback  Closure that produces the value on miss; must not return null
     * @param  int  $lockSeconds  How long the lock is held before auto-expiring (must exceed worst-case closure runtime)
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

    /**
     * Like rememberLocked, but the callback may return null. Null results are cached
     * as a sentinel so subsequent reads return null without re-running the callback.
     *
     * @param  string  $key  Cache key
     * @param  DateTimeInterface|int  $ttl  TTL for non-null values
     * @param  Closure(): mixed  $callback  May return null
     * @param  DateTimeInterface|int|null  $nullTtl  TTL when caching a null result.
     *         Defaults to $ttl when null. Pass a shorter duration to retry "not found"
     *         lookups sooner (e.g. when a row may appear in the DB shortly after a miss).
     */
    public function rememberLockedNullable(
        string $key,
        DateTimeInterface|int $ttl,
        Closure $callback,
        DateTimeInterface|int|null $nullTtl = null,
        int $lockSeconds = 10,
        int $blockSeconds = 5,
    ): mixed {
        $cached = Cache::get($key);
        if ($cached === self::NULL_SENTINEL) {
            return null;
        }
        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock('lock:'.$key, $lockSeconds);

        try {
            $lock->block($blockSeconds);
        } catch (LockTimeoutException) {
            $warm = Cache::get($key);
            if ($warm === self::NULL_SENTINEL) {
                return null;
            }
            if ($warm !== null) {
                return $warm;
            }

            return $callback();
        }

        try {
            $rechecked = Cache::get($key);
            if ($rechecked === self::NULL_SENTINEL) {
                return null;
            }
            if ($rechecked !== null) {
                return $rechecked;
            }

            $value = $callback();
            if ($value === null) {
                Cache::put($key, self::NULL_SENTINEL, $nullTtl ?? $ttl);
            } else {
                Cache::put($key, $value, $ttl);
            }

            return $value;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // ignore
            }
        }
    }
}
