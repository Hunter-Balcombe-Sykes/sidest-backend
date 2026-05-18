# Feature Flag Overrides Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a per-tenant feature flag system supporting allowlist, percentage rollout, and per-tenant overrides, sharing one resolver and one Redis-cached storage layer.

**Architecture:** Two tables in `core` schema (`feature_flags` registry + `feature_flag_overrides`). One `FeatureFlagService` with a `feature()` helper for callsites. Resolution order: brand override → pro override → percentage rollout (deterministic hash) → registry default → `config()` fallback. Hot path is single Redis `mget`; writes push-invalidate.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL (Supabase), Redis cache, Pest 4. Migrations as raw SQL in `supabase/migrations/`, NOT Laravel migrations (composer guard rejects them).

**Spec:** `docs/superpowers/specs/2026-05-17-feature-flag-overrides-design.md`

**Pre-flight reading (do this before Task 1):**
- `backend/CLAUDE.md` — full project conventions (mandatory)
- `supabase/migrations/TEMPLATE.sql.example` — migration format
- `supabase/migrations/CONVENTIONS.md` — `NOT VALID` + `CONCURRENTLY` rules
- `app/Policies/BasePolicy.php` — policy convention
- `app/Models/BaseModel.php` — all models extend this
- One existing staff controller, e.g. `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionAdjustmentController.php`, for shape reference
- `app/Providers/AppServiceProvider.php` — where `Gate::policy()` lines live

---

## Task 1: Migration — schema + indexes

**Files:**
- Create: `supabase/migrations/202605180000000_create_feature_flags.sql`
- Create: `supabase/migrations/202605180000001_create_feature_flags_indexes.sql`

> **Audit fixes applied (P1 SCHEMA-1, P2 SCHEMA-2, P3 SCHEMA-3, P3 SCHEMA-4):** FK target is `brand.brand_profiles` (not `brand.brands` — that table does not exist; verified against migration history). `feature_flags` carries `deleted_at` for soft-delete consistency with the rest of Partna. `key` has a DB-level length CHECK matching the app validator. The override list query gets a composite `(flag_key, created_at DESC)` index. **Run Step 1 (verification) BEFORE Step 2 (DDL)** — the prior plan had these reversed.

- [ ] **Step 1: Verify FK target table names**

```bash
rg -n "CREATE TABLE core\.professionals|CREATE TABLE brand\." supabase/migrations/ -i | head -10
```

Expected: confirms `core.professionals` exists and identifies the canonical `brand.*` table name. As of 2026-05-18, the correct name is `brand.brand_profiles`; verify it has not been renamed before continuing. If it has been renamed, update every `brand.brand_profiles` reference in Step 2 to match.

- [ ] **Step 2: Write DDL migration**

Create `supabase/migrations/202605180000000_create_feature_flags.sql`:

```sql
BEGIN;

CREATE TABLE core.feature_flags (
    key text PRIMARY KEY,
    description text NOT NULL DEFAULT '',
    default_enabled boolean NOT NULL DEFAULT false,
    rollout_percent smallint NOT NULL DEFAULT 0,
    deleted_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT feature_flags_rollout_percent_range CHECK (rollout_percent >= 0 AND rollout_percent <= 100),
    CONSTRAINT feature_flags_key_length CHECK (length(key) <= 128),
    CONSTRAINT feature_flags_key_format CHECK (key ~ '^[a-z][a-z0-9_]*$')
);

CREATE TABLE core.feature_flag_overrides (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    flag_key text NOT NULL REFERENCES core.feature_flags(key) ON DELETE CASCADE,
    professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_id uuid NULL REFERENCES brand.brand_profiles(id) ON DELETE CASCADE,
    enabled boolean NOT NULL,
    reason text NULL,
    expires_at timestamptz NULL,
    created_by uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT feature_flag_overrides_scope_set CHECK (
        professional_id IS NOT NULL OR brand_id IS NOT NULL
    )
);

COMMIT;
```

> The `ON DELETE CASCADE` on `flag_key` is correct *for hard deletes only*. Soft delete is the normal lifecycle (via `deleted_at` + the `SoftDeletes` trait added in Task 2); hard delete remains available for cleanup and cascades sensibly when used.

- [ ] **Step 3: Write indexes migration**

Create `supabase/migrations/202605180000001_create_feature_flags_indexes.sql`:

```sql
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_pro_unique
    ON core.feature_flag_overrides (flag_key, professional_id)
    WHERE brand_id IS NULL;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_brand_unique
    ON core.feature_flag_overrides (flag_key, brand_id)
    WHERE brand_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_pro_lookup
    ON core.feature_flag_overrides (professional_id, flag_key)
    WHERE professional_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_brand_lookup
    ON core.feature_flag_overrides (brand_id, flag_key)
    WHERE brand_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_expires_at
    ON core.feature_flag_overrides (expires_at)
    WHERE expires_at IS NOT NULL;

-- Powers the admin override list query (ORDER BY created_at DESC at the staff endpoint)
CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_flag_key_created
    ON core.feature_flag_overrides (flag_key, created_at DESC);

-- Powers the active-flag scan from the resolver (soft-delete aware)
CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flags_active
    ON core.feature_flags (key)
    WHERE deleted_at IS NULL;
```

- [ ] **Step 4: Push to dev Supabase**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Expected: both migrations applied, no errors. Verify in Supabase SQL editor: `SELECT * FROM core.feature_flags LIMIT 1;` returns 0 rows, no error.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/202605180000000_create_feature_flags.sql supabase/migrations/202605180000001_create_feature_flags_indexes.sql
git commit -m "feat(db): feature_flags + feature_flag_overrides tables"
```

---

## Task 2: Eloquent models

**Files:**
- Create: `app/Models/Core/FeatureFlag.php`
- Create: `app/Models/Core/FeatureFlagOverride.php`

- [ ] **Step 1: Write FeatureFlag model**

Create `app/Models/Core/FeatureFlag.php`:

```php
<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeatureFlag extends BaseModel
{
    use SoftDeletes;

    protected $table = 'core.feature_flags';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'description', 'default_enabled', 'rollout_percent'];

    protected function casts(): array
    {
        return [
            'default_enabled' => 'boolean',
            'rollout_percent' => 'integer',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(FeatureFlagOverride::class, 'flag_key', 'key');
    }
}
```

> **Audit fix (P2 SCHEMA-2):** `SoftDeletes` added so `DELETE /staff/feature-flags/{key}` sets `deleted_at` instead of hard-destroying the flag and cascading away every override. Hard delete remains available via `forceDelete()` for actual cleanup.

- [ ] **Step 2: Write FeatureFlagOverride model**

Create `app/Models/Core/FeatureFlagOverride.php`:

```php
<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FeatureFlagOverride extends BaseModel
{
    protected $table = 'core.feature_flag_overrides';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'flag_key', 'professional_id', 'brand_id', 'enabled',
        'reason', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if (empty($row->id)) {
                $row->id = (string) Str::uuid();
            }
        });
    }

    public function flag(): BelongsTo
    {
        return $this->belongsTo(FeatureFlag::class, 'flag_key', 'key');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Core/FeatureFlag.php app/Models/Core/FeatureFlagOverride.php
git commit -m "feat(models): feature flag eloquent models"
```

---

## Task 3: OverrideScope value object

**Files:**
- Create: `app/Services/FeatureFlags/OverrideScope.php`
- Create: `tests/Unit/FeatureFlags/OverrideScopeTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/FeatureFlags/OverrideScopeTest.php`:

```php
<?php

use App\Services\FeatureFlags\OverrideScope;

it('builds a professional scope', function () {
    $scope = OverrideScope::forProfessional('pro-uuid-1');
    expect($scope->professionalId)->toBe('pro-uuid-1');
    expect($scope->brandId)->toBeNull();
});

it('builds a brand scope', function () {
    $scope = OverrideScope::forBrand('brand-uuid-1');
    expect($scope->brandId)->toBe('brand-uuid-1');
    expect($scope->professionalId)->toBeNull();
});

it('rejects scopes with neither id set', function () {
    OverrideScope::forProfessional('');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --compact tests/Unit/FeatureFlags/OverrideScopeTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

Create `app/Services/FeatureFlags/OverrideScope.php`:

```php
<?php

namespace App\Services\FeatureFlags;

use InvalidArgumentException;

final class OverrideScope
{
    private function __construct(
        public readonly ?string $professionalId,
        public readonly ?string $brandId,
    ) {
        if ($professionalId === null && $brandId === null) {
            throw new InvalidArgumentException('OverrideScope requires either professionalId or brandId');
        }
        if ($professionalId === '' || $brandId === '') {
            throw new InvalidArgumentException('OverrideScope id must not be empty string');
        }
    }

    public static function forProfessional(string $professionalId): self
    {
        return new self(professionalId: $professionalId, brandId: null);
    }

    public static function forBrand(string $brandId): self
    {
        return new self(professionalId: null, brandId: $brandId);
    }
}
```

- [ ] **Step 4: Run — expect pass**

```bash
php artisan test --compact tests/Unit/FeatureFlags/OverrideScopeTest.php
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/FeatureFlags/OverrideScope.php tests/Unit/FeatureFlags/OverrideScopeTest.php
git commit -m "feat(feature-flags): OverrideScope value object"
```

---

## Task 4: FeatureFlagService — resolver core (no cache yet)

This task implements precedence + rollout as a pure DB-backed resolver. Caching comes in Task 5 so we can test the resolution logic in isolation first.

**Files:**
- Create: `app/Services/FeatureFlags/FeatureFlagService.php`
- Create: `tests/Feature/FeatureFlags/ResolverPrecedenceTest.php`
- Create: `tests/Feature/FeatureFlags/RolloutDeterminismTest.php`

- [ ] **Step 1: Write precedence test**

Create `tests/Feature/FeatureFlags/ResolverPrecedenceTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional;
use App\Services\FeatureFlags\FeatureFlagService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(FeatureFlagService::class);
    $this->pro = Professional::factory()->create();
    $this->brand = BrandProfile::factory()->for($this->pro)->create();
});

it('returns global default when no override and no rollout', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => true, 'rollout_percent' => 0]);
    expect($this->service->enabled('test_flag', $this->pro))->toBeTrue();

    FeatureFlag::where('key', 'test_flag')->update(['default_enabled' => false]);
    expect($this->service->enabled('test_flag', $this->pro))->toBeFalse();
});

it('falls back to config when flag row missing', function () {
    config(['partna.features.video_uploads' => true]);
    expect($this->service->enabled('video_uploads', $this->pro))->toBeTrue();

    config(['partna.features.video_uploads' => false]);
    expect($this->service->enabled('video_uploads', $this->pro))->toBeFalse();
});

it('pro override wins over default', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlagOverride::create([
        'flag_key' => 'test_flag',
        'professional_id' => $this->pro->id,
        'enabled' => true,
    ]);
    expect($this->service->enabled('test_flag', $this->pro))->toBeTrue();
});

it('brand override wins over pro override', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlagOverride::create([
        'flag_key' => 'test_flag',
        'professional_id' => $this->pro->id,
        'enabled' => true,
    ]);
    FeatureFlagOverride::create([
        'flag_key' => 'test_flag',
        'brand_id' => $this->brand->id,
        'enabled' => false,
    ]);
    expect($this->service->enabled('test_flag', $this->pro, $this->brand))->toBeFalse();
});

it('expired overrides are ignored', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlagOverride::create([
        'flag_key' => 'test_flag',
        'professional_id' => $this->pro->id,
        'enabled' => true,
        'expires_at' => now()->subMinute(),
    ]);
    expect($this->service->enabled('test_flag', $this->pro))->toBeFalse();
});

it('null professional resolves to global default only', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => true, 'rollout_percent' => 0]);
    expect($this->service->enabled('test_flag'))->toBeTrue();
});
```

- [ ] **Step 2: Write rollout determinism test**

Create `tests/Feature/FeatureFlags/RolloutDeterminismTest.php`:

```php
<?php

use App\Models\Core\FeatureFlag;
use App\Models\Core\Professional;
use App\Services\FeatureFlags\FeatureFlagService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('same pro+key always buckets identically', function () {
    FeatureFlag::create(['key' => 'ramp_flag', 'default_enabled' => false, 'rollout_percent' => 50]);
    $pro = Professional::factory()->create();
    $service = app(FeatureFlagService::class);

    $first = $service->enabled('ramp_flag', $pro);
    for ($i = 0; $i < 10; $i++) {
        expect($service->enabled('ramp_flag', $pro))->toBe($first);
    }
});

it('ramping percent up never removes a tenant who was previously enabled', function () {
    FeatureFlag::create(['key' => 'ramp_flag', 'default_enabled' => false, 'rollout_percent' => 25]);
    $service = app(FeatureFlagService::class);
    $pros = Professional::factory()->count(200)->create();

    $enabledAt25 = $pros->filter(fn ($p) => $service->enabled('ramp_flag', $p));

    FeatureFlag::where('key', 'ramp_flag')->update(['rollout_percent' => 50]);
    $service->flush(); // clear in-memory state if any
    $enabledAt50 = $pros->filter(fn ($p) => $service->enabled('ramp_flag', $p));

    foreach ($enabledAt25 as $pro) {
        expect($enabledAt50->contains('id', $pro->id))->toBeTrue();
    }
});

it('distributes roughly evenly at 50 percent', function () {
    FeatureFlag::create(['key' => 'dist_flag', 'default_enabled' => false, 'rollout_percent' => 50]);
    $service = app(FeatureFlagService::class);
    $pros = Professional::factory()->count(1000)->create();

    $enabledCount = $pros->filter(fn ($p) => $service->enabled('dist_flag', $p))->count();
    expect($enabledCount)->toBeBetween(450, 550);
});
```

- [ ] **Step 3: Run — expect failure**

```bash
php artisan test --compact tests/Feature/FeatureFlags/
```

Expected: FAIL — `FeatureFlagService` class not found.

- [ ] **Step 4: Implement service**

Create `app/Services/FeatureFlags/FeatureFlagService.php`:

```php
<?php

namespace App\Services\FeatureFlags;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FeatureFlagService
{
    public function enabled(string $key, ?Professional $pro = null, ?Brand $brand = null): bool
    {
        // 1. Brand override (if brand passed)
        if ($brand !== null) {
            $brandOverride = $this->lookupOverride($key, brandId: $brand->id);
            if ($brandOverride !== null) {
                return $brandOverride;
            }
        }

        // 2. Professional override
        if ($pro !== null) {
            $proOverride = $this->lookupOverride($key, professionalId: $pro->id);
            if ($proOverride !== null) {
                return $proOverride;
            }
        }

        $flag = FeatureFlag::find($key);

        // 3. Percentage rollout (requires pro)
        if ($flag !== null && $pro !== null && $flag->rollout_percent > 0) {
            $bucket = crc32($key . $pro->id) % 100;
            if ($bucket < $flag->rollout_percent) {
                return true;
            }
        }

        // 4. Global default → config fallback
        if ($flag !== null) {
            return $flag->default_enabled;
        }

        return (bool) config('partna.features.' . $key, false);
    }

    /**
     * Reset any in-memory state. Caching layer will override this in Task 5.
     */
    public function flush(): void
    {
        // No-op in DB-only resolver. Cache layer will hook here.
    }

    private function lookupOverride(string $key, ?string $professionalId = null, ?string $brandId = null): ?bool
    {
        $query = FeatureFlagOverride::where('flag_key', $key)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        } else {
            $query->where('professional_id', $professionalId)->whereNull('brand_id');
        }

        $row = $query->first();
        return $row?->enabled;
    }
}
```

- [ ] **Step 5: Run — expect pass**

```bash
php artisan test --compact tests/Feature/FeatureFlags/
```

Expected: PASS, 9 tests.

If the rollout distribution test is flaky (it tests probabilistic behavior over 1000 random UUIDs), it should reliably land in 450–550 — if it doesn't, the crc32 hash is not distributing well, investigate before commenting out.

- [ ] **Step 6: Commit**

```bash
git add app/Services/FeatureFlags/FeatureFlagService.php tests/Feature/FeatureFlags/
git commit -m "feat(feature-flags): resolver with precedence + percentage rollout"
```

---

## Task 5: Add Redis caching to the resolver

**Files:**
- Modify: `app/Services/FeatureFlags/FeatureFlagService.php`
- Create: `tests/Feature/FeatureFlags/CacheInvalidationTest.php`
- Create: `tests/Unit/FeatureFlags/RedisDownFallbackTest.php`

- [ ] **Step 1: Write cache invalidation test**

Create `tests/Feature/FeatureFlags/CacheInvalidationTest.php`:

```php
<?php

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\FeatureFlags\OverrideScope;
use Illuminate\Support\Facades\Cache;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->service = app(FeatureFlagService::class);
    $this->pro = Professional::factory()->create();
    FeatureFlag::create(['key' => 'cache_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
});

it('cache hit returns same value as DB', function () {
    FeatureFlagOverride::create([
        'flag_key' => 'cache_flag',
        'professional_id' => $this->pro->id,
        'enabled' => true,
    ]);

    expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
    // Mutate DB without going through service — cache should still return true
    FeatureFlagOverride::where('flag_key', 'cache_flag')->update(['enabled' => false]);
    expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
});

it('setOverride invalidates the pro cache key', function () {
    expect($this->service->enabled('cache_flag', $this->pro))->toBeFalse();
    $this->service->setOverride('cache_flag', OverrideScope::forProfessional($this->pro->id), true, null, null);
    expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
});

it('clearOverride invalidates the pro cache key', function () {
    $this->service->setOverride('cache_flag', OverrideScope::forProfessional($this->pro->id), true, null, null);
    expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
    $this->service->clearOverride('cache_flag', OverrideScope::forProfessional($this->pro->id));
    expect($this->service->enabled('cache_flag', $this->pro))->toBeFalse();
});
```

- [ ] **Step 2: Write Redis-down fallback test**

Create `tests/Unit/FeatureFlags/RedisDownFallbackTest.php`:

```php
<?php

use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

it('falls back to DB when cache throws and logs a warning', function () {
    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    Log::spy();

    $service = app(FeatureFlagService::class);
    config(['partna.features.fallback_flag' => true]);

    expect($service->enabled('fallback_flag'))->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'cache_unavailable'));
});
```

- [ ] **Step 3: Run — expect failure**

```bash
php artisan test --compact tests/Feature/FeatureFlags/CacheInvalidationTest.php tests/Unit/FeatureFlags/RedisDownFallbackTest.php
```

Expected: FAIL — caching not wired, `setOverride`/`clearOverride` don't exist.

- [ ] **Step 4: Replace `FeatureFlagService` with cached version**

> **Audit fixes applied to this step:**
> - **P1 CACHE-1** — Uses `CacheLockService::rememberLocked` (single-flight + SWR) instead of `Cache::remember`. Matches the commerce read-cache pattern that CLAUDE.md / the spec calls out as canonical.
> - **P2 CACHE-2** — TTL is jittered ±20% per write via `random_int` to prevent synchronized expiry storms.
> - **P2 LIFE-2** — Precedence logic is extracted into a single `resolveFromArrays()` so the cache path and the degraded DB path share one decision tree; they cannot drift.
> - **P2 LIFE-3** — `feature_flags.cache_unavailable` log includes `professional_id`, `brand_id`, and `request_id` so Nightwatch can correlate during an incident.
> - **P2 FF-2 (original audit)** — Overrides for soft-deleted professionals/brands are filtered out at load time. Soft-deleted accounts no longer influence resolution.
> - **P2 FF-3 (original audit)** — Adds the `allFor()` method that the spec promised but the prior plan omitted.

Replace `app/Services/FeatureFlags/FeatureFlagService.php` entirely with:

```php
<?php

namespace App\Services\FeatureFlags;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional;
use App\Services\Cache\CacheLockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeatureFlagService
{
    private const BASE_TTL_SECONDS = 300;
    private const TTL_JITTER_SECONDS = 60;
    private const REGISTRY_KEY = 'ff:registry';

    public function __construct(private CacheLockService $cacheLock)
    {
    }

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
     * Matches the API surface promised by the spec.
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
            // Degrade to per-key DB resolution rather than empty array.
            $result = [];
            foreach (array_keys($registry ?? []) as $k) {
                $result[$k] = $this->resolveFromDb($k, $pro, $brand);
            }
            return $result;
        }

        $result = [];
        foreach (array_keys($registry) as $k) {
            $result[$k] = $this->resolveFromArrays($k, $registry, $proOverrides, $brandOverrides, $pro);
        }
        return $result;
    }

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
            $this->forgetBrand($scope->brandId);
        } else {
            FeatureFlagOverride::updateOrCreate(
                ['flag_key' => $key, 'professional_id' => $scope->professionalId, 'brand_id' => null],
                $attrs + ['professional_id' => $scope->professionalId, 'brand_id' => null],
            );
            $this->forgetPro($scope->professionalId);
        }
    }

    public function clearOverride(string $key, OverrideScope $scope): void
    {
        $query = FeatureFlagOverride::where('flag_key', $key);
        if ($scope->brandId !== null) {
            $query->where('brand_id', $scope->brandId)->delete();
            $this->forgetBrand($scope->brandId);
        } else {
            $query->where('professional_id', $scope->professionalId)->whereNull('brand_id')->delete();
            $this->forgetPro($scope->professionalId);
        }
    }

    public function flushRegistry(): void
    {
        Cache::forget(self::REGISTRY_KEY);
    }

    public function flush(): void
    {
        $this->flushRegistry();
    }

    public function forgetPro(string $proId): void
    {
        Cache::forget("ff:pro:{$proId}");
    }

    public function forgetBrand(string $brandId): void
    {
        Cache::forget("ff:brand:{$brandId}");
    }

    /**
     * @return array{0: array<string, array{default_enabled: bool, rollout_percent: int}>, 1: array<string, bool>, 2: array<string, bool>}
     */
    private function loadAll(?Professional $pro, ?BrandProfile $brand): array
    {
        $registry = $this->loadRegistry();
        $proOverrides = $pro !== null ? $this->loadProOverrides($pro->id) : [];
        $brandOverrides = $brand !== null ? $this->loadBrandOverrides($brand->id) : [];
        return [$registry, $proOverrides, $brandOverrides];
    }

    /**
     * Single decision tree shared by both cached + degraded-DB paths.
     */
    private function resolveFromArrays(
        string $key,
        array $registry,
        array $proOverrides,
        array $brandOverrides,
        ?Professional $pro,
    ): bool {
        if (isset($brandOverrides[$key])) {
            return $brandOverrides[$key];
        }
        if (isset($proOverrides[$key])) {
            return $proOverrides[$key];
        }
        $flag = $registry[$key] ?? null;
        if ($flag !== null && $pro !== null && $flag['rollout_percent'] > 0) {
            if ((crc32($key . $pro->id) % 100) < $flag['rollout_percent']) {
                return true;
            }
        }
        if ($flag !== null) {
            return $flag['default_enabled'];
        }
        return (bool) config('partna.features.' . $key, false);
    }

    private function jitteredTtl(): int
    {
        return self::BASE_TTL_SECONDS + random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS);
    }

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

    private function loadProOverrides(string $proId): array
    {
        return $this->cacheLock->rememberLocked(
            "ff:pro:{$proId}",
            $this->jitteredTtl(),
            function () use ($proId): array {
                // Filter out overrides for soft-deleted professionals via EXISTS subquery.
                return FeatureFlagOverride::query()
                    ->where('professional_id', $proId)
                    ->whereNull('brand_id')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('core.professionals')
                        ->whereColumn('core.professionals.id', 'core.feature_flag_overrides.professional_id')
                        ->whereNull('core.professionals.deleted_at'))
                    ->get()
                    ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                    ->all();
            },
        );
    }

    private function loadBrandOverrides(string $brandId): array
    {
        return $this->cacheLock->rememberLocked(
            "ff:brand:{$brandId}",
            $this->jitteredTtl(),
            function () use ($brandId): array {
                return FeatureFlagOverride::query()
                    ->where('brand_id', $brandId)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('brand.brand_profiles')
                        ->whereColumn('brand.brand_profiles.id', 'core.feature_flag_overrides.brand_id')
                        ->whereNull('brand.brand_profiles.deleted_at'))
                    ->get()
                    ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                    ->all();
            },
        );
    }

    /**
     * Degraded path used when the cache layer throws.
     * Reads directly from the DB, then delegates to the same decision tree as enabled().
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
```

> **Note on `BrandProfile`:** the model is at `app/Models/Brand/BrandProfile.php` (verify with `ls app/Models/Brand/`). If the class is actually named `Brand` in this codebase, swap the import and parameter type accordingly — but the underlying table is `brand.brand_profiles` regardless.

- [ ] **Step 4b: Write the `allFor()` test**

Create `tests/Feature/FeatureFlags/AllForTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional;
use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Support\Facades\Cache;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->service = app(FeatureFlagService::class);
});

it('returns global defaults map when no pro is passed', function () {
    FeatureFlag::create(['key' => 'a', 'default_enabled' => true, 'rollout_percent' => 0]);
    FeatureFlag::create(['key' => 'b', 'default_enabled' => false, 'rollout_percent' => 0]);

    expect($this->service->allFor())->toBe(['a' => true, 'b' => false]);
});

it('applies pro and brand overrides correctly in the map', function () {
    $pro = Professional::factory()->create();
    $brand = BrandProfile::factory()->for($pro)->create();

    FeatureFlag::create(['key' => 'a', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlag::create(['key' => 'b', 'default_enabled' => true, 'rollout_percent' => 0]);

    FeatureFlagOverride::create(['flag_key' => 'a', 'professional_id' => $pro->id, 'enabled' => true]);
    FeatureFlagOverride::create(['flag_key' => 'b', 'brand_id' => $brand->id, 'enabled' => false]);

    expect($this->service->allFor($pro, $brand))->toBe(['a' => true, 'b' => false]);
});
```

- [ ] **Step 5: Run — expect pass**

```bash
php artisan test --compact tests/Feature/FeatureFlags/ tests/Unit/FeatureFlags/
```

Expected: PASS, all tests including the prior precedence + rollout suite (the resolver still resolves correctly, now with cache fronting).

- [ ] **Step 6: Commit**

```bash
git add app/Services/FeatureFlags/FeatureFlagService.php tests/Feature/FeatureFlags/CacheInvalidationTest.php tests/Unit/FeatureFlags/RedisDownFallbackTest.php
git commit -m "feat(feature-flags): redis caching with push invalidation"
```

---

## Task 6: `feature()` global helper

**Files:**
- Create: `app/helpers.php`
- Modify: `composer.json`
- Create: `tests/Unit/FeatureFlags/FeatureHelperTest.php`

- [ ] **Step 1: Check if `app/helpers.php` already exists**

```bash
ls app/helpers.php 2>/dev/null && echo "EXISTS" || echo "MISSING"
```

If EXISTS: skip the composer.json change in Step 3 and append the helper to the file instead of creating it. If MISSING: proceed.

- [ ] **Step 2: Write failing test**

Create `tests/Unit/FeatureFlags/FeatureHelperTest.php`:

```php
<?php

use App\Services\FeatureFlags\FeatureFlagService;

it('feature() helper delegates to FeatureFlagService', function () {
    $mock = $this->mock(FeatureFlagService::class);
    $mock->shouldReceive('enabled')->with('test_helper', null, null)->andReturn(true);
    expect(feature('test_helper'))->toBeTrue();
});
```

- [ ] **Step 3: Implement helper**

Create `app/helpers.php`:

```php
<?php

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional;
use App\Services\FeatureFlags\FeatureFlagService;

if (! function_exists('feature')) {
    function feature(string $key, ?Professional $pro = null, ?Brand $brand = null): bool
    {
        return app(FeatureFlagService::class)->enabled($key, $pro, $brand);
    }
}
```

Modify `composer.json` to autoload the file — find the `"autoload"` block and add `"files"`:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/"
    },
    "files": [
        "app/helpers.php"
    ]
}
```

- [ ] **Step 4: Regenerate autoloader and run test**

```bash
composer dump-autoload -o
php artisan test --compact tests/Unit/FeatureFlags/FeatureHelperTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/helpers.php composer.json composer.lock tests/Unit/FeatureFlags/FeatureHelperTest.php
git commit -m "feat(feature-flags): feature() global helper"
```

---

## Task 7: Policy + registration

**Files:**
- Create: `app/Policies/FeatureFlagPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Read BasePolicy for the staff-detection pattern**

```bash
cat app/Policies/BasePolicy.php
```

Note the staff-role check method used by other policies (e.g. `$this->isStaff($pro)` or similar). Use the same idiom.

- [ ] **Step 2: Implement policy**

Create `app/Policies/FeatureFlagPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Core\FeatureFlag;
use App\Models\Core\Professional;

class FeatureFlagPolicy extends BasePolicy
{
    public function viewAny(Professional $pro): bool
    {
        return $this->isStaff($pro);
    }

    public function view(Professional $pro, FeatureFlag $flag): bool
    {
        return $this->isStaff($pro);
    }

    public function manage(Professional $pro, ?FeatureFlag $flag = null): bool
    {
        return $this->isStaff($pro);
    }
}
```

> If `BasePolicy` doesn't expose `isStaff()`, use whatever staff-check method does exist (grep `app/Policies/` for one — staff routes already have this pattern).

- [ ] **Step 3: Register the policy**

Modify `app/Providers/AppServiceProvider.php` — find the `boot()` method's existing `Gate::policy(...)` block and add:

```php
Gate::policy(FeatureFlag::class, FeatureFlagPolicy::class);
```

Add the import at the top: `use App\Models\Core\FeatureFlag;` and `use App\Policies\FeatureFlagPolicy;`.

- [ ] **Step 4: Verify PolicyCoverageTest passes**

```bash
php artisan test --compact tests/Feature/Security/PolicyCoverageTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/FeatureFlagPolicy.php app/Providers/AppServiceProvider.php
git commit -m "feat(feature-flags): policy + gate registration"
```

---

## Task 8: Form Requests

**Files:**
- Create: `app/Http/Requests/Api/Staff/FeatureFlag/CreateFeatureFlagRequest.php`
- Create: `app/Http/Requests/Api/Staff/FeatureFlag/UpdateFeatureFlagRequest.php`
- Create: `app/Http/Requests/Api/Staff/FeatureFlag/CreateOverrideRequest.php`

- [ ] **Step 1: Check existing Form Request conventions**

```bash
ls app/Http/Requests/Api/Staff/ | head -10
cat $(ls app/Http/Requests/Api/Staff/**/Staff*Request.php 2>/dev/null | head -1)
```

Match the conventions (array vs string rules, custom messages style, namespace).

- [ ] **Step 2: Implement CreateFeatureFlagRequest**

Create `app/Http/Requests/Api/Staff/FeatureFlag/CreateFeatureFlagRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff\FeatureFlag;

use Illuminate\Foundation\Http\FormRequest;

class CreateFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy enforced at controller
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'min:1', 'max:128', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:core.feature_flags,key'],
            'description' => ['nullable', 'string', 'max:500'],
            'default_enabled' => ['required', 'boolean'],
            'rollout_percent' => ['required', 'integer', 'between:0,100'],
        ];
    }
}
```

- [ ] **Step 3: Implement UpdateFeatureFlagRequest**

Create `app/Http/Requests/Api/Staff/FeatureFlag/UpdateFeatureFlagRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff\FeatureFlag;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_enabled' => ['sometimes', 'boolean'],
            'rollout_percent' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
```

- [ ] **Step 4: Implement CreateOverrideRequest**

Create `app/Http/Requests/Api/Staff/FeatureFlag/CreateOverrideRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff\FeatureFlag;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'professional_id' => ['required_without:brand_id', 'nullable', 'uuid', 'exists:core.professionals,id'],
            'brand_id' => ['required_without:professional_id', 'nullable', 'uuid', 'exists:brand.brand_profiles,id'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->filled('professional_id') && $this->filled('brand_id')) {
                $v->errors()->add('scope', 'Provide professional_id or brand_id, not both.');
            }
        });
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Staff/FeatureFlag/
git commit -m "feat(feature-flags): form requests for admin endpoints"
```

---

## Task 9: API Resources

**Files:**
- Create: `app/Http/Resources/FeatureFlagResource.php`
- Create: `app/Http/Resources/FeatureFlagOverrideResource.php`

- [ ] **Step 1: Implement FeatureFlagResource**

Create `app/Http/Resources/FeatureFlagResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FeatureFlagResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'key' => $this->key,
            'description' => $this->description,
            'default_enabled' => (bool) $this->default_enabled,
            'rollout_percent' => (int) $this->rollout_percent,
            'override_count' => $this->whenCounted('overrides'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 2: Implement FeatureFlagOverrideResource**

Create `app/Http/Resources/FeatureFlagOverrideResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FeatureFlagOverrideResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'flag_key' => $this->flag_key,
            'professional_id' => $this->professional_id,
            'brand_id' => $this->brand_id,
            'enabled' => (bool) $this->enabled,
            'reason' => $this->reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/FeatureFlagResource.php app/Http/Resources/FeatureFlagOverrideResource.php
git commit -m "feat(feature-flags): api resources"
```

---

## Task 10: Staff controller

**Files:**
- Create: `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php`
- Create: `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php`

- [ ] **Step 1: Confirm the staff-attribute key**

```bash
rg -n "attributes->get\('partna_staff'\)" app/Http/Controllers/Api/Staff/ | head -3
```

Expected: confirms every existing staff controller uses `$request->attributes->get('partna_staff')` to resolve the authenticated staff actor. **This is the only correct key.** Under Supabase JWT auth, `Auth::user()` and `$request->user()` always return null — using them as fallbacks creates a silent auth bypass (`Gate::forUser(null)` passes every policy check). The controllers below use `partna_staff` directly with a hard 401 if it is missing — no fallbacks.

- [ ] **Step 2: Implement flag controller**

Create `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\FeatureFlag;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Staff\FeatureFlag\CreateFeatureFlagRequest;
use App\Http\Requests\Api\Staff\FeatureFlag\UpdateFeatureFlagRequest;
use App\Http\Resources\FeatureFlagResource;
use App\Models\Core\FeatureFlag;
use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffFeatureFlagController extends Controller
{
    public function __construct(private FeatureFlagService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pro = $this->resolveStaff($request);
        $this->authorizeForUser($pro, 'viewAny', FeatureFlag::class);

        $flags = FeatureFlag::withCount('overrides')->orderBy('key')->get();
        return FeatureFlagResource::collection($flags)->response();
    }

    public function store(CreateFeatureFlagRequest $request): JsonResponse
    {
        $pro = $this->resolveStaff($request);
        $this->authorizeForUser($pro, 'manage', FeatureFlag::class);

        $flag = FeatureFlag::create($request->validated());
        $this->service->flushRegistry();
        return (new FeatureFlagResource($flag))->response()->setStatusCode(201);
    }

    public function update(UpdateFeatureFlagRequest $request, string $key): JsonResponse
    {
        $pro = $this->resolveStaff($request);
        $flag = FeatureFlag::findOrFail($key);
        $this->authorizeForUser($pro, 'manage', $flag);

        $flag->update($request->validated());
        $this->service->flushRegistry();
        return (new FeatureFlagResource($flag))->response();
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $pro = $this->resolveStaff($request);
        $flag = FeatureFlag::findOrFail($key);
        $this->authorizeForUser($pro, 'manage', $flag);

        $flag->delete();
        $this->service->flushRegistry();
        return response()->json(null, 204);
    }

    private function resolveStaff(Request $request)
    {
        $staff = $request->attributes->get('partna_staff');
        abort_if($staff === null, 401, 'Unauthenticated');
        return $staff;
    }
}
```

> **Audit fix (P1 SEC-1):** uses the canonical `partna_staff` attribute key with a hard 401 on missing. **Never** fall back to `$request->user()` — under Supabase JWT it returns null, and `authorizeForUser(null, ...)` silently passes every policy check (`Gate::forUser(null)` is a known Laravel-Gate quirk). Fail closed, always.

- [ ] **Step 3: Implement override controller**

Create `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\FeatureFlag;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Staff\FeatureFlag\CreateOverrideRequest;
use App\Http\Resources\FeatureFlagOverrideResource;
use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\FeatureFlags\OverrideScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffFeatureFlagOverrideController extends Controller
{
    public function __construct(private FeatureFlagService $service)
    {
    }

    public function index(Request $request, string $key): JsonResponse
    {
        $pro = $this->resolveStaff($request);
        $flag = FeatureFlag::findOrFail($key);
        $this->authorizeForUser($pro, 'manage', $flag);

        // Audit fix (P3 SCALE-3): paginate to bound response size as overrides accumulate.
        $overrides = $flag->overrides()->orderBy('created_at', 'desc')->paginate(50);
        return FeatureFlagOverrideResource::collection($overrides)->response();
    }

    public function store(CreateOverrideRequest $request, string $key): JsonResponse
    {
        $pro = $this->resolveStaff($request);
        $flag = FeatureFlag::findOrFail($key);
        $this->authorizeForUser($pro, 'manage', $flag);

        $data = $request->validated();
        $scope = $data['brand_id'] ?? null
            ? OverrideScope::forBrand($data['brand_id'])
            : OverrideScope::forProfessional($data['professional_id']);

        $this->service->setOverride(
            $key,
            $scope,
            (bool) $data['enabled'],
            $data['reason'] ?? null,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $pro->id,
        );

        $created = FeatureFlagOverride::where('flag_key', $key)
            ->when($scope->brandId, fn ($q) => $q->where('brand_id', $scope->brandId))
            ->when($scope->professionalId, fn ($q) => $q->where('professional_id', $scope->professionalId)->whereNull('brand_id'))
            ->first();

        return (new FeatureFlagOverrideResource($created))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pro = $this->resolveStaff($request);
        $override = FeatureFlagOverride::findOrFail($id);
        $flag = FeatureFlag::findOrFail($override->flag_key);
        $this->authorizeForUser($pro, 'manage', $flag);

        $scope = $override->brand_id
            ? OverrideScope::forBrand($override->brand_id)
            : OverrideScope::forProfessional($override->professional_id);

        $this->service->clearOverride($override->flag_key, $scope);
        return response()->json(null, 204);
    }

    private function resolveStaff(Request $request)
    {
        $staff = $request->attributes->get('partna_staff');
        abort_if($staff === null, 401, 'Unauthenticated');
        return $staff;
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/Staff/FeatureFlag/
git commit -m "feat(feature-flags): staff admin controllers"
```

---

## Task 11: Routes

**Files:**
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Read existing route group**

```bash
grep -n "Route::" routes/api/staff.php | head -20
```

Identify which prefix/middleware group new staff routes go in. Add the new routes inside that group at a sensible location (near other admin/system routes, not interleaved with site-management routes).

- [ ] **Step 2: Add routes**

Add inside the existing staff route group in `routes/api/staff.php`:

```php
use App\Http\Controllers\Api\Staff\FeatureFlag\StaffFeatureFlagController;
use App\Http\Controllers\Api\Staff\FeatureFlag\StaffFeatureFlagOverrideController;

// ... inside the staff group:
Route::prefix('feature-flags')->group(function () {
    Route::get('/', [StaffFeatureFlagController::class, 'index']);
    Route::post('/', [StaffFeatureFlagController::class, 'store']);
    Route::patch('{key}', [StaffFeatureFlagController::class, 'update']);
    Route::delete('{key}', [StaffFeatureFlagController::class, 'destroy']);
    Route::get('{key}/overrides', [StaffFeatureFlagOverrideController::class, 'index']);
    Route::post('{key}/overrides', [StaffFeatureFlagOverrideController::class, 'store']);
    Route::delete('overrides/{id}', [StaffFeatureFlagOverrideController::class, 'destroy']);
});
```

- [ ] **Step 3: Verify routes registered**

```bash
php artisan route:list --path=staff/feature-flags
```

Expected: 7 routes listed.

- [ ] **Step 4: Commit**

```bash
git add routes/api/staff.php
git commit -m "feat(feature-flags): staff admin routes"
```

---

## Task 12: Controller tests

**Files:**
- Create: `tests/Feature/FeatureFlags/StaffFeatureFlagsControllerTest.php`

- [ ] **Step 1: Find the staff-auth test helper**

```bash
grep -rn "actingAsStaff\|asStaff\|staffActing" tests/ | head -5
```

Use whichever helper existing staff feature tests use to authenticate as a staff professional. If none, look at how an existing staff controller test sets up `$request->attributes->set('professional', $staff)` or similar.

- [ ] **Step 2: Write the controller test**

Create `tests/Feature/FeatureFlags/StaffFeatureFlagsControllerTest.php`:

```php
<?php

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->staff = Professional::factory()->staff()->create();
    $this->actingAsStaff($this->staff); // adjust to match codebase helper from Step 1
});

it('lists flags', function () {
    FeatureFlag::create(['key' => 'a', 'default_enabled' => true, 'rollout_percent' => 0]);
    FeatureFlag::create(['key' => 'b', 'default_enabled' => false, 'rollout_percent' => 25]);

    $this->getJson('/api/staff/feature-flags')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

it('creates a flag', function () {
    $this->postJson('/api/staff/feature-flags', [
        'key' => 'video_uploads',
        'description' => 'gate video uploads',
        'default_enabled' => false,
        'rollout_percent' => 0,
    ])->assertCreated();

    expect(FeatureFlag::find('video_uploads'))->not->toBeNull();
});

it('rejects invalid keys', function () {
    $this->postJson('/api/staff/feature-flags', [
        'key' => 'Video-Uploads', // invalid: uppercase + hyphen
        'default_enabled' => false,
        'rollout_percent' => 0,
    ])->assertUnprocessable();
});

it('updates a flag', function () {
    FeatureFlag::create(['key' => 'x', 'default_enabled' => false, 'rollout_percent' => 0]);
    $this->patchJson('/api/staff/feature-flags/x', ['rollout_percent' => 50])
        ->assertSuccessful();
    expect(FeatureFlag::find('x')->rollout_percent)->toBe(50);
});

it('deletes a flag', function () {
    FeatureFlag::create(['key' => 'x', 'default_enabled' => false, 'rollout_percent' => 0]);
    $this->deleteJson('/api/staff/feature-flags/x')->assertNoContent();
    expect(FeatureFlag::find('x'))->toBeNull();
});

it('creates a pro override', function () {
    FeatureFlag::create(['key' => 'x', 'default_enabled' => false, 'rollout_percent' => 0]);
    $target = Professional::factory()->create();

    $this->postJson('/api/staff/feature-flags/x/overrides', [
        'professional_id' => $target->id,
        'enabled' => true,
    ])->assertCreated();

    expect(FeatureFlagOverride::where('flag_key', 'x')->where('professional_id', $target->id)->exists())->toBeTrue();
});

it('rejects override with both scopes', function () {
    FeatureFlag::create(['key' => 'x', 'default_enabled' => false, 'rollout_percent' => 0]);
    $pro = Professional::factory()->create();

    $this->postJson('/api/staff/feature-flags/x/overrides', [
        'professional_id' => $pro->id,
        'brand_id' => '00000000-0000-0000-0000-000000000000',
        'enabled' => true,
    ])->assertUnprocessable();
});

it('deletes an override', function () {
    FeatureFlag::create(['key' => 'x', 'default_enabled' => false, 'rollout_percent' => 0]);
    $pro = Professional::factory()->create();
    $override = FeatureFlagOverride::create([
        'flag_key' => 'x', 'professional_id' => $pro->id, 'enabled' => true,
    ]);

    $this->deleteJson("/api/staff/feature-flags/overrides/{$override->id}")
        ->assertNoContent();
    expect(FeatureFlagOverride::find($override->id))->toBeNull();
});

it('forbids non-staff', function () {
    $this->actingAsStaff(Professional::factory()->create()); // non-staff
    $this->getJson('/api/staff/feature-flags')->assertForbidden();
});
```

> If `Professional::factory()->staff()` doesn't exist, look at how other staff tests construct a staff user and copy that. Same for `actingAsStaff`.

- [ ] **Step 3: Run — fix wiring as needed**

```bash
php artisan test --compact tests/Feature/FeatureFlags/StaffFeatureFlagsControllerTest.php
```

Expected: PASS. If something fails because of staff-auth wiring, fix it by matching the existing convention; don't bypass the authorization layer.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/FeatureFlags/StaffFeatureFlagsControllerTest.php
git commit -m "test(feature-flags): staff controller coverage"
```

---

## Task 13: Prune-expired artisan command

**Files:**
- Create: `app/Console/Commands/FeatureFlags/PruneExpiredOverridesCommand.php`
- Create: `tests/Feature/FeatureFlags/PruneExpiredCommandTest.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/FeatureFlags/PruneExpiredCommandTest.php`:

```php
<?php

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('deletes expired overrides, keeps active ones', function () {
    // Audit fix (P2 FF-4 from targeted run): use real Brand/Professional factories,
    // not synthetic UUIDs — Postgres enforces FK constraints and SQLite may too.
    $pro = Professional::factory()->create();
    $brand = \App\Models\Core\Professional\BrandProfile::factory()->for($pro)->create();
    FeatureFlag::create(['key' => 'x', 'default_enabled' => false, 'rollout_percent' => 0]);

    $expired = FeatureFlagOverride::create([
        'flag_key' => 'x', 'professional_id' => $pro->id, 'enabled' => true,
        'expires_at' => now()->subMinute(),
    ]);
    $active = FeatureFlagOverride::create([
        'flag_key' => 'x', 'brand_id' => $brand->id,
        'enabled' => true, 'expires_at' => now()->addHour(),
    ]);
    $permanent = FeatureFlagOverride::create([
        'flag_key' => 'x', 'professional_id' => Professional::factory()->create()->id,
        'enabled' => false, 'expires_at' => null,
    ]);

    $this->artisan('feature-flags:prune-expired')->assertExitCode(0);

    expect(FeatureFlagOverride::find($expired->id))->toBeNull();
    expect(FeatureFlagOverride::find($active->id))->not->toBeNull();
    expect(FeatureFlagOverride::find($permanent->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --compact tests/Feature/FeatureFlags/PruneExpiredCommandTest.php
```

Expected: FAIL — command not registered.

- [ ] **Step 3: Implement command**

Create `app/Console/Commands/FeatureFlags/PruneExpiredOverridesCommand.php`:

```php
<?php

namespace App\Console\Commands\FeatureFlags;

use App\Models\Core\FeatureFlagOverride;
use Illuminate\Console\Command;

class PruneExpiredOverridesCommand extends Command
{
    protected $signature = 'feature-flags:prune-expired';
    protected $description = 'Hard-delete feature flag overrides whose expires_at is in the past';

    public function handle(): int
    {
        $deleted = FeatureFlagOverride::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Pruned {$deleted} expired feature flag override(s).");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule the command**

In `routes/console.php`, add:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('feature-flags:prune-expired')->daily()->onOneServer();
```

- [ ] **Step 5: Run — expect pass**

```bash
php artisan test --compact tests/Feature/FeatureFlags/PruneExpiredCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/FeatureFlags/PruneExpiredOverridesCommand.php routes/console.php tests/Feature/FeatureFlags/PruneExpiredCommandTest.php
git commit -m "feat(feature-flags): prune-expired command + daily schedule"
```

---

## Task 14: Delete dropped-feature flag guards

These callsites guard Square, Fresha, and smart-booking — all of which are dropped per `project_booking_dropped.md`. Delete the `if (! config('partna.features.X')) { return ... }` block entirely (and any imports / dead code that becomes unreachable).

**Files to modify (delete the flag check block in each):**

- `app/Observers/Core/ServiceObserver.php:179` — `square_sync` guard
- `app/Observers/Core/ServiceObserver.php:197` — `fresha_sync` guard
- `app/Jobs/Square/SyncSquareCatalogDeltaJob.php:36` — `square_sync` guard
- `app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php:36` — `fresha_sync` guard
- `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php:20` — `square_sync` guard
- `app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php:20` — `fresha_sync` guard
- `app/Services/Professional/SectionVisibilityService.php:117` — `smart_booking` toggle
- `app/Services/Professional/SectionVisibilityService.php:451` — `smart_booking` check
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php:126` — `smart_booking` `Rule::in` ternary
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSiteController.php:54` — `smart_booking` `$allowedModes` ternary
- `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php:140` — `smart_booking` `Rule::in` ternary

- [ ] **Step 1: For each callsite, decide deletion strategy**

For boolean kill-switches (the first 6 entries): delete the `if (! config(...))` block — let the code path run unconditionally. The booking memo says Square/Fresha are dropped, but these are sync jobs/webhook handlers; deleting the *flag* is the goal, not deleting the entire job file (that's a separate cleanup). Sonnet should NOT delete the surrounding job — only the flag guard inside it.

For `smart_booking` ternaries (last 5 entries): the flag is currently `false` in prod (booking is dropped), so the "manual" branch is the live one. Replace the ternary with the manual-only value.

Example before/after for `ProfessionalSiteController.php:54`:

```php
// BEFORE:
$allowedModes = config('partna.features.smart_booking') ? ['manual', 'smart'] : ['manual'];

// AFTER:
$allowedModes = ['manual'];
```

Example before/after for `SyncSquareCatalogDeltaJob.php:36`:

```php
// BEFORE:
public function handle(): void
{
    if (! (bool) config('partna.features.square_sync', false)) {
        return;
    }
    // ... rest of handle
}

// AFTER:
public function handle(): void
{
    // ... rest of handle (without the early return)
}
```

- [ ] **Step 2: Apply the deletions**

For each file, edit out the flag check. Use `rg -n "config\('partna\.features\." app/` after to confirm only `captcha` remains in `app/Http/Middleware/VerifyTurnstileCaptcha.php`.

- [ ] **Step 3: Verify nothing else broke**

```bash
composer test
```

Expected: PASS. If any test previously relied on the flag being checked, update the test to match the new unconditional behavior — don't restore the flag.

- [ ] **Step 4: Commit**

```bash
git add -u app/
git commit -m "refactor: remove flag guards for dropped Square/Fresha/booking features"
```

---

## Task 15: Wire `video_uploads` as the first real per-tenant flag

**Files:**
- Modify: whichever controller currently handles video upload entry (find it)

- [ ] **Step 1: Find the video upload entry point**

```bash
rg -n "video" app/Http/Controllers/ --type-add 'php:*.php' -t php | rg -i "upload|store|create" | head -10
```

Expected: identify the controller method that initiates a video upload (likely under `Controllers/Api/Professional/`).

- [ ] **Step 2: Add a flag gate at the entry**

In the identified controller, at the start of the upload action:

```php
if (! feature('video_uploads', $pro)) {
    return $this->error('Video uploads are not enabled for your account.', 403);
}
```

Match the existing error-response idiom in that controller (some use `$this->error()`, some return JSON directly). Use whichever pattern the surrounding code uses.

- [ ] **Step 3: Register the flag in dev DB**

```bash
# Via tinker, against dev:
php artisan tinker
> \App\Models\Core\FeatureFlag::create(['key' => 'video_uploads', 'description' => 'Allow professionals to upload video content', 'default_enabled' => false, 'rollout_percent' => 0]);
```

- [ ] **Step 4: Add or update test**

Find the existing test for the video upload endpoint (if any) and add a test that confirms 403 when the flag is off, success when an override turns it on. If no test exists, create one — the gate is meaningless without coverage.

- [ ] **Step 5: Run focused test**

```bash
php artisan test --compact --filter=video
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -u
git commit -m "feat(video): gate uploads behind video_uploads feature flag"
```

---

## Task 16: Final verification + push to prod

- [ ] **Step 1: Full test suite**

```bash
composer test
```

Expected: PASS. Investigate any failure before proceeding — do NOT push to prod with red tests.

- [ ] **Step 2: Pint**

```bash
vendor/bin/pint --dirty
```

If pint changed anything, commit it:

```bash
git add -u
git commit -m "style: pint formatting"
```

- [ ] **Step 3: Verify final callsite state**

```bash
rg -n "config\('partna\.features\." app/
```

Expected output: ONLY the captcha line in `VerifyTurnstileCaptcha.php`. Everything else should be using `feature(...)` or have been deleted.

- [ ] **Step 4: Push migration to prod Supabase**

```bash
supabase link --project-ref edplucmvkcnokyygxqsb
supabase db push --dry-run
```

Show the dry-run output to the user. **Wait for explicit confirmation before running `supabase db push`.** Per CLAUDE.md, prod pushes require an explicit OK.

```bash
supabase db push
```

- [ ] **Step 5: Smoke test on dev**

After deploying app code to dev:

```bash
# Create a flag
curl -X POST https://dev-api.partna.au/api/staff/feature-flags \
  -H "Authorization: Bearer <staff_jwt>" \
  -H "Content-Type: application/json" \
  -d '{"key":"smoke_test","default_enabled":false,"rollout_percent":0}'

# Create an override for own pro account
curl -X POST https://dev-api.partna.au/api/staff/feature-flags/smoke_test/overrides \
  -H "Authorization: Bearer <staff_jwt>" \
  -H "Content-Type: application/json" \
  -d '{"professional_id":"<your_pro_id>","enabled":true}'

# Verify via tinker on dev: feature('smoke_test', $pro) === true

# Clean up
curl -X DELETE https://dev-api.partna.au/api/staff/feature-flags/smoke_test
```

- [ ] **Step 6: Final summary commit (if anything still uncommitted)**

```bash
git status
```

Expected: clean. If not, commit any final touchups.

---

## Spec coverage check

| Spec section | Covered by task |
|---|---|
| Resolution model (precedence) | Task 4, 5 |
| Storage schema | Task 1 |
| Resolver service API | Task 4, 5 |
| Caching | Task 5 |
| `OverrideScope` | Task 3 |
| `feature()` helper | Task 6 |
| Admin endpoints (7 routes) | Task 8, 9, 10, 11 |
| Authorization (policy) | Task 7 |
| All tests from spec | Tasks 4, 5, 12, 13 |
| Callsite cleanup (delete 10, wire 1) | Tasks 14, 15 |
| Prune-expired command | Task 13 |
| Rollout plan (dev → prod) | Task 16 |
