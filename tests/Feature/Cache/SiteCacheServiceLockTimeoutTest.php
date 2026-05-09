<?php

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mockery as M;

beforeEach(function () {
    Cache::flush();
    $this->service = new SiteCacheService(new CacheLockService);
});

afterEach(function () {
    M::close();
});

// Helpers ─────────────────────────────────────────────────────────────────────

/**
 * Minimal payload array that passes the cache healing checks in getPublicSitePayload
 * (must contain a 'services' key so the backward-compat branch returns early).
 *
 * @return array<string, mixed>
 */
function minimalCachedPayload(): array
{
    return [
        'published' => true,
        'services' => [],
        'links' => [],
        'sections' => [],
        'blocks' => [],
        'site' => null,
        'professional' => null,
        'theme' => null,
        'legal' => null,
        'store' => null,
        'selected_products' => [],
        'default_commission_rate' => 15.0,
        'max_featured_products' => 12,
        'checkout_mode' => 'shopify',
    ];
}

// Tests ───────────────────────────────────────────────────────────────────────

it('returns null on lock timeout when MISS_SENTINEL is already cached', function () {
    $subdomain = 'test-sentinel';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    // Initial miss → attempt lock → timeout → re-check returns sentinel.
    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, '__MISS__');
    Cache::shouldReceive('lock')->with('site:fill:'.$subdomain, 10)->once()->andReturn($lock);

    $result = $this->service->getPublicSitePayload($subdomain);

    expect($result)->toBeNull();
});

it('returns cached payload on lock timeout when cache fills during the wait', function () {
    $subdomain = 'test-warm';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);
    $payload = minimalCachedPayload();

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    // Initial miss → attempt lock → timeout → re-check finds the warm payload.
    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, $payload);
    Cache::shouldReceive('lock')->with('site:fill:'.$subdomain, 10)->once()->andReturn($lock);

    $result = $this->service->getPublicSitePayload($subdomain);

    expect($result)->toBe($payload);
});

it('falls through to compute on lock timeout when cache is still empty (CACHE-4 fix)', function () {
    // Verifies the fix: rather than returning null immediately when the lock times
    // out and the cache re-check is still empty, the service now calls
    // buildPayloadFromDb() as a last resort — matching CacheLockService::rememberLocked.
    //
    // We subclass SiteCacheService to spy on buildPayloadFromDb (which would otherwise
    // require a real pgsql DB). The spy returns a valid payload so we can assert the
    // result was NOT null, proving the code did not short-circuit before reaching it.
    $subdomain = 'test-fallback';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    // Both reads return null: initial miss and re-check after timeout.
    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('site:fill:'.$subdomain, 10)->once()->andReturn($lock);

    $computeReached = false;
    $fakePayload = minimalCachedPayload();

    // Anonymous subclass stubs buildPayloadFromDb so the test does not need a real DB.
    $service = new class(new CacheLockService, $fakePayload, $computeReached) extends SiteCacheService
    {
        public function __construct(
            CacheLockService $lock,
            private array $fakePayload,
            public bool &$computeReached,
        ) {
            parent::__construct($lock);
        }

        protected function buildPayloadFromDb(string $subdomain, string $key): ?array
        {
            $this->computeReached = true;

            return $this->fakePayload;
        }
    };

    $result = $service->getPublicSitePayload($subdomain);

    expect($computeReached)->toBeTrue()
        ->and($result)->toBe($fakePayload);
});
