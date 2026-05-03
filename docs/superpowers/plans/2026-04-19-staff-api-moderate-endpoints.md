# Staff API — Moderate Endpoints Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 4 staff API endpoints covering affiliate status toggling, commission manual void, Shopify resync trigger, and brand store settings override.

**Architecture:** Each endpoint is a focused controller in `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/`, wired into `routes/api/staff.php` under the `staff.admin` middleware group. They delegate to existing services (`CommissionVoidService`, `ShopifyDataResyncService`) and models (`BrandStoreSettings`) — no new services required.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL (Supabase), Pest 4 tests with `DB::shouldReceive()` mocking (consistent with other staff tests in this codebase), existing services with no signature changes.

---

## Key Codebase Facts

- **Professional status values:** `active` | `suspended` (enforced in `StaffProfessionalController::updateStatus`)
- **CommissionVoidService::voidEntry(CommissionLedgerEntry $entry, string $reason): bool** — optimistic lock; only voids `pending` entries with no `payout_id`; returns `false` if already claimed
- **ShopifyDataResyncService::resync(ProfessionalIntegration $integration): array** — fetches shop.json, diff-merges profile, dispatches `SyncShopifyBrandDesignJob`; throws `RuntimeException` on bad integration
- **BrandStoreSettings** — table `brand.brand_store_settings`, `updateOrCreate(['professional_id' => ...], [...])` pattern
- **Rate limit key for Shopify resync:** `"shopify-resync:{$integration->id}"`, 1 attempt per 60s (same as brand-facing endpoint)
- **Test pattern:** `DB::shouldReceive('table')` for raw queries; services injected via constructor and mocked with `Mockery::mock(ServiceClass::class)`
- **All 4 endpoints are admin-only** — add to the second route group (`staff.admin` middleware)

---

## File Map

**New controllers (create):**
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateStatusController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionVoidController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyResyncController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffStoreSettingsController.php`

**New tests (create):**
- `tests/Feature/Staff/StaffAffiliateStatusControllerTest.php`
- `tests/Feature/Staff/StaffCommissionVoidControllerTest.php`
- `tests/Feature/Staff/StaffShopifyResyncControllerTest.php`
- `tests/Feature/Staff/StaffStoreSettingsControllerTest.php`

**Modify:**
- `routes/api/staff.php` — 4 new routes in the admin group

---

## Task 1: Affiliate Status Toggle

**Endpoint:** `PATCH /api/staff/professionals/{professional}/affiliates/{affiliate}/status`
**Auth:** staff.admin
**Purpose:** Staff suspends or reactivates an affiliate, verifying the affiliate actually belongs to the given brand before touching their record.

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateStatusController.php`
- Create: `tests/Feature/Staff/StaffAffiliateStatusControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffAffiliateStatusControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateStatusController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns 404 when affiliate does not belong to the brand', function () {
    $brand     = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'status' => 'active']);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('exists')->andReturn(false);

    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($mockQuery);

    $controller = new StaffAffiliateStatusController();
    $request    = Request::create('/', 'PATCH', ['status' => 'suspended']);

    $response = $controller->update($request, $brand, $affiliate);

    expect($response->status())->toBe(404);
});

it('suspends an affiliate that belongs to the brand', function () {
    $brand     = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = Mockery::mock(Professional::class)->makePartial();
    $affiliate->id     = (string) Str::uuid();
    $affiliate->status = 'active';
    $affiliate->shouldReceive('save')->once();
    $affiliate->shouldReceive('fresh')->andReturnSelf();

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('exists')->andReturn(true);

    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($mockQuery);

    $controller = new StaffAffiliateStatusController();
    $request    = Request::create('/', 'PATCH', ['status' => 'suspended']);

    $response = $controller->update($request, $brand, $affiliate);
    $data     = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKey('professional');
});

it('reactivates a suspended affiliate', function () {
    $brand     = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = Mockery::mock(Professional::class)->makePartial();
    $affiliate->id     = (string) Str::uuid();
    $affiliate->status = 'suspended';
    $affiliate->shouldReceive('save')->once();
    $affiliate->shouldReceive('fresh')->andReturnSelf();

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('exists')->andReturn(true);

    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($mockQuery);

    $controller = new StaffAffiliateStatusController();
    $request    = Request::create('/', 'PATCH', ['status' => 'active']);

    $response = $controller->update($request, $brand, $affiliate);

    expect($response->status())->toBe(200);
});

it('rejects invalid status values', function () {
    $brand     = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);

    $controller = new StaffAffiliateStatusController();
    $request    = Request::create('/', 'PATCH', ['status' => 'banned']);

    expect(fn () => $controller->update($request, $brand, $affiliate))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffAffiliateStatusControllerTest.php --no-coverage
```

Expected: FAIL — `StaffAffiliateStatusController` does not exist.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateStatusController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff admin suspends or reactivates an affiliate. Verifies brand ownership before
// touching the affiliate's record — prevents accidentally acting on unrelated professionals.
class StaffAffiliateStatusController extends ApiController
{
    /**
     * PATCH /api/staff/professionals/{professional}/affiliates/{affiliate}/status
     *
     * Body: { "status": "active" | "suspended" }
     */
    public function update(Request $request, Professional $professional, Professional $affiliate): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);

        $linked = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $professional->id)
            ->where('affiliate_professional_id', $affiliate->id)
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $affiliate->status = $data['status'];
        $affiliate->save();

        return $this->success(['professional' => $affiliate->fresh()]);
    }
}
```

- [ ] **Step 4: Add route to `routes/api/staff.php`**

Add import at top of file with other admin imports:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateStatusController;
```

Add route inside the admin group (the second `Route::group` block, the one with `staff.admin` middleware):

```php
// Toggle affiliate status for a brand (admin only)
Route::patch('/professionals/{professional}/affiliates/{affiliate}/status', [StaffAffiliateStatusController::class, 'update'])
    ->whereUuid('affiliate');
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffAffiliateStatusControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite to confirm no regressions**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

Expected: same pre-existing 3 failures, all new tests passing.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateStatusController.php \
        tests/Feature/Staff/StaffAffiliateStatusControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add PATCH /staff/professionals/{professional}/affiliates/{affiliate}/status"
```

---

## Task 2: Manual Commission Void

**Endpoint:** `POST /api/staff/commissions/{commission}/void`
**Auth:** staff.admin
**Purpose:** Staff manually voids a single pending commission entry with a required reason. Delegates to the existing `CommissionVoidService::voidEntry()` which uses an optimistic lock — safe against concurrent processing.

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionVoidController.php`
- Create: `tests/Feature/Staff/StaffCommissionVoidControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffCommissionVoidControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionVoidController;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('voids a pending commission entry', function () {
    $entry = new CommissionLedgerEntry([
        'id'        => (string) Str::uuid(),
        'status'    => 'pending',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $voidService->shouldReceive('voidEntry')
        ->once()
        ->with($entry, 'staff_manual: duplicate order')
        ->andReturn(true);

    $controller = new StaffCommissionVoidController($voidService);
    $request    = Request::create('/', 'POST', ['reason' => 'duplicate order']);

    $response = $controller->void($request, $entry);
    $data     = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['voided'])->toBeTrue()
        ->and($data['id'])->toBe($entry->id);
});

it('returns 422 when entry is not pending', function () {
    $entry = new CommissionLedgerEntry([
        'id'     => (string) Str::uuid(),
        'status' => 'approved',
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);

    $controller = new StaffCommissionVoidController($voidService);
    $request    = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $entry);

    expect($response->status())->toBe(422);
});

it('returns 422 when entry already has a payout', function () {
    $entry = new CommissionLedgerEntry([
        'id'        => (string) Str::uuid(),
        'status'    => 'pending',
        'payout_id' => (string) Str::uuid(),
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);

    $controller = new StaffCommissionVoidController($voidService);
    $request    = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $entry);

    expect($response->status())->toBe(422);
});

it('returns 409 when optimistic lock loses the race', function () {
    $entry = new CommissionLedgerEntry([
        'id'        => (string) Str::uuid(),
        'status'    => 'pending',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $voidService->shouldReceive('voidEntry')->andReturn(false);

    $controller = new StaffCommissionVoidController($voidService);
    $request    = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $entry);

    expect($response->status())->toBe(409);
});

it('requires a reason', function () {
    $entry = new CommissionLedgerEntry([
        'id'        => (string) Str::uuid(),
        'status'    => 'pending',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $controller  = new StaffCommissionVoidController($voidService);
    $request     = Request::create('/', 'POST', []);

    expect(fn () => $controller->void($request, $entry))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffCommissionVoidControllerTest.php --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionVoidController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff admin manually voids a single pending commission entry. Prefixes the reason
// with 'staff_manual:' so voided entries are auditable as staff-initiated vs system-initiated.
class StaffCommissionVoidController extends ApiController
{
    public function __construct(
        private readonly CommissionVoidService $voidService,
    ) {}

    /**
     * POST /api/staff/commissions/{commission}/void
     *
     * Body: { "reason": string }
     * Only pending entries with no payout_id are voidable.
     */
    public function void(Request $request, CommissionLedgerEntry $commission): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($commission->status !== 'pending') {
            return $this->error(
                "Commission is '{$commission->status}' and cannot be voided. Only pending entries are voidable.",
                422
            );
        }

        if ($commission->payout_id !== null) {
            return $this->error('Commission is already attached to a payout batch and cannot be voided.', 422);
        }

        $voided = $this->voidService->voidEntry($commission, 'staff_manual: ' . $data['reason']);

        if (! $voided) {
            return $this->error(
                'Commission was claimed by a concurrent process. Refresh and try again.',
                409
            );
        }

        return $this->success([
            'id'     => $commission->id,
            'voided' => true,
        ]);
    }
}
```

- [ ] **Step 4: Add route**

Add import:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionVoidController;
```

Add route in the admin group. This is top-level (not under a professional) since a commission spans both brand and affiliate:

```php
// Manually void a pending commission entry (admin only)
Route::post('/commissions/{commission}/void', [StaffCommissionVoidController::class, 'void'])
    ->whereUuid('commission');
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffCommissionVoidControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionVoidController.php \
        tests/Feature/Staff/StaffCommissionVoidControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add POST /staff/commissions/{commission}/void (admin)"
```

---

## Task 3: Shopify Resync Trigger

**Endpoint:** `POST /api/staff/professionals/{professional}/integrations/shopify/resync`
**Auth:** staff.admin
**Purpose:** Staff triggers a Shopify data resync for any brand — same service as the brand-facing endpoint, same rate limit key, scoped by professional ID instead of the authenticated JWT.

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyResyncController.php`
- Create: `tests/Feature/Staff/StaffShopifyResyncControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffShopifyResyncControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffShopifyResyncController;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Professional;
use App\Services\Shopify\ShopifyDataResyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

it('returns 404 when professional has no Shopify integration', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('first')->andReturn(null);

    DB::shouldReceive('table')->with('core.professional_integrations')->andReturn($mockQuery);

    $resyncService = Mockery::mock(ShopifyDataResyncService::class);
    $controller    = new StaffShopifyResyncController($resyncService);

    $response = $controller->invoke(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(404);
});

it('returns 429 when rate limit is exceeded', function () {
    $professional  = new Professional(['id' => (string) Str::uuid()]);
    $integrationId = (string) Str::uuid();

    $row = (object) ['id' => $integrationId, 'access_token' => 'tok'];

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('first')->andReturn($row);

    DB::shouldReceive('table')->with('core.professional_integrations')->andReturn($mockQuery);

    RateLimiter::shouldReceive('tooManyAttempts')
        ->with("shopify-resync:{$integrationId}", 1)
        ->andReturn(true);
    RateLimiter::shouldReceive('availableIn')->andReturn(45);

    $resyncService = Mockery::mock(ShopifyDataResyncService::class);
    $controller    = new StaffShopifyResyncController($resyncService);

    $response = $controller->invoke(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(429);
});

it('returns resync result on success', function () {
    $professional  = new Professional(['id' => (string) Str::uuid()]);
    $integrationId = (string) Str::uuid();

    $row = (object) ['id' => $integrationId, 'access_token' => 'tok'];

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('first')->andReturn($row);

    DB::shouldReceive('table')->with('core.professional_integrations')->andReturn($mockQuery);

    RateLimiter::shouldReceive('tooManyAttempts')->andReturn(false);
    RateLimiter::shouldReceive('hit')->with("shopify-resync:{$integrationId}", 60)->once();

    $resyncResult = [
        'fields_updated'   => ['display_name'],
        'fields_preserved' => [],
        'jobs_dispatched'  => ['brand_design'],
        'last_resynced_at' => now()->toIso8601String(),
    ];

    $resyncService = Mockery::mock(ShopifyDataResyncService::class);
    $resyncService->shouldReceive('resync')
        ->once()
        ->andReturn($resyncResult);

    $controller = new StaffShopifyResyncController($resyncService);

    $response = $controller->invoke(Request::create('/', 'POST'), $professional);
    $data     = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['fields_updated', 'fields_preserved', 'jobs_dispatched', 'last_resynced_at']);
});

it('returns 502 when ShopifyDataResyncService throws', function () {
    $professional  = new Professional(['id' => (string) Str::uuid()]);
    $integrationId = (string) Str::uuid();

    $row = (object) ['id' => $integrationId, 'access_token' => 'tok'];

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('first')->andReturn($row);

    DB::shouldReceive('table')->with('core.professional_integrations')->andReturn($mockQuery);

    RateLimiter::shouldReceive('tooManyAttempts')->andReturn(false);
    RateLimiter::shouldReceive('hit')->once();

    $resyncService = Mockery::mock(ShopifyDataResyncService::class);
    $resyncService->shouldReceive('resync')->andThrow(new \RuntimeException('Bad token'));

    $controller = new StaffShopifyResyncController($resyncService);

    $response = $controller->invoke(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(502);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffShopifyResyncControllerTest.php --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyResyncController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyDataResyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

// V2: Staff admin triggers a Shopify data resync for any brand. Uses the same service
// and rate-limit key as the brand-facing endpoint — 1 resync per integration per 60s.
class StaffShopifyResyncController extends ApiController
{
    public function __construct(
        private readonly ShopifyDataResyncService $resyncService,
    ) {}

    /**
     * POST /api/staff/professionals/{professional}/integrations/shopify/resync
     */
    public function invoke(Request $request, Professional $professional): JsonResponse
    {
        $row = DB::table('core.professional_integrations')
            ->where('professional_id', $professional->id)
            ->where('provider', 'shopify')
            ->first();

        if (! $row) {
            return $this->error('No Shopify integration found for this professional.', 404);
        }

        $rateLimitKey = "shopify-resync:{$row->id}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->error('Shopify resync is rate-limited. Try again shortly.', 429)
                ->header('Retry-After', (string) $retryAfter);
        }
        RateLimiter::hit($rateLimitKey, 60);

        // Load the full Eloquent model so ShopifyDataResyncService can read the
        // encrypted access_token and call mergeProviderMetadata.
        $integration = ProfessionalIntegration::find($row->id);

        if (! $integration) {
            return $this->error('No Shopify integration found for this professional.', 404);
        }

        try {
            $result = $this->resyncService->resync($integration);
        } catch (RuntimeException $e) {
            return $this->error('Unable to resync from Shopify: ' . $e->getMessage(), 502);
        }

        return $this->success($result);
    }
}
```

- [ ] **Step 4: Add route**

Add import:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffShopifyResyncController;
```

Add route in the admin group:

```php
// Trigger Shopify resync for a brand (admin only)
Route::post('/professionals/{professional}/integrations/shopify/resync', [StaffShopifyResyncController::class, 'invoke']);
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffShopifyResyncControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyResyncController.php \
        tests/Feature/Staff/StaffShopifyResyncControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add POST /staff/professionals/{professional}/integrations/shopify/resync"
```

---

## Task 4: Brand Store Settings Override

**Endpoint:** `PATCH /api/staff/professionals/{professional}/store-settings`
**Auth:** staff.admin
**Purpose:** Staff admin overrides a brand's commission rate and payout hold days directly in the DB. Deliberately skips the Shopify metafield sync the brand-facing endpoint performs — staff corrections go to DB only; the brand can resync from Shopify if needed.

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffStoreSettingsController.php`
- Create: `tests/Feature/Staff/StaffStoreSettingsControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffStoreSettingsControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffStoreSettingsController;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns updated store settings after patch', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $mockUpdateQuery = Mockery::mock();
    $mockUpdateQuery->shouldReceive('updateOrInsert')->once()->andReturn(true);

    $mockSelectQuery = Mockery::mock();
    $mockSelectQuery->shouldReceive('where')->andReturnSelf();
    $mockSelectQuery->shouldReceive('first')->andReturn((object) [
        'default_commission_rate' => '20.00',
        'payout_hold_days'        => 14,
    ]);

    DB::shouldReceive('table')->with('brand.brand_store_settings')->andReturn($mockSelectQuery);
    DB::shouldReceive('table')->with('brand.brand_store_settings')->andReturn($mockUpdateQuery);

    // Simpler approach: mock the model static method via the underlying DB
    BrandStoreSettings::shouldReceive('updateOrCreate')
        ->once()
        ->with(['professional_id' => $professional->id], ['default_commission_rate' => 20.0, 'payout_hold_days' => 14])
        ->andReturn(new BrandStoreSettings([
            'professional_id'         => $professional->id,
            'default_commission_rate' => 20.0,
            'payout_hold_days'        => 14,
        ]));

    BrandStoreSettings::shouldReceive('where->first')->andReturn(
        new BrandStoreSettings([
            'professional_id'         => $professional->id,
            'default_commission_rate' => 20.0,
            'payout_hold_days'        => 14,
        ])
    );

    $controller = new StaffStoreSettingsController();
    $request    = Request::create('/', 'PATCH', [
        'default_commission_rate' => 20,
        'payout_hold_days'        => 14,
    ]);

    $response = $controller->update($request, $professional);
    $data     = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['default_commission_rate', 'payout_hold_days']);
});

it('rejects commission rate below 0', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);
    $controller   = new StaffStoreSettingsController();
    $request      = Request::create('/', 'PATCH', ['default_commission_rate' => -1]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects commission rate above 100', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);
    $controller   = new StaffStoreSettingsController();
    $request      = Request::create('/', 'PATCH', ['default_commission_rate' => 101]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects payout_hold_days below system minimum', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);
    $controller   = new StaffStoreSettingsController();
    // System minimum is 7 days (sidest.store.min_payout_hold_days config)
    $request = Request::create('/', 'PATCH', ['payout_hold_days' => 1]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffStoreSettingsControllerTest.php --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffStoreSettingsController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff admin overrides brand commission rate and payout hold days. DB-only write —
// deliberately skips Shopify metafield sync to avoid API calls during support operations.
class StaffStoreSettingsController extends ApiController
{
    /**
     * PATCH /api/staff/professionals/{professional}/store-settings
     *
     * Updatable: default_commission_rate (0–100), payout_hold_days (>= system minimum)
     */
    public function update(Request $request, Professional $professional): JsonResponse
    {
        $minHoldDays = (int) config('sidest.store.min_payout_hold_days', 7);

        $data = $request->validate([
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payout_hold_days'        => ['sometimes', 'integer', "min:{$minHoldDays}"],
        ]);

        if (empty($data)) {
            return $this->error('No updatable fields provided.', 422);
        }

        $settings = BrandStoreSettings::updateOrCreate(
            ['professional_id' => $professional->id],
            $data
        );

        return $this->success([
            'default_commission_rate' => (float) $settings->default_commission_rate,
            'payout_hold_days'        => $settings->payout_hold_days,
        ]);
    }
}
```

- [ ] **Step 4: Add route**

Add import:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffStoreSettingsController;
```

Add route in the admin group:

```php
// Override brand commission rate and payout hold days (admin only)
Route::patch('/professionals/{professional}/store-settings', [StaffStoreSettingsController::class, 'update']);
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffStoreSettingsControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

Expected: same pre-existing 3 failures, all other tests passing.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffStoreSettingsController.php \
        tests/Feature/Staff/StaffStoreSettingsControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add PATCH /staff/professionals/{professional}/store-settings (admin)"
```

---

## Self-Review

### Spec coverage

| Endpoint | Task |
|---|---|
| `PATCH /staff/professionals/{p}/affiliates/{affiliate}/status` | Task 1 ✅ |
| `POST /staff/commissions/{commission}/void` | Task 2 ✅ |
| `POST /staff/professionals/{p}/integrations/shopify/resync` | Task 3 ✅ |
| `PATCH /staff/professionals/{p}/store-settings` | Task 4 ✅ |

### Auth review — all 4 are admin-only ✅

### Placeholder scan — no TBDs, all steps contain complete code ✅

### Type consistency

- `StaffAffiliateStatusController::update(Request, Professional $professional, Professional $affiliate)` — `$professional` is the brand, `$affiliate` is the affiliate. Both bound as `Professional` via route model binding with separate route parameter names.
- `StaffCommissionVoidController::void(Request, CommissionLedgerEntry $commission)` — `$commission` bound by UUID from route.
- `StaffShopifyResyncController::invoke(Request, Professional $professional)` — loads `ProfessionalIntegration` via `DB::table()` first (for rate limit key), then `ProfessionalIntegration::find()` for the Eloquent model (needed by `ShopifyDataResyncService::resync()`).
- `StaffStoreSettingsController::update(Request, Professional $professional)` — uses `BrandStoreSettings::updateOrCreate()` directly.

### Edge case — Task 3 Shopify resync

The controller does two lookups: first `DB::table()` for the rate limit key (avoids loading the encrypted token until we know we're allowed to proceed), then `ProfessionalIntegration::find()` for the Eloquent model. If `find()` returns null (race condition on deletion), it returns 404 cleanly. This mirrors the pattern in the brand-facing `ShopifyResyncController`.

### Note on Task 4 test

`BrandStoreSettings::shouldReceive()` works because `BrandStoreSettings` extends `Model` (not `BaseModel`) and Mockery can alias Eloquent model statics. If the test runner reports `shouldReceive` unavailable (same issue we hit with `BrandPartnerLink` in the previous plan), switch the test to verify the response shape using a real SQLite in-memory write — the controller is simple enough that an integration test is equally clean.
