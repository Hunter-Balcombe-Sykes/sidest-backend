<?php

namespace App\Services\FeatureFlags;

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves feature flag state for a given professional/brand context.
 *
 * Resolution order (highest → lowest precedence):
 *   1. Brand-scoped override (if brand passed)
 *   2. Professional-scoped override
 *   3. Percentage rollout — deterministic hash(key + pro.id) % 100
 *   4. Registry default (feature_flags.default_enabled)
 *   5. Config fallback — config('partna.features.{key}', false)
 *
 * All lookups are served from Redis (via CacheLockService) with a 5-minute TTL
 * and ±60s jitter. Three cache keys are used per context:
 *   - ff:registry          — all FeatureFlag rows (defaults + rollout %)
 *   - ff:pro:{proId}       — all active overrides for a professional
 *   - ff:brand:{brandId}   — all active overrides for a brand
 *
 * setOverride/clearOverride flush the relevant pro/brand key so the next
 * read rebuilds from DB immediately (push invalidation).
 *
 * On any cache failure, falls back to direct DB queries and logs a warning.
 */
class FeatureFlagService
{
    private const BASE_TTL_SECONDS = 300;

    private const TTL_JITTER_SECONDS = 60;

    private const REGISTRY_KEY = 'ff:registry';

    public function __construct(private CacheLockService $cacheLock) {}

    public function enabled(string $key, ?Professional $pro = null, ?BrandProfile $brand = null): bool
    {
        try {
            [$registry, $proOverrides, $brandOverrides] = $this->loadAll($pro, $brand);

            return $this->resolveFromArrays($key, $registry, $proOverrides, $brandOverrides, $pro);
        } catch (Throwable $e) {
            Log::warning('feature_flags.cache_unavailable', [
                'error' => $e->getMessage(),
                'flag_key' => $key,
                'professional_id' => $pro?->id,
                'brand_id' => $brand?->id,
                'request_id' => request()?->header('X-Request-Id'),
            ]);

            return $this->resolveFromDb($key, $pro, $brand);
        }
    }

    /**
     * Return the full ['key' => bool, ...] map for the given scope.
     */
    public function allFor(?Professional $pro = null, ?BrandProfile $brand = null): array
    {
        try {
            [$registry, $proOverrides, $brandOverrides] = $this->loadAll($pro, $brand);
        } catch (Throwable $e) {
            Log::warning('feature_flags.cache_unavailable', [
                'error' => $e->getMessage(),
                'method' => 'allFor',
                'professional_id' => $pro?->id,
                'brand_id' => $brand?->id,
                'request_id' => request()?->header('X-Request-Id'),
            ]);

            // Degrade: load all data in 3 queries (registry + pro overrides + brand overrides)
            // then resolve from arrays without cache.
            return $this->allForFromDb($pro, $brand);
        }

        $result = [];
        foreach (array_keys($registry) as $k) {
            $result[$k] = $this->resolveFromArrays($k, $registry, $proOverrides, $brandOverrides, $pro);
        }

        return $result;
    }

    /**
     * Upsert a feature flag override for the given scope, then invalidate
     * the relevant cache key so the next read reflects the change.
     */
    public function setOverride(
        string $key,
        OverrideScope $scope,
        bool $enabled,
        ?string $reason = null,
        ?Carbon $expiresAt = null,
        ?string $createdBy = null,
    ): void {
        $attrs = [
            'flag_key' => $key,
            'enabled' => $enabled,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ];

        if ($scope->brandId !== null) {
            FeatureFlagOverride::updateOrCreate(
                ['flag_key' => $key, 'brand_id' => $scope->brandId],
                $attrs + ['brand_id' => $scope->brandId, 'professional_id' => null],
            );
        } else {
            FeatureFlagOverride::updateOrCreate(
                ['flag_key' => $key, 'professional_id' => $scope->professionalId, 'brand_id' => null],
                $attrs + ['professional_id' => $scope->professionalId, 'brand_id' => null],
            );
        }

        try {
            if ($scope->brandId !== null) {
                $this->forgetBrand($scope->brandId);
            } else {
                $this->forgetPro($scope->professionalId);
            }
        } catch (Throwable $e) {
            Log::warning('feature_flags.invalidation_failed', [
                'error' => $e->getMessage(),
                'flag_key' => $key,
                'scope_brand_id' => $scope->brandId,
                'scope_professional_id' => $scope->professionalId,
            ]);
        }
    }

    /**
     * Delete a feature flag override for the given scope, then invalidate
     * the relevant cache key.
     */
    public function clearOverride(string $key, OverrideScope $scope): void
    {
        $query = FeatureFlagOverride::where('flag_key', $key);

        if ($scope->brandId !== null) {
            $query->where('brand_id', $scope->brandId)->delete();
        } else {
            $query->where('professional_id', $scope->professionalId)->whereNull('brand_id')->delete();
        }

        try {
            if ($scope->brandId !== null) {
                $this->forgetBrand($scope->brandId);
            } else {
                $this->forgetPro($scope->professionalId);
            }
        } catch (Throwable $e) {
            Log::warning('feature_flags.invalidation_failed', [
                'error' => $e->getMessage(),
                'flag_key' => $key,
                'scope_brand_id' => $scope->brandId,
                'scope_professional_id' => $scope->professionalId,
            ]);
        }
    }

    /** Flush only the registry key (use after adding/editing a FeatureFlag row). */
    public function flushRegistry(): void
    {
        Cache::forget(self::REGISTRY_KEY);
        Cache::forget(self::REGISTRY_KEY.':stale');
    }

    /** Flush all FF cache keys. Useful in tests and admin operations. */
    public function flush(): void
    {
        $this->flushRegistry();
    }

    public function forgetPro(string $proId): void
    {
        Cache::forget("ff:pro:{$proId}");
        Cache::forget("ff:pro:{$proId}:stale");
    }

    public function forgetBrand(string $brandId): void
    {
        Cache::forget("ff:brand:{$brandId}");
        Cache::forget("ff:brand:{$brandId}:stale");
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function loadAll(?Professional $pro, ?BrandProfile $brand): array
    {
        $registry = $this->loadRegistry();
        $proOverrides = $pro !== null ? $this->loadProOverrides($pro->id) : [];
        $brandOverrides = $brand !== null ? $this->loadBrandOverrides($brand->id) : [];

        return [$registry, $proOverrides, $brandOverrides];
    }

    /**
     * Resolve a single flag key against pre-loaded arrays (no DB/cache I/O).
     *
     * @param  array<string, array{default_enabled: bool, rollout_percent: int}>  $registry
     * @param  array<string, bool>  $proOverrides
     * @param  array<string, bool>  $brandOverrides
     */
    private function resolveFromArrays(
        string $key,
        array $registry,
        array $proOverrides,
        array $brandOverrides,
        ?Professional $pro,
    ): bool {
        // 1. Brand override wins.
        if (isset($brandOverrides[$key])) {
            return $brandOverrides[$key];
        }

        // 2. Pro override.
        if (isset($proOverrides[$key])) {
            return $proOverrides[$key];
        }

        $flag = $registry[$key] ?? null;

        // 3. Percentage rollout — deterministic: same pro+key always lands in the same bucket.
        //    abs() guards against negative crc32 values on 64-bit PHP.
        if ($flag !== null && $pro !== null && $flag['rollout_percent'] > 0) {
            if ((abs(crc32($key.$pro->id)) % 100) < $flag['rollout_percent']) {
                return true;
            }
        }

        // 4. Global registry default.
        if ($flag !== null) {
            return $flag['default_enabled'];
        }

        // 5. Config fallback — used for flags that don't yet have a DB row.
        return (bool) config('partna.features.'.$key, false);
    }

    private function jitteredTtl(): int
    {
        return self::BASE_TTL_SECONDS + random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS);
    }

    /** Load all active FeatureFlag rows as a key-indexed map. */
    private function loadRegistry(): array
    {
        return $this->cacheLock->rememberLocked(
            self::REGISTRY_KEY,
            $this->jitteredTtl(),
            function (): array {
                return FeatureFlag::query()
                    ->whereNull('deleted_at')
                    ->get()
                    ->mapWithKeys(fn ($f) => [$f->key => [
                        'default_enabled' => (bool) $f->default_enabled,
                        'rollout_percent' => (int) $f->rollout_percent,
                    ]])
                    ->all();
            },
        );
    }

    /**
     * Load all non-expired, pro-scoped overrides for $proId as a flag_key → bool map.
     *
     * The whereExists filter guards against returning overrides for soft-deleted
     * professionals. Skipped in SQLite test environment (schema-qualified table names
     * aren't supported there, but soft-deletes don't apply in tests anyway).
     */
    private function loadProOverrides(string $proId): array
    {
        return $this->cacheLock->rememberLocked(
            "ff:pro:{$proId}",
            $this->jitteredTtl(),
            function () use ($proId): array {
                $query = FeatureFlagOverride::query()
                    ->where('professional_id', $proId)
                    ->whereNull('brand_id')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

                // Only apply the cross-schema soft-delete joins on PostgreSQL — SQLite
                // (used in tests) doesn't support schema-qualified table names.
                if (DB::getDriverName() === 'pgsql') {
                    $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('core.professionals')
                        ->whereColumn('core.professionals.id', 'core.feature_flag_overrides.professional_id')
                        ->whereNull('core.professionals.deleted_at'));

                    // Exclude overrides whose flag has been soft-deleted.
                    $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('core.feature_flags')
                        ->whereColumn('core.feature_flags.key', 'core.feature_flag_overrides.flag_key')
                        ->whereNull('core.feature_flags.deleted_at'));
                }

                return $query->get()
                    ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                    ->all();
            },
        );
    }

    /**
     * Load all non-expired, brand-scoped overrides for $brandId as a flag_key → bool map.
     */
    private function loadBrandOverrides(string $brandId): array
    {
        return $this->cacheLock->rememberLocked(
            "ff:brand:{$brandId}",
            $this->jitteredTtl(),
            function () use ($brandId): array {
                $query = FeatureFlagOverride::query()
                    ->where('brand_id', $brandId)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

                if (DB::getDriverName() === 'pgsql') {
                    $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('brand.brand_profiles')
                        ->whereColumn('brand.brand_profiles.id', 'core.feature_flag_overrides.brand_id')
                        ->whereNull('brand.brand_profiles.deleted_at'));

                    // Exclude overrides whose flag has been soft-deleted.
                    $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('core.feature_flags')
                        ->whereColumn('core.feature_flags.key', 'core.feature_flag_overrides.flag_key')
                        ->whereNull('core.feature_flags.deleted_at'));
                }

                return $query->get()
                    ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                    ->all();
            },
        );
    }

    /**
     * Batch DB fallback for allFor() when cache is unavailable.
     * Loads registry + pro overrides + brand overrides in 3 queries, then
     * resolves the full map from arrays — no N+1 per flag key.
     */
    private function allForFromDb(?Professional $pro, ?BrandProfile $brand): array
    {
        $registry = FeatureFlag::query()
            ->whereNull('deleted_at')
            ->get()
            ->mapWithKeys(fn ($f) => [$f->key => [
                'default_enabled' => (bool) $f->default_enabled,
                'rollout_percent' => (int) $f->rollout_percent,
            ]])
            ->all();

        $proOverrides = [];
        if ($pro !== null) {
            $proOverrides = FeatureFlagOverride::query()
                ->where('professional_id', $pro->id)
                ->whereNull('brand_id')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get()
                ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                ->all();
        }

        $brandOverrides = [];
        if ($brand !== null) {
            $brandOverrides = FeatureFlagOverride::query()
                ->where('brand_id', $brand->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get()
                ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                ->all();
        }

        $result = [];
        foreach (array_keys($registry) as $k) {
            $result[$k] = $this->resolveFromArrays($k, $registry, $proOverrides, $brandOverrides, $pro);
        }

        return $result;
    }

    /**
     * Direct DB fallback used when the cache layer throws. No caching — each call
     * hits the DB, which is acceptable as this path is exceptional (cache failure).
     */
    private function resolveFromDb(string $key, ?Professional $pro, ?BrandProfile $brand): bool
    {
        $registry = FeatureFlag::query()
            ->whereNull('deleted_at')
            ->where('key', $key)
            ->get()
            ->mapWithKeys(fn ($f) => [$f->key => [
                'default_enabled' => (bool) $f->default_enabled,
                'rollout_percent' => (int) $f->rollout_percent,
            ]])
            ->all();

        $proOverrides = [];
        if ($pro !== null) {
            $row = FeatureFlagOverride::where('flag_key', $key)
                ->where('professional_id', $pro->id)
                ->whereNull('brand_id')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first();
            if ($row !== null) {
                $proOverrides[$key] = (bool) $row->enabled;
            }
        }

        $brandOverrides = [];
        if ($brand !== null) {
            $row = FeatureFlagOverride::where('flag_key', $key)
                ->where('brand_id', $brand->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first();
            if ($row !== null) {
                $brandOverrides[$key] = (bool) $row->enabled;
            }
        }

        return $this->resolveFromArrays($key, $registry, $proOverrides, $brandOverrides, $pro);
    }
}
