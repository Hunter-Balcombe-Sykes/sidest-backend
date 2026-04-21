# Launch Feature Gates: Disable Smart Booking + POS Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Disable all smart-booking functionality (POS-integrated booking, analytics, public booking) and Square/Fresha catalog sync for launch, while preserving manual service CRUD and the link-redirect booking pattern. All code stays live and reversible via env flags.

**Architecture:** Three env-driven feature flags in `config/sidest.php`. A new `FeatureGate` middleware gates HTTP routes. Non-HTTP entry points (observer, jobs, webhooks, request validation, section visibility) read the flags directly and early-return. No code is deleted or commented out.

**Tech Stack:** Laravel 12, PHP 8.2, Pest 4 for tests, env-var feature-flag pattern already in use (`SIDEST_VIDEO_UPLOADS_ENABLED` is precedent).

## Testing Conventions (read before writing tests)

This codebase does NOT use Eloquent factories (only `UserFactory` exists). Tests that need DB data use one of two patterns:

**Pattern A — Service/validator unit tests with no DB:**
Test validators directly (see `tests/Feature/Site/LinkBlockSocialValidationTest.php`). Call jobs with mocked sync services. No DB setup needed.

**Pattern B — SQLite in-memory with manual schema:**
See `tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.php`. A per-feature test-case helper class `::boot()`s SQLite in-memory, attaches schemas (`core`, `site`, `commerce`, etc. as separate in-memory DBs), and creates the tables needed. Test helper functions like `makeProfessional()` insert via `DB::connection('pgsql')->table('core.professionals')->insert(...)`. Models are fetched via `Model::query()->where('id', $id)->first()` after raw insert.

**Pattern C — Route middleware inspection:**
For route-level gate tests, inspect the registered route's middleware list via `Route::getRoutes()->getByAction(...)` rather than hitting the endpoint. Lighter than full HTTP test, and sufficient because the gate middleware's *behaviour* is verified once in the middleware test (Task 2).

Each test task below specifies which pattern applies.

## Scope

**In scope (gated off at launch):**
- All `/booking/*` routes (professional + public + analytics)
- All `/square/*` and `/fresha/*` routes
- Square and Fresha webhook handlers
- `ServiceObserver` dispatches of `PushServiceToSquareJob` / `PushServiceToFreshaJob`
- `PushService*Job` and `Sync*CatalogDeltaJob` handle methods
- `booking_mode = 'smart'` selection in `UpdateSiteRequest` and `updateBookingSettings`
- `SectionVisibilityService::checkBookingRequirements` integration check

**Out of scope (stays active):**
- Manual service CRUD (`ProfessionalServiceController`, `ProfessionalServiceCategoryController`, staff equivalents)
- `booking_mode = 'manual'` + `manual_booking_url` redirect link pattern
- Booking section publishing when a redirect link is set
- Shopify integration (separate product axis — brand catalog)
- Hydrogen storefront services read
- Cache/visibility side-effects on service save (non-sync parts of `ServiceObserver`)

## File Structure

**New files:**
- `app/Http/Middleware/FeatureGate.php` — generic env-flag gate, returns 503 when the named flag is false

**Modified files:**
- `config/sidest.php` — add three feature flag keys
- `.env.example` — document the three env vars
- `bootstrap/app.php` — register `feature` middleware alias
- `routes/api.php` — apply `feature:smart_booking` to public booking routes
- `routes/api/professional.php` — apply `feature:*` to `/booking`, `/square`, `/fresha` route groups
- `app/Observers/Core/ServiceObserver.php` — gate sync dispatches on flags
- `app/Jobs/Square/PushServiceToSquareJob.php` — belt-and-suspenders early return
- `app/Jobs/Square/SyncSquareCatalogDeltaJob.php` — belt-and-suspenders early return
- `app/Jobs/Fresha/PushServiceToFreshaJob.php` — belt-and-suspenders early return
- `app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php` — belt-and-suspenders early return
- `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php` — return 200 without dispatch when off
- `app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php` — return 200 without dispatch when off
- `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` — reject `booking_mode=smart` when off
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php` — reject `booking_mode=smart` when off
- `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSiteController.php` — reject `booking_mode=smart` in `updateBookingSettings`
- `app/Services/Professional/SectionVisibilityService.php` — allow booking section when flag off only if `manual_booking_url` is set

**New test files:**
- `tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php`
- `tests/Feature/FeatureFlags/BookingRoutesGatedTest.php`
- `tests/Feature/FeatureFlags/PosSyncRoutesGatedTest.php`
- `tests/Feature/FeatureFlags/WebhooksGatedTest.php`
- `tests/Feature/FeatureFlags/ServiceObserverSyncGatedTest.php`
- `tests/Feature/FeatureFlags/SyncJobsGatedTest.php`
- `tests/Feature/FeatureFlags/BookingModeSmartRejectedTest.php`
- `tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php`

---

## Task 1: Add Feature Flags to Config and .env.example

**Files:**
- Modify: `config/sidest.php` (append to the bottom of the return array, before the closing `];`)
- Modify: `.env.example`
- Test: `tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php` (config assertion piece)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php`:

```php
<?php

use function Pest\Laravel\get;

it('exposes three launch feature flags via config', function () {
    expect(config('sidest.features'))
        ->toBeArray()
        ->toHaveKeys(['smart_booking', 'square_sync', 'fresha_sync']);
});

it('defaults all three launch feature flags to false', function () {
    expect(config('sidest.features.smart_booking'))->toBeFalse();
    expect(config('sidest.features.square_sync'))->toBeFalse();
    expect(config('sidest.features.fresha_sync'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php`
Expected: FAIL with "Failed asserting that null is array" or similar — `sidest.features` key does not exist.

- [ ] **Step 3: Add flags to `config/sidest.php`**

Before the closing `];`, append:

```php
    /*
    |----------------------------------------------------------------------
    | Launch feature flags
    |----------------------------------------------------------------------
    | Master switches for functionality that's coded but not yet live.
    | All default to false; flip in .env once the feature is ready.
    |
    | smart_booking  — gates all /booking/* routes (professional, public,
    |                  analytics) and forbids selecting booking_mode='smart'.
    |                  When off, only manual booking (redirect link) works.
    | square_sync    — gates Square integration (/square/* routes, webhook,
    |                  observer dispatch, sync jobs).
    | fresha_sync    — gates Fresha integration (/fresha/* routes, webhook,
    |                  observer dispatch, sync jobs).
    |
    | Square/Fresha ONLY power smart booking — if smart_booking is off, their
    | flags are largely redundant but kept separate so we can enable one
    | provider before the other post-launch.
    */
    'features' => [
        'smart_booking' => (bool) env('SIDEST_SMART_BOOKING_ENABLED', false),
        'square_sync' => (bool) env('SIDEST_SQUARE_SYNC_ENABLED', false),
        'fresha_sync' => (bool) env('SIDEST_FRESHA_SYNC_ENABLED', false),
    ],
```

- [ ] **Step 4: Update `.env.example`**

Append (if not already present under a feature-flags section):

```
# Launch feature flags — default false; flip on when feature is ready to ship
SIDEST_SMART_BOOKING_ENABLED=false
SIDEST_SQUARE_SYNC_ENABLED=false
SIDEST_FRESHA_SYNC_ENABLED=false
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add config/sidest.php .env.example tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php
git commit -m "feat(launch-gates): add smart_booking/square_sync/fresha_sync feature flags"
```

---

## Task 2: Create FeatureGate Middleware + Register Alias

**Files:**
- Create: `app/Http/Middleware/FeatureGate.php`
- Modify: `bootstrap/app.php` (add `'feature'` to the middleware alias array)
- Test: `tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php` (extend existing file)

- [ ] **Step 1: Extend the test file with middleware behaviour tests**

Append to `tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php`:

```php
it('returns 503 when the named feature flag is off', function () {
    config()->set('sidest.features.smart_booking', false);

    Route::middleware('feature:smart_booking')
        ->get('/__test/feature-gate', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate')
        ->assertStatus(503)
        ->assertJson(['message' => 'Feature not available']);
});

it('passes through when the named feature flag is on', function () {
    config()->set('sidest.features.smart_booking', true);

    Route::middleware('feature:smart_booking')
        ->get('/__test/feature-gate', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('returns 503 for unknown flag keys (fail closed)', function () {
    Route::middleware('feature:nonexistent_flag')
        ->get('/__test/feature-gate-unknown', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate-unknown')->assertStatus(503);
});
```

The `use Illuminate\Support\Facades\Route;` import goes at the top of the file.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php`
Expected: FAIL with "Target class [feature] does not exist" or similar.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/FeatureGate.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// V2: Launch-time feature gate. Reads `sidest.features.{flag}` and short-circuits
// with 503 when the flag is false. Fails closed: an unknown flag key = off.
// Apply via route middleware: `->middleware('feature:smart_booking')`.
class FeatureGate
{
    public function handle(Request $request, Closure $next, string $flag)
    {
        if (! (bool) config("sidest.features.{$flag}", false)) {
            return response()->json([
                'message' => 'Feature not available',
                'feature' => $flag,
            ], 503);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias**

In `bootstrap/app.php`, inside `->withMiddleware(...)`, extend the `$middleware->alias([...])` array with:

```php
'feature' => FeatureGate::class,
```

Add the import at the top of `bootstrap/app.php`:

```php
use App\Http\Middleware\FeatureGate;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php`
Expected: PASS (5 tests total).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/FeatureGate.php bootstrap/app.php tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php
git commit -m "feat(launch-gates): add FeatureGate middleware + feature alias"
```

---

## Task 3: Gate /booking Routes (Professional + Public)

**Testing pattern:** Pattern C — route middleware inspection. The middleware's behaviour is already verified in Task 2; here we just confirm attachment.

**Files:**
- Modify: `routes/api/professional.php` (booking routes)
- Modify: `routes/api/publicSite.php` (public booking routes)
- Modify: `routes/api.php` (public booking-by-slug routes)
- Test: `tests/Feature/FeatureFlags/BookingRoutesGatedTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FeatureFlags/BookingRoutesGatedTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

function routeMiddlewareFor(string $method, string $uri): array
{
    $route = collect(Route::getRoutes()->getRoutes())->first(function ($r) use ($method, $uri) {
        return in_array(strtoupper($method), $r->methods())
            && $r->uri() === ltrim($uri, '/');
    });

    expect($route)->not->toBeNull("Route [{$method} {$uri}] not registered");

    return $route->gatherMiddleware();
}

it('PATCH api/professional/booking/settings has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('PATCH', 'api/professional/booking/settings'))
        ->toContain('feature:smart_booking');
});

it('GET api/professional/booking/my-analytics/overview has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('GET', 'api/professional/booking/my-analytics/overview'))
        ->toContain('feature:smart_booking');
});

it('GET api/public/booking/config-by-slug has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('GET', 'api/public/booking/config-by-slug'))
        ->toContain('feature:smart_booking');
});

it('GET api/public/booking/services-by-slug has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('GET', 'api/public/booking/services-by-slug'))
        ->toContain('feature:smart_booking');
});

it('POST api/public/booking/availability-by-slug has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('POST', 'api/public/booking/availability-by-slug'))
        ->toContain('feature:smart_booking');
});

it('POST api/public/booking/checkout-by-slug has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('POST', 'api/public/booking/checkout-by-slug'))
        ->toContain('feature:smart_booking');
});

it('GET api/public/booking/config has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('GET', 'api/public/booking/config'))
        ->toContain('feature:smart_booking');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/BookingRoutesGatedTest.php`
Expected: FAIL — the `feature:smart_booking` middleware is not attached to these routes.

- [ ] **Step 3: Wrap booking routes in `routes/api/professional.php`**

Replace lines 137-138 (the two `/booking/*` routes) with a grouped middleware block:

```php
Route::middleware('feature:smart_booking')->group(function () {
    Route::patch('/booking/settings', [ProfessionalSiteController::class, 'updateBookingSettings']);
    Route::get('/booking/my-analytics/overview', [BookingAnalyticsController::class, 'myOverview']);
});
```

- [ ] **Step 4: Wrap booking routes in `routes/api/publicSite.php`**

Find the `// Public booking flow` block (lines 26-34) and wrap the four routes in a feature-gate group. Preserve the per-route throttle middlewares:

```php
// Public booking flow — gated behind smart_booking feature flag
Route::middleware('feature:smart_booking')->group(function () {
    Route::get('/booking/config', [PublicBookingController::class, 'config'])
        ->middleware('throttle:public-site');
    Route::get('/booking/services', [PublicBookingController::class, 'services'])
        ->middleware('throttle:public-site');
    Route::post('/booking/availability', [PublicBookingController::class, 'availability'])
        ->middleware('throttle:public-site');
    Route::post('/booking/checkout', [PublicBookingController::class, 'checkout'])
        ->middleware('throttle:booking-checkout');
});
```

- [ ] **Step 5: Wrap public booking-by-slug routes in `routes/api.php`**

Find the four `/public/booking/*-by-slug` routes (around lines 93-100) and wrap them:

```php
Route::middleware('feature:smart_booking')->group(function () {
    Route::get('/public/booking/config-by-slug', [PublicBookingController::class, 'config'])
        ->middleware('throttle:public-site');
    Route::get('/public/booking/services-by-slug', [PublicBookingController::class, 'services'])
        ->middleware('throttle:public-site');
    Route::post('/public/booking/availability-by-slug', [PublicBookingController::class, 'availability'])
        ->middleware('throttle:public-site');
    Route::post('/public/booking/checkout-by-slug', [PublicBookingController::class, 'checkout'])
        ->middleware('throttle:booking-checkout');
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/BookingRoutesGatedTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add routes/api/professional.php routes/api/publicSite.php routes/api.php tests/Feature/FeatureFlags/BookingRoutesGatedTest.php
git commit -m "feat(launch-gates): gate /booking routes behind smart_booking flag"
```

---

## Task 4: Gate /square and /fresha Routes

**Testing pattern:** Pattern C — route middleware inspection (reuse the `routeMiddlewareFor` helper defined in Task 3's test).

**Files:**
- Modify: `routes/api/professional.php` (Square + Fresha route sections)
- Test: `tests/Feature/FeatureFlags/PosSyncRoutesGatedTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FeatureFlags/PosSyncRoutesGatedTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

// Duplicate of helper from BookingRoutesGatedTest — Pest global helpers would
// let us share, but keeping this local means each test file is self-contained.
function posRouteMiddlewareFor(string $method, string $uri): array
{
    $route = collect(Route::getRoutes()->getRoutes())->first(function ($r) use ($method, $uri) {
        return in_array(strtoupper($method), $r->methods())
            && $r->uri() === ltrim($uri, '/');
    });

    expect($route)->not->toBeNull("Route [{$method} {$uri}] not registered");

    return $route->gatherMiddleware();
}

// --- Square (all under feature:square_sync) ---

it('POST api/professional/square/connect has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/professional/square/connect'))
        ->toContain('feature:square_sync');
});

it('POST api/professional/square/disconnect has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/professional/square/disconnect'))
        ->toContain('feature:square_sync');
});

it('GET api/professional/square/token has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('GET', 'api/professional/square/token'))
        ->toContain('feature:square_sync');
});

it('POST api/professional/square/services/sync has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/professional/square/services/sync'))
        ->toContain('feature:square_sync');
});

it('POST api/professional/square/services/{service}/push has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/professional/square/services/{service}/push'))
        ->toContain('feature:square_sync');
});

// --- Fresha (all under feature:fresha_sync) ---

it('POST api/professional/fresha/connect has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/professional/fresha/connect'))
        ->toContain('feature:fresha_sync');
});

it('POST api/professional/fresha/disconnect has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/professional/fresha/disconnect'))
        ->toContain('feature:fresha_sync');
});

it('GET api/professional/fresha/token has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('GET', 'api/professional/fresha/token'))
        ->toContain('feature:fresha_sync');
});

it('POST api/professional/fresha/services/sync has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/professional/fresha/services/sync'))
        ->toContain('feature:fresha_sync');
});

it('POST api/professional/fresha/services/{service}/push has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/professional/fresha/services/{service}/push'))
        ->toContain('feature:fresha_sync');
});
```

**Before implementing:** run `php artisan route:list | grep -E '(square|fresha)' | grep professional` to enumerate every Square and Fresha route currently in `routes/api/professional.php`. If there are routes not listed above (e.g. additional `connect` variants, OAuth callbacks), wrap them in the feature group too and add corresponding test cases.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/PosSyncRoutesGatedTest.php`
Expected: FAIL — `feature:square_sync` / `feature:fresha_sync` middleware not yet attached.

- [ ] **Step 3: Wrap Square routes in `routes/api/professional.php`**

Find the Square integration block (includes `connect`, `disconnect`, `token`, `services/sync`, `services/{service}/push`). Wrap the entire block in a feature-gate group:

```php
Route::middleware('feature:square_sync')->group(function () {
    Route::post('/square/connect', [SquareIntegrationController::class, 'connect']);
    Route::post('/square/disconnect', [SquareIntegrationController::class, 'disconnect']);
    Route::get('/square/token', [SquareIntegrationController::class, 'token']);
    Route::post('/square/services/sync', [SquareIntegrationController::class, 'syncServicesNow']);
    Route::post('/square/services/{service}/push', [SquareIntegrationController::class, 'pushServiceNow'])
        ->whereUuid('service');
});
```

(Preserve the exact route definitions that already exist — just wrap them in the group. Check the file for any other `/square/*` routes in the professional namespace and include them all.)

- [ ] **Step 4: Wrap Fresha routes in `routes/api/professional.php`**

Same approach for Fresha:

```php
Route::middleware('feature:fresha_sync')->group(function () {
    Route::post('/fresha/connect', [FreshaIntegrationController::class, 'connect']);
    Route::post('/fresha/disconnect', [FreshaIntegrationController::class, 'disconnect']);
    Route::get('/fresha/token', [FreshaIntegrationController::class, 'token']);
    Route::post('/fresha/services/sync', [FreshaIntegrationController::class, 'syncServicesNow']);
    Route::post('/fresha/services/{service}/push', [FreshaIntegrationController::class, 'pushServiceNow'])
        ->whereUuid('service');
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/PosSyncRoutesGatedTest.php`
Expected: PASS (8 tests).

- [ ] **Step 6: Commit**

```bash
git add routes/api/professional.php tests/Feature/FeatureFlags/PosSyncRoutesGatedTest.php
git commit -m "feat(launch-gates): gate /square and /fresha routes behind sync flags"
```

---

## Task 5: Gate Square + Fresha Webhook Controllers

**Design note:** Webhooks must return 200 even when gated — Square and Fresha retry aggressively on non-2xx responses. We accept the webhook, log that it was suppressed, and skip the sync dispatch.

**Files:**
- Modify: `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php`
- Modify: `app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php`
- Test: `tests/Feature/FeatureFlags/WebhooksGatedTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FeatureFlags/WebhooksGatedTest.php`:

```php
<?php

use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('Square webhook returns 200 and does not dispatch sync when flag is off', function () {
    config()->set('sidest.features.square_sync', false);

    // Bypass signature check by mocking config to disable it, or send a request
    // that will get past validation into the gated branch. Simpler: post an
    // invalid-signature request — but the gate must run BEFORE signature check
    // so this test doesn't depend on signature mechanics.
    $this->postJson('/api/webhooks/square', [
        'event_id' => 'test-evt-1',
        'type' => 'catalog.version.updated',
        'merchant_id' => 'MERCHANT_ABC',
    ])->assertStatus(200)
      ->assertJson(['received' => true, 'feature_gated' => true]);

    Queue::assertNotPushed(SyncSquareCatalogDeltaJob::class);
});

it('Fresha webhook returns 200 and does not dispatch sync when flag is off', function () {
    config()->set('sidest.features.fresha_sync', false);

    $this->postJson('/api/webhooks/fresha', [
        'event_id' => 'test-evt-2',
        'type' => 'catalog.version.updated',
        'merchant_id' => 'MERCHANT_XYZ',
    ])->assertStatus(200)
      ->assertJson(['received' => true, 'feature_gated' => true]);

    Queue::assertNotPushed(SyncFreshaCatalogDeltaJob::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/WebhooksGatedTest.php`
Expected: FAIL — either 401 (signature rejected) or dispatch happens.

- [ ] **Step 3: Short-circuit `SquareCatalogWebhookController::__invoke`**

In `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php`, at the top of `__invoke()` (immediately after the method signature, BEFORE the signature check), add:

```php
if (! (bool) config('sidest.features.square_sync', false)) {
    Log::info('Square webhook suppressed — square_sync feature flag is off');

    return $this->success(['received' => true, 'feature_gated' => true]);
}
```

- [ ] **Step 4: Short-circuit `FreshaCatalogWebhookController::__invoke`**

In `app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php`, at the top of `__invoke()` (immediately after the method signature, BEFORE the signature check), add:

```php
if (! (bool) config('sidest.features.fresha_sync', false)) {
    Log::info('Fresha webhook suppressed — fresha_sync feature flag is off');

    return $this->success(['received' => true, 'feature_gated' => true]);
}
```

(If `Log` is not already imported in FreshaCatalogWebhookController, add `use Illuminate\Support\Facades\Log;`.)

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/WebhooksGatedTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php tests/Feature/FeatureFlags/WebhooksGatedTest.php
git commit -m "feat(launch-gates): short-circuit Square/Fresha webhooks when sync flags off"
```

---

## Task 6: Gate ServiceObserver Sync Dispatches

**Design note:** The observer's non-sync side-effects (cache invalidation, booking section reevaluation) must keep running. Only the two `dispatch*Sync` calls are gated.

**Testing pattern:** Unit-level reflection test. `shouldDispatchSquareSync` / `shouldDispatchFreshaSync` are private. We invoke them via reflection with a bare `Professional` instance (no DB needed) to verify the flag is checked BEFORE the integration lookup. This is sufficient because the methods' existing non-flag branches are already in place and untouched.

**Files:**
- Modify: `app/Observers/Core/ServiceObserver.php`
- Test: `tests/Feature/FeatureFlags/ServiceObserverSyncGatedTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FeatureFlags/ServiceObserverSyncGatedTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Observers\Core\ServiceObserver;

function invokePrivateObserverMethod(string $method, Professional $pro): bool
{
    $observer = app(ServiceObserver::class);
    $reflection = new ReflectionClass($observer);
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return (bool) $reflectionMethod->invoke($observer, $pro);
}

it('shouldDispatchSquareSync returns false when square_sync flag is off', function () {
    config()->set('sidest.features.square_sync', false);

    $pro = new Professional(['id' => 'pro-anything']);

    expect(invokePrivateObserverMethod('shouldDispatchSquareSync', $pro))->toBeFalse();
});

it('shouldDispatchFreshaSync returns false when fresha_sync flag is off', function () {
    config()->set('sidest.features.fresha_sync', false);

    $pro = new Professional(['id' => 'pro-anything']);

    expect(invokePrivateObserverMethod('shouldDispatchFreshaSync', $pro))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/ServiceObserverSyncGatedTest.php`
Expected: FAIL — before the flag-check is added, the method falls through to the integration lookup, which queries the DB and may error or return a different result. (The exact failure mode depends on the test DB state, but it will NOT return the clean `false` the flag-off path should return.)

- [ ] **Step 3: Gate dispatches in `ServiceObserver::shouldDispatchSquareSync`**

In `app/Observers/Core/ServiceObserver.php`, modify `shouldDispatchSquareSync()`:

```php
private function shouldDispatchSquareSync(?Professional $professional): bool
{
    if (! (bool) config('sidest.features.square_sync', false)) {
        return false;
    }

    if (! $professional) {
        return false;
    }

    $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
    if (! $integration || empty($integration->access_token) || empty($integration->external_account_id)) {
        return false;
    }

    return (bool) data_get($professional->site?->settings, 'services_auto_sync_enabled', false);
}
```

- [ ] **Step 4: Gate dispatches in `ServiceObserver::shouldDispatchFreshaSync`**

Modify `shouldDispatchFreshaSync()` the same way:

```php
private function shouldDispatchFreshaSync(?Professional $professional): bool
{
    if (! (bool) config('sidest.features.fresha_sync', false)) {
        return false;
    }

    if (! $professional) {
        return false;
    }

    $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_FRESHA);
    if (! $integration || empty($integration->access_token) || empty($integration->external_account_id)) {
        return false;
    }

    return (bool) data_get($professional->site?->settings, 'services_auto_sync_enabled', false);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/ServiceObserverSyncGatedTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Observers/Core/ServiceObserver.php tests/Feature/FeatureFlags/ServiceObserverSyncGatedTest.php
git commit -m "feat(launch-gates): short-circuit ServiceObserver sync dispatches"
```

---

## Task 7: Gate Push + Sync Jobs (Belt-and-Suspenders)

**Design note:** Even with the observer gated, an existing queue might have already-pushed jobs from before the flag flip. These jobs early-return so we never call Square/Fresha APIs at launch.

**Files:**
- Modify: `app/Jobs/Square/PushServiceToSquareJob.php`
- Modify: `app/Jobs/Square/SyncSquareCatalogDeltaJob.php`
- Modify: `app/Jobs/Fresha/PushServiceToFreshaJob.php`
- Modify: `app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php`
- Test: `tests/Feature/FeatureFlags/SyncJobsGatedTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FeatureFlags/SyncJobsGatedTest.php`:

```php
<?php

use App\Jobs\Fresha\PushServiceToFreshaJob;
use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use App\Jobs\Square\PushServiceToSquareJob;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use App\Services\Fresha\FreshaServiceSyncService;
use App\Services\Square\SquareServiceSyncService;
use Mockery\MockInterface;

it('PushServiceToSquareJob::handle does nothing when square_sync flag is off', function () {
    config()->set('sidest.features.square_sync', false);

    $syncService = $this->mock(SquareServiceSyncService::class, function (MockInterface $m) {
        $m->shouldNotReceive('pushServiceToSquare');
    });

    (new PushServiceToSquareJob('any-service-id'))->handle($syncService);
});

it('SyncSquareCatalogDeltaJob::handle does nothing when square_sync flag is off', function () {
    config()->set('sidest.features.square_sync', false);

    $syncService = $this->mock(SquareServiceSyncService::class, function (MockInterface $m) {
        $m->shouldNotReceive('syncFromSquare');
    });

    (new SyncSquareCatalogDeltaJob('merchant-1'))->handle($syncService);
});

it('PushServiceToFreshaJob::handle does nothing when fresha_sync flag is off', function () {
    config()->set('sidest.features.fresha_sync', false);

    $syncService = $this->mock(FreshaServiceSyncService::class, function (MockInterface $m) {
        $m->shouldNotReceive('pushServiceToFresha');
    });

    (new PushServiceToFreshaJob('any-service-id'))->handle($syncService);
});

it('SyncFreshaCatalogDeltaJob::handle does nothing when fresha_sync flag is off', function () {
    config()->set('sidest.features.fresha_sync', false);

    $syncService = $this->mock(FreshaServiceSyncService::class, function (MockInterface $m) {
        $m->shouldNotReceive('syncFromFresha');
    });

    (new SyncFreshaCatalogDeltaJob('acct-1'))->handle($syncService);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/SyncJobsGatedTest.php`
Expected: FAIL — jobs attempt to call the sync service.

- [ ] **Step 3: Add guard to `PushServiceToSquareJob::handle`**

In `app/Jobs/Square/PushServiceToSquareJob.php`, at the top of `handle()`:

```php
public function handle(SquareServiceSyncService $syncService): void
{
    if (! (bool) config('sidest.features.square_sync', false)) {
        return;
    }

    $service = Service::query()
        ->withTrashed()
        ->where('id', $this->serviceId)
        ->first();

    if (! $service) {
        return;
    }

    $syncService->pushServiceToSquare($service, $this->action);
}
```

- [ ] **Step 4: Add guard to `SyncSquareCatalogDeltaJob::handle`**

In `app/Jobs/Square/SyncSquareCatalogDeltaJob.php`, at the top of `handle()`:

```php
public function handle(SquareServiceSyncService $syncService): void
{
    if (! (bool) config('sidest.features.square_sync', false)) {
        return;
    }

    $integration = ProfessionalIntegration::query()
        ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
        ->where('external_account_id', $this->merchantId)
        ->first();

    // ... rest unchanged
}
```

- [ ] **Step 5: Add guard to `PushServiceToFreshaJob::handle`**

Same pattern in `app/Jobs/Fresha/PushServiceToFreshaJob.php`:

```php
public function handle(FreshaServiceSyncService $syncService): void
{
    if (! (bool) config('sidest.features.fresha_sync', false)) {
        return;
    }

    // ... rest unchanged
}
```

- [ ] **Step 6: Add guard to `SyncFreshaCatalogDeltaJob::handle`**

Same pattern in `app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php`:

```php
public function handle(FreshaServiceSyncService $syncService): void
{
    if (! (bool) config('sidest.features.fresha_sync', false)) {
        return;
    }

    // ... rest unchanged
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/SyncJobsGatedTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/Square/PushServiceToSquareJob.php app/Jobs/Square/SyncSquareCatalogDeltaJob.php app/Jobs/Fresha/PushServiceToFreshaJob.php app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php tests/Feature/FeatureFlags/SyncJobsGatedTest.php
git commit -m "feat(launch-gates): short-circuit Push/Sync jobs when sync flags off"
```

---

## Task 8: Reject `booking_mode=smart` When Flag Off

**Design note:** Three write surfaces allow setting `booking_mode`:
1. `UpdateSiteRequest` (`settings.booking_mode` field in the generic site update)
2. `ProfessionalSiteController::updateBookingSettings` (dedicated endpoint — inline validator)
3. `StaffUpdateSiteRequest` (staff equivalent)

All three must reject `'smart'` when the flag is off.

**Testing pattern:** Pattern A — validate requests directly via `validateResolved()`, following `tests/Feature/Site/LinkBlockSocialValidationTest.php`. No DB/auth needed.

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php`
- Modify: `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php`
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSiteController.php`
- Test: `tests/Feature/FeatureFlags/BookingModeSmartRejectedTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FeatureFlags/BookingModeSmartRejectedTest.php`:

```php
<?php

use App\Http\Requests\Api\Professional\Site\UpdateSiteRequest;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateSiteRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

function validateAgainstRequest(string $requestClass, array $payload): array
{
    $request = Request::create('/test', 'PATCH', $payload);
    $formRequest = $requestClass::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

it('UpdateSiteRequest rejects settings.booking_mode=smart when smart_booking flag is off', function () {
    config()->set('sidest.features.smart_booking', false);

    $result = validateAgainstRequest(UpdateSiteRequest::class, [
        'settings' => ['booking_mode' => 'smart'],
    ]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.booking_mode');
});

it('UpdateSiteRequest accepts settings.booking_mode=smart when smart_booking flag is on', function () {
    config()->set('sidest.features.smart_booking', true);

    $result = validateAgainstRequest(UpdateSiteRequest::class, [
        'settings' => ['booking_mode' => 'smart'],
    ]);

    // Should not have a booking_mode validation error (other fields may be required;
    // we only check that THIS rule is not what's blocking us).
    expect($result['errors'] ?? [])->not->toHaveKey('settings.booking_mode');
});

it('UpdateSiteRequest always accepts settings.booking_mode=manual', function () {
    config()->set('sidest.features.smart_booking', false);

    $result = validateAgainstRequest(UpdateSiteRequest::class, [
        'settings' => ['booking_mode' => 'manual'],
    ]);

    expect($result['errors'] ?? [])->not->toHaveKey('settings.booking_mode');
});

it('StaffUpdateSiteRequest rejects settings.booking_mode=smart when smart_booking flag is off', function () {
    config()->set('sidest.features.smart_booking', false);

    $result = validateAgainstRequest(StaffUpdateSiteRequest::class, [
        'settings' => ['booking_mode' => 'smart'],
    ]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.booking_mode');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/BookingModeSmartRejectedTest.php`
Expected: FAIL — the current `Rule::in(['manual', 'smart'])` accepts `'smart'` unconditionally.

- [ ] **Step 3: Narrow the `Rule::in` in `UpdateSiteRequest`**

In `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php`, find the `'settings.booking_mode'` rule (line ~135) and replace with:

```php
'settings.booking_mode' => [
    'sometimes',
    'string',
    Rule::in(config('sidest.features.smart_booking') ? ['manual', 'smart'] : ['manual']),
],
```

- [ ] **Step 4: Narrow the `Rule::in` in `StaffUpdateSiteRequest`**

Same change in `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php`. Find the equivalent `settings.booking_mode` rule and apply the same conditional `Rule::in` list.

- [ ] **Step 5: Narrow the inline validator in `updateBookingSettings`**

In `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSiteController.php`, update the `updateBookingSettings()` method's inline validator (around line 56-59):

```php
$allowedModes = config('sidest.features.smart_booking') ? ['manual', 'smart'] : ['manual'];

$validator = Validator::make($request->all(), [
    'booking_mode' => ['required', 'string', Rule::in($allowedModes)],
    'manual_booking_url' => ['nullable', 'url', 'max:2048'],
]);
```

(Note: the `/booking/settings` route itself is already route-gated in Task 3, so this branch only runs when the flag is on. Still, narrow the validator for defense in depth and for the case where the flag is partially enabled during rollout.)

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/BookingModeSmartRejectedTest.php`
Expected: PASS (2 active tests, 1 skipped).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalSiteController.php tests/Feature/FeatureFlags/BookingModeSmartRejectedTest.php
git commit -m "feat(launch-gates): reject booking_mode=smart when smart_booking flag off"
```

---

## Task 9: Allow Booking Section When Flag Off If Manual Link Is Set

**Design note:** Currently `SectionVisibilityService::checkBookingRequirements` requires EITHER a Square/Fresha integration OR a `booking_url` block setting. When `smart_booking` is off, we must only accept the link path — otherwise the professional has no way to publish the booking section.

**Testing pattern:** Pattern B — SQLite in-memory with manual schema. Follow `tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.php` as template. Create a local `SectionVisibilityTestCase::boot()` that sets up just the four tables needed: `core.professionals`, `site.services`, `core.professional_integrations`, `site.blocks`.

**Files:**
- Modify: `app/Services/Professional/SectionVisibilityService.php`
- Create: `tests/Feature/FeatureFlags/SectionVisibilityTestCase.php` (boot helper)
- Test: `tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php` (new)

- [ ] **Step 1: Create the test-case boot helper**

Create `tests/Feature/FeatureFlags/SectionVisibilityTestCase.php`:

```php
<?php

namespace Tests\Feature\FeatureFlags;

use Illuminate\Support\Facades\DB;

class SectionVisibilityTestCase
{
    public static function boot(): void
    {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $conn = DB::connection('pgsql');

        foreach (['core', 'site'] as $schema) {
            try {
                $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
            } catch (\Throwable) {
            }
        }

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
            id TEXT PRIMARY KEY,
            handle TEXT,
            display_name TEXT,
            primary_email TEXT,
            status TEXT DEFAULT "active",
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            provider TEXT,
            access_token TEXT,
            external_account_id TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.services (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            title TEXT,
            price_cents INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.blocks (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            site_id TEXT,
            block_group TEXT,
            block_type TEXT,
            settings TEXT,
            is_enabled INTEGER DEFAULT 1,
            is_active INTEGER DEFAULT 1,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php`:

```php
<?php

use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\SectionVisibilityTestCase;

beforeEach(function () {
    SectionVisibilityTestCase::boot();
});

function seedProAndSite(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'test-pro',
        'display_name' => 'Test Pro',
        'primary_email' => 'test@example.com',
        'status' => 'active',
    ]);

    return [$proId, $siteId];
}

function seedActiveService(string $proId): void
{
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'title' => 'Test Service',
        'price_cents' => 5000,
        'is_active' => 1,
    ]);
}

function seedSquareIntegration(string $proId): void
{
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => 'square',
        'access_token' => 'tok',
        'external_account_id' => 'merchant-1',
    ]);
}

function seedBookingLinkBlock(string $proId, string $siteId): void
{
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'booking',
        'settings' => json_encode(['booking_url' => 'https://example.com/book']),
    ]);
}

it('rejects booking section via Square integration when smart_booking flag is off', function () {
    config()->set('sidest.features.smart_booking', false);

    [$proId, $siteId] = seedProAndSite();
    seedActiveService($proId);
    seedSquareIntegration($proId);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'booking');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('booking link');
});

it('allows booking section via manual booking_url when smart_booking flag is off', function () {
    config()->set('sidest.features.smart_booking', false);

    [$proId, $siteId] = seedProAndSite();
    seedActiveService($proId);
    seedBookingLinkBlock($proId, $siteId);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'booking');

    expect($canBeVisible)->toBeTrue();
});

it('allows booking section via Square integration when smart_booking flag is on', function () {
    config()->set('sidest.features.smart_booking', true);

    [$proId, $siteId] = seedProAndSite();
    seedActiveService($proId);
    seedSquareIntegration($proId);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'booking');

    expect($canBeVisible)->toBeTrue();
});
```

**NOTE:** if the `settings->>'booking_url'` raw-SQL extraction used in `checkBookingRequirements` uses Postgres-specific syntax that SQLite doesn't support, the "booking link" test may need either a SQLite-compatible fallback in the service, or a mock-based approach. Run the test first — if it errors on the `NULLIF(BTRIM(settings->>'booking_url'), '')` expression, adjust the service to use a portable Laravel query builder expression (`whereRaw` → `whereNotNull` + JSON column) so SQLite can parse it. This is a known tension in the codebase's SQLite-in-memory testing approach.

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php`
Expected: FAIL — first test currently returns `canBeVisible=true` via the Square integration path when it should reject.

- [ ] **Step 4: Update `checkBookingRequirements` to gate the integration path**

In `app/Services/Professional/SectionVisibilityService.php`, replace the `checkBookingRequirements` method:

```php
private function checkBookingRequirements(string $professionalId): array
{
    $hasService = Service::query()
        ->where('professional_id', $professionalId)
        ->where('is_active', true)
        ->whereNull('deleted_at')
        ->exists();

    if (! $hasService) {
        return [false, 'Booking section requires at least 1 active service.'];
    }

    // Smart-booking integration path is only available when the feature flag is on.
    // Pre-launch, only the manual booking_url (redirect link) path is accepted.
    $hasBookingIntegration = (bool) config('sidest.features.smart_booking', false)
        && ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->whereIn('provider', [
                ProfessionalIntegration::PROVIDER_SQUARE,
                ProfessionalIntegration::PROVIDER_FRESHA,
            ])
            ->exists();

    $hasBookingLink = Block::query()
        ->where('professional_id', $professionalId)
        ->where('block_group', 'sections')
        ->where('block_type', 'booking')
        ->whereRaw("NULLIF(BTRIM(settings->>'booking_url'), '') IS NOT NULL")
        ->exists();

    if (! $hasBookingIntegration && ! $hasBookingLink) {
        return [false, 'Booking section requires a booking link or booking integration.'];
    }

    return [true, null];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Run full test suite to check for regressions**

Run: `composer test`
Expected: All existing tests still pass. If any `SectionVisibilityService` tests exist elsewhere and break, inspect and fix — most likely they assumed the integration path was always available.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Professional/SectionVisibilityService.php tests/Feature/FeatureFlags/SectionVisibilityTestCase.php tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php
git commit -m "feat(launch-gates): require manual booking link when smart_booking off"
```

---

## Final Verification

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: All tests pass. No Laravel migration files created (guard runs as part of the test suite).

- [ ] **Step 2: Manual smoke check — flags off (launch config)**

With all three flags set to `false` in `.env`:

```bash
php artisan route:list | grep -E '(booking|square|fresha)'
```

Expected: routes are visible (not removed), but hitting them returns 503.

```bash
# Confirm service CRUD still works
curl -X GET http://localhost:8000/api/professional/services -H "Authorization: Bearer <token>"
```

Expected: 200 with services list.

- [ ] **Step 3: Manual smoke check — flags on (future launch)**

Temporarily set `SIDEST_SMART_BOOKING_ENABLED=true`, `SIDEST_SQUARE_SYNC_ENABLED=true` and verify `/api/professional/booking/settings` and `/api/professional/square/services/sync` return their normal responses (not 503).

Revert `.env` to launch config (all false) before commit.

- [ ] **Step 4: Final commit with launch config confirmation**

Nothing to commit at this step if `.env.example` was correctly set in Task 1. Sanity-check with `git status`.

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Smart booking endpoints gated — Task 3
- ✅ Public booking flow gated — Task 3
- ✅ Booking analytics gated — Task 3 (covered by `/booking/my-analytics/overview` gate)
- ✅ Square integration gated (routes + webhook + observer + jobs) — Tasks 4, 5, 6, 7
- ✅ Fresha integration gated (routes + webhook + observer + jobs) — Tasks 4, 5, 6, 7
- ✅ `booking_mode='smart'` rejected — Task 8
- ✅ Booking section still publishable via redirect link — Task 9
- ✅ Manual service CRUD preserved — verified by running full test suite (Task 9, step 5)
- ✅ Code stays live, reversible via env — all gates use `config('sidest.features.*')`
- ✅ Shopify integration untouched — confirmed no Shopify code modified

**Placeholder scan:** no "TBD", "add appropriate error handling", or "similar to Task N" — each task contains full code and file paths.

**Type consistency:**
- Config key `sidest.features.{flag}` consistent across all tasks
- Middleware alias `feature:{flag}` consistent across all route wrappings
- Response shape `{message: string, feature?: string}` / `{received: true, feature_gated: true}` consistent per-context
- Three flag keys (`smart_booking`, `square_sync`, `fresha_sync`) consistent across config, middleware args, and guard calls
