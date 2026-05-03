# Staff API — Easy Endpoints Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 7 read/write staff API endpoints covering stats, affiliates, commissions, payouts, integrations, invites, and brand profile editing — all following established patterns with no schema changes.

**Architecture:** Each endpoint is a focused controller in `app/Http/Controllers/Api/Staff/`, wired into `routes/api/staff.php`. Read endpoints go under the existing `staff` middleware group; the brand profile patch and invite cancel go under `staff.admin`. All controllers extend `ApiController` and use its `success()`, `error()`, and `paginated()` helpers.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL (Supabase), Pest 4 tests (SQLite in-memory), existing models with no schema changes required.

---

## File Map

**New controllers (create):**
- `app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffPayoutListController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffIntegrationController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffInviteController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandProfileController.php`

**New tests (create):**
- `tests/Feature/Staff/StaffStatsControllerTest.php`
- `tests/Feature/Staff/StaffAffiliateControllerTest.php`
- `tests/Feature/Staff/StaffCommissionControllerTest.php`
- `tests/Feature/Staff/StaffPayoutListControllerTest.php`
- `tests/Feature/Staff/StaffIntegrationControllerTest.php`
- `tests/Feature/Staff/StaffInviteControllerTest.php`
- `tests/Feature/Staff/StaffBrandProfileControllerTest.php`

**Modify:**
- `routes/api/staff.php` — add routes for all 7 endpoint groups

---

## Task 1: Platform Stats Endpoint

**Endpoint:** `GET /api/staff/stats`
**Auth:** staff (read)
**Purpose:** Single aggregation call for the ops dashboard — counts of brands, influencers, professionals, active subscriptions, and pending commission total.

**Files:**
- Create: `app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php`
- Create: `tests/Feature/Staff/StaffStatsControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffStatsControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffStatsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('returns correct shape with zero data', function () {
    DB::shouldReceive('table')->with('core.professionals')->andReturn(
        tap(Mockery::mock(), function ($m) {
            $m->shouldReceive('selectRaw')->andReturnSelf();
            $m->shouldReceive('groupBy')->andReturnSelf();
            $m->shouldReceive('pluck')->andReturn(collect());
        })
    );
    DB::shouldReceive('table')->with('billing.subscriptions')->andReturn(
        tap(Mockery::mock(), function ($m) {
            $m->shouldReceive('whereNull')->andReturnSelf();
            $m->shouldReceive('count')->andReturn(0);
        })
    );
    DB::shouldReceive('table')->with('commerce.commission_ledger_entries')->andReturn(
        tap(Mockery::mock(), function ($m) {
            $m->shouldReceive('where')->andReturnSelf();
            $m->shouldReceive('sum')->andReturn(0);
        })
    );

    $controller = new StaffStatsController();
    $request = Request::create('/', 'GET');

    $response = $controller->show($request);
    $data = json_decode($response->getContent(), true);

    expect($data)->toHaveKeys(['professionals', 'subscriptions', 'commissions'])
        ->and($data['professionals'])->toHaveKeys(['brands', 'influencers', 'professionals', 'total'])
        ->and($data['subscriptions'])->toHaveKey('active_count')
        ->and($data['commissions'])->toHaveKey('pending_cents');
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffStatsControllerTest.php --no-coverage
```

Expected: FAIL — `StaffStatsController` does not exist.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Platform-wide stats for the staff ops dashboard. Single aggregation call — counts by professional_type, active subscriptions, pending commissions.
class StaffStatsController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $typeCounts = DB::table('core.professionals')
            ->selectRaw('professional_type, count(*) as total')
            ->groupBy('professional_type')
            ->pluck('total', 'professional_type');

        $activeSubscriptions = DB::table('billing.subscriptions')
            ->whereNull('ended_at')
            ->count();

        $pendingCommissionCents = DB::table('commerce.commission_ledger_entries')
            ->where('status', 'pending')
            ->sum('amount_cents');

        $brands        = (int) ($typeCounts->get('brand') ?? 0);
        $influencers   = (int) ($typeCounts->get('influencer') ?? 0);
        $professionals = (int) ($typeCounts->get('professional') ?? 0);

        return $this->success([
            'professionals' => [
                'brands'        => $brands,
                'influencers'   => $influencers,
                'professionals' => $professionals,
                'total'         => $brands + $influencers + $professionals,
            ],
            'subscriptions' => [
                'active_count' => (int) $activeSubscriptions,
            ],
            'commissions' => [
                'pending_cents' => (int) $pendingCommissionCents,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Add route to `routes/api/staff.php`**

In the first (read) route group, after `Route::get('/me', ...)`, add:

```php
// Platform-wide stats
Route::get('/stats', [StaffStatsController::class, 'show']);
```

Also add the import at the top of the file:

```php
use App\Http\Controllers\Api\Staff\StaffSite\StaffStatsController;
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffStatsControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite to confirm no regressions**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php \
        tests/Feature/Staff/StaffStatsControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add GET /staff/stats platform ops dashboard endpoint"
```

---

## Task 2: List Affiliates for a Brand

**Endpoint:** `GET /api/staff/professionals/{professional}/affiliates`
**Auth:** staff (read)
**Purpose:** Staff views all affiliates connected to a brand, including their status and connection date.

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateController.php`
- Create: `tests/Feature/Staff/StaffAffiliateControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffAffiliateControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateController;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns empty affiliates list when brand has no links', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);

    BrandPartnerLink::shouldReceive('query->where->with->orderByDesc->get')
        ->andReturn(collect());

    $controller = new StaffAffiliateController();
    $request = Request::create('/', 'GET');

    $response = $controller->index($request, $brand);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['affiliates'])->toBe([]);
});

it('returns affiliate summary shape', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliateId = (string) Str::uuid();

    $affiliateProfessional = new Professional([
        'id'                => $affiliateId,
        'first_name'        => 'Sarah',
        'last_name'         => 'Jones',
        'display_name'      => 'Sarah Jones',
        'handle'            => 'sarah',
        'professional_type' => 'influencer',
        'status'            => 'active',
        'primary_email'     => 'sarah@example.com',
        'phone'             => null,
    ]);

    $link = new BrandPartnerLink([
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id'     => $brand->id,
        'slot'                      => 0,
        'custom_photos_enabled'     => true,
    ]);
    $link->updated_at = now();
    $link->setRelation('affiliateProfessional', $affiliateProfessional);

    BrandPartnerLink::shouldReceive('query->where->with->orderByDesc->get')
        ->andReturn(collect([$link]));

    $controller = new StaffAffiliateController();
    $request = Request::create('/', 'GET');

    $response = $controller->index($request, $brand);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['affiliates'])->toHaveCount(1)
        ->and($data['affiliates'][0])->toHaveKeys([
            'id', 'full_name', 'handle', 'status', 'email',
            'is_primary', 'custom_photos_enabled', 'connected_at',
        ]);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffAffiliateControllerTest.php --no-coverage
```

Expected: FAIL — controller does not exist.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandPartnerLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff views affiliates linked to a brand. Read-only — disconnect is a brand-level action and out of scope here.
class StaffAffiliateController extends ApiController
{
    /**
     * GET /api/staff/professionals/{professional}/affiliates
     *
     * @return JsonResponse{ affiliates: array<int, array{id: string, full_name: string, handle: string, status: string, email: string|null, is_primary: bool, custom_photos_enabled: bool, connected_at: string|null}> }
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $links = BrandPartnerLink::query()
            ->where('brand_professional_id', $professional->id)
            ->with('affiliateProfessional')
            ->orderByDesc('updated_at')
            ->get();

        $affiliates = $links
            ->map(function (BrandPartnerLink $link): ?array {
                $p = $link->affiliateProfessional;
                if (! $p) {
                    return null;
                }

                $fullName = trim(implode(' ', array_filter([$p->first_name, $p->last_name])));

                return [
                    'id'                    => $p->id,
                    'full_name'             => $fullName ?: ($p->display_name ?? $p->handle ?? 'Unknown'),
                    'handle'                => $p->handle,
                    'professional_type'     => $p->professional_type,
                    'status'                => $p->status,
                    'email'                 => $p->primary_email ?? $p->public_contact_email,
                    'phone'                 => $p->phone ?? $p->public_contact_number,
                    'is_primary'            => (int) $link->slot === BrandPartnerLinkService::PRIMARY_SLOT,
                    'custom_photos_enabled' => (bool) $link->custom_photos_enabled,
                    'connected_at'          => $link->updated_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return $this->success(['affiliates' => $affiliates]);
    }
}
```

- [ ] **Step 4: Add route**

In `routes/api/staff.php`, add import and route inside the read group after the `/professionals/{professional}/customers` block:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateController;
```

```php
// View affiliates linked to a brand
Route::get('/professionals/{professional}/affiliates', [StaffAffiliateController::class, 'index']);
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffAffiliateControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffAffiliateController.php \
        tests/Feature/Staff/StaffAffiliateControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add GET /staff/professionals/{professional}/affiliates"
```

---

## Task 3: List Commission Ledger Entries for a Professional

**Endpoint:** `GET /api/staff/professionals/{professional}/commissions?status=pending&per_page=25`
**Auth:** staff (read)
**Purpose:** Staff views the commission ledger for any professional — works for both brands (brand_professional_id) and affiliates (affiliate_professional_id).

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionController.php`
- Create: `tests/Feature/Staff/StaffCommissionControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffCommissionControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionController;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns paginated commissions for a professional', function () {
    $professional = new Professional([
        'id'                => (string) Str::uuid(),
        'professional_type' => 'brand',
    ]);

    $mockPaginator = Mockery::mock(\Illuminate\Pagination\LengthAwarePaginator::class);
    $mockPaginator->shouldReceive('items')->andReturn([]);
    $mockPaginator->shouldReceive('currentPage')->andReturn(1);
    $mockPaginator->shouldReceive('perPage')->andReturn(25);
    $mockPaginator->shouldReceive('total')->andReturn(0);
    $mockPaginator->shouldReceive('lastPage')->andReturn(1);
    $mockPaginator->shouldReceive('nextPageUrl')->andReturn(null);
    $mockPaginator->shouldReceive('previousPageUrl')->andReturn(null);

    CommissionLedgerEntry::shouldReceive('query->where->orWhere->orderByDesc->paginate')
        ->andReturn($mockPaginator);

    $controller = new StaffCommissionController();
    $request = Request::create('/', 'GET');

    $response = $controller->index($request, $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKey('data')
        ->and($data)->toHaveKey('meta');
});

it('filters commissions by status when provided', function () {
    $professional = new Professional([
        'id'                => (string) Str::uuid(),
        'professional_type' => 'influencer',
    ]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('orWhere')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();

    $mockPaginator = Mockery::mock(\Illuminate\Pagination\LengthAwarePaginator::class);
    $mockPaginator->shouldReceive('items')->andReturn([]);
    $mockPaginator->shouldReceive('currentPage')->andReturn(1);
    $mockPaginator->shouldReceive('perPage')->andReturn(25);
    $mockPaginator->shouldReceive('total')->andReturn(0);
    $mockPaginator->shouldReceive('lastPage')->andReturn(1);
    $mockPaginator->shouldReceive('nextPageUrl')->andReturn(null);
    $mockPaginator->shouldReceive('previousPageUrl')->andReturn(null);
    $mockQuery->shouldReceive('paginate')->andReturn($mockPaginator);

    CommissionLedgerEntry::shouldReceive('query')->andReturn($mockQuery);

    $controller = new StaffCommissionController();
    $request = Request::create('/', 'GET', ['status' => 'pending']);

    $response = $controller->index($request, $professional);

    expect($response->status())->toBe(200);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffCommissionControllerTest.php --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff views the commission ledger for any professional. Queries both brand and affiliate sides so it works regardless of the professional's type.
class StaffCommissionController extends ApiController
{
    use NormalizesPerPage;

    /**
     * GET /api/staff/professionals/{professional}/commissions
     *
     * Query params: status (pending|approved|reversed|voided), per_page (default 25, max 100)
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $perPage = $this->normalizePerPage($request, 25, 100);
        $status  = $request->query('status');

        $query = CommissionLedgerEntry::query()
            ->where(function ($q) use ($professional): void {
                $q->where('brand_professional_id', $professional->id)
                    ->orWhere('affiliate_professional_id', $professional->id);
            })
            ->orderByDesc('occurred_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage, [
            'id', 'shopify_order_id', 'brand_professional_id', 'affiliate_professional_id',
            'entry_type', 'status', 'amount_cents', 'currency_code',
            'commission_rate', 'rate_source', 'occurred_at', 'payout_id',
            'voided_at', 'void_reason',
        ]);

        return $this->paginated($paginator);
    }
}
```

- [ ] **Step 4: Add route**

In `routes/api/staff.php`, add import and route in the read group:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionController;
```

```php
// View commission ledger for a professional (brand or affiliate)
Route::get('/professionals/{professional}/commissions', [StaffCommissionController::class, 'index']);
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffCommissionControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionController.php \
        tests/Feature/Staff/StaffCommissionControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add GET /staff/professionals/{professional}/commissions"
```

---

## Task 4: List All Payouts (Global)

**Endpoint:** `GET /api/staff/commission-payouts?status=failed&per_page=25`
**Auth:** staff (read)
**Purpose:** Staff lists and filters commission payouts platform-wide. Complements the existing retry endpoint.

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionPayoutController.php`
- Create: `tests/Feature/Staff/StaffPayoutListControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffPayoutListControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionPayoutController;
use App\Models\Retail\CommissionPayout;
use Illuminate\Http\Request;

it('returns paginated payouts', function () {
    $mockPaginator = Mockery::mock(\Illuminate\Pagination\LengthAwarePaginator::class);
    $mockPaginator->shouldReceive('items')->andReturn([]);
    $mockPaginator->shouldReceive('currentPage')->andReturn(1);
    $mockPaginator->shouldReceive('perPage')->andReturn(25);
    $mockPaginator->shouldReceive('total')->andReturn(0);
    $mockPaginator->shouldReceive('lastPage')->andReturn(1);
    $mockPaginator->shouldReceive('nextPageUrl')->andReturn(null);
    $mockPaginator->shouldReceive('previousPageUrl')->andReturn(null);

    CommissionPayout::shouldReceive('query->orderByDesc->paginate')->andReturn($mockPaginator);

    $controller = app(StaffCommissionPayoutController::class);
    $request = Request::create('/', 'GET');

    $response = $controller->index($request);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['data', 'meta']);
});

it('filters payouts by status', function () {
    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->with('status', 'failed')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();

    $mockPaginator = Mockery::mock(\Illuminate\Pagination\LengthAwarePaginator::class);
    $mockPaginator->shouldReceive('items')->andReturn([]);
    $mockPaginator->shouldReceive('currentPage')->andReturn(1);
    $mockPaginator->shouldReceive('perPage')->andReturn(25);
    $mockPaginator->shouldReceive('total')->andReturn(0);
    $mockPaginator->shouldReceive('lastPage')->andReturn(1);
    $mockPaginator->shouldReceive('nextPageUrl')->andReturn(null);
    $mockPaginator->shouldReceive('previousPageUrl')->andReturn(null);
    $mockQuery->shouldReceive('paginate')->andReturn($mockPaginator);

    CommissionPayout::shouldReceive('query')->andReturn($mockQuery);

    $controller = app(StaffCommissionPayoutController::class);
    $request = Request::create('/', 'GET', ['status' => 'failed']);

    $response = $controller->index($request);

    expect($response->status())->toBe(200);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffPayoutListControllerTest.php --no-coverage
```

Expected: FAIL — `index` method does not exist on `StaffCommissionPayoutController`.

- [ ] **Step 3: Add `index` method to the existing payout controller**

Open `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionPayoutController.php` and add after the class-level comment, add the missing use imports and new method:

```php
use App\Http\Controllers\Concerns\NormalizesPerPage;
```

Add the trait in the class body:

```php
use NormalizesPerPage;
```

Add the `index` method before `retry()`:

```php
/**
 * GET /staff/commission-payouts
 *
 * List all payouts. Query params: status (pending|processing|completed|failed|collecting|collected|transferring), per_page (default 25, max 100).
 *
 * @return JsonResponse paginated CommissionPayout records
 */
public function index(Request $request): JsonResponse
{
    $perPage = $this->normalizePerPage($request, 25, 100);
    $status  = $request->query('status');

    $query = CommissionPayout::query()->orderByDesc('created_at');

    if (is_string($status) && $status !== '') {
        $query->where('status', $status);
    }

    $paginator = $query->paginate($perPage, [
        'id', 'brand_professional_id', 'affiliate_professional_id',
        'status', 'gross_commission_cents', 'platform_fee_cents',
        'net_payout_cents', 'currency_code', 'failure_code', 'failure_reason',
        'ledger_entry_count', 'eligible_after', 'processed_at', 'created_at',
    ]);

    return $this->paginated($paginator);
}
```

- [ ] **Step 4: Add route**

In `routes/api/staff.php`, in the read group, add:

```php
// List all payouts platform-wide
Route::get('/commission-payouts', [StaffCommissionPayoutController::class, 'index']);
```

(`StaffCommissionPayoutController` is already imported at the top of the file.)

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffPayoutListControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionPayoutController.php \
        tests/Feature/Staff/StaffPayoutListControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add GET /staff/commission-payouts payout listing endpoint"
```

---

## Task 5: View Integration Status for a Professional

**Endpoint:** `GET /api/staff/professionals/{professional}/integrations`
**Auth:** staff (read)
**Purpose:** Staff sees which third-party integrations (Shopify, Square, Fresha) a professional has connected and when they last synced. Sensitive tokens are hidden by the model.

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffIntegrationController.php`
- Create: `tests/Feature/Staff/StaffIntegrationControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffIntegrationControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffIntegrationController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns empty integrations when none exist', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);
    $professional->setRelation('integrations', collect());

    $controller = new StaffIntegrationController();
    $request = Request::create('/', 'GET');

    $response = $controller->index($request, $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['integrations'])->toBe([]);
});

it('returns integration shape without sensitive fields', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $integration = new ProfessionalIntegration([
        'id'                   => (string) Str::uuid(),
        'professional_id'      => $professional->id,
        'provider'             => 'shopify',
        'external_account_id'  => 'mystore.myshopify.com',
        'last_catalog_sync_at' => now(),
        'last_catalog_sync_error' => null,
        'expires_at'           => null,
    ]);
    // Ensure hidden fields are not exposed
    $professional->setRelation('integrations', collect([$integration]));

    $controller = new StaffIntegrationController();
    $request = Request::create('/', 'GET');

    $response = $controller->index($request, $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['integrations'])->toHaveCount(1)
        ->and($data['integrations'][0])->toHaveKeys([
            'id', 'provider', 'external_account_id',
            'last_catalog_sync_at', 'last_catalog_sync_error', 'expires_at',
        ])
        ->and($data['integrations'][0])->not->toHaveKey('access_token')
        ->and($data['integrations'][0])->not->toHaveKey('refresh_token');
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffIntegrationControllerTest.php --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffIntegrationController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff views third-party integration status for any professional. Tokens are hidden by the model — this endpoint is safe to return in full.
class StaffIntegrationController extends ApiController
{
    /**
     * GET /api/staff/professionals/{professional}/integrations
     *
     * @return JsonResponse{ integrations: array<int, array{id: string, provider: string, external_account_id: string|null, last_catalog_sync_at: string|null, last_catalog_sync_error: string|null, expires_at: string|null}> }
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $integrations = $professional->integrations()
            ->get(['id', 'provider', 'external_account_id', 'last_catalog_sync_at', 'last_catalog_sync_error', 'expires_at', 'created_at'])
            ->map(fn (ProfessionalIntegration $i): array => [
                'id'                      => $i->id,
                'provider'                => $i->provider,
                'external_account_id'     => $i->external_account_id,
                'last_catalog_sync_at'    => $i->last_catalog_sync_at?->toIso8601String(),
                'last_catalog_sync_error' => $i->last_catalog_sync_error,
                'expires_at'              => $i->expires_at?->toIso8601String(),
                'connected_at'            => $i->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return $this->success(['integrations' => $integrations]);
    }
}
```

- [ ] **Step 4: Add route**

In `routes/api/staff.php`, add import and route in the read group:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffIntegrationController;
```

```php
// View integration status for a professional
Route::get('/professionals/{professional}/integrations', [StaffIntegrationController::class, 'index']);
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffIntegrationControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffIntegrationController.php \
        tests/Feature/Staff/StaffIntegrationControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add GET /staff/professionals/{professional}/integrations"
```

---

## Task 6: View and Cancel Affiliate Invites

**Endpoints:**
- `GET /api/staff/professionals/{professional}/invites?status=pending` (staff read)
- `DELETE /api/staff/professionals/{professional}/invites/{invite}` (staff admin — expires invite)

**Purpose:** Staff views pending/historical invites for a brand and can expire a stuck invite without deleting the record.

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffInviteController.php`
- Create: `tests/Feature/Staff/StaffInviteControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffInviteControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffInviteController;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns paginated invites for a brand', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $mockPaginator = Mockery::mock(\Illuminate\Pagination\LengthAwarePaginator::class);
    $mockPaginator->shouldReceive('items')->andReturn([]);
    $mockPaginator->shouldReceive('currentPage')->andReturn(1);
    $mockPaginator->shouldReceive('perPage')->andReturn(25);
    $mockPaginator->shouldReceive('total')->andReturn(0);
    $mockPaginator->shouldReceive('lastPage')->andReturn(1);
    $mockPaginator->shouldReceive('nextPageUrl')->andReturn(null);
    $mockPaginator->shouldReceive('previousPageUrl')->andReturn(null);

    BrandAffiliateInvite::shouldReceive('query->where->orderByDesc->paginate')
        ->andReturn($mockPaginator);

    $controller = new StaffInviteController();
    $request = Request::create('/', 'GET');

    $response = $controller->index($request, $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['data', 'meta']);
});

it('expires a pending invite', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $invite = Mockery::mock(BrandAffiliateInvite::class)->makePartial();
    $invite->id = (string) Str::uuid();
    $invite->brand_professional_id = $professional->id;
    $invite->status = 'pending';
    $invite->shouldReceive('save')->once();

    $controller = new StaffInviteController();
    $request = Request::create('/', 'DELETE');

    $response = $controller->cancel($request, $professional, $invite);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['status'])->toBe('expired');
});

it('returns 422 when trying to cancel an already accepted invite', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $invite = new BrandAffiliateInvite([
        'id'                    => (string) Str::uuid(),
        'brand_professional_id' => $professional->id,
        'status'                => 'accepted',
    ]);

    $controller = new StaffInviteController();
    $request = Request::create('/', 'DELETE');

    $response = $controller->cancel($request, $professional, $invite);

    expect($response->status())->toBe(422);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffInviteControllerTest.php --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffInviteController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff views and cancels affiliate invites for a brand. Cancel marks status 'expired' to preserve audit trail — does not hard-delete.
class StaffInviteController extends ApiController
{
    use NormalizesPerPage;

    /**
     * GET /api/staff/professionals/{professional}/invites
     *
     * Query params: status (pending|accepted|declined|expired), per_page (default 25)
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $perPage = $this->normalizePerPage($request, 25, 100);
        $status  = $request->query('status');

        $query = BrandAffiliateInvite::query()
            ->where('brand_professional_id', $professional->id)
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage, [
            'id', 'status', 'invite_type', 'email', 'first_name', 'last_name',
            'claimed_professional_id', 'accepted_at', 'expires_at', 'created_at',
        ]);

        return $this->paginated($paginator);
    }

    /**
     * DELETE /api/staff/professionals/{professional}/invites/{invite}
     *
     * Expires a pending or declined invite. Cannot cancel accepted invites.
     */
    public function cancel(Request $request, Professional $professional, BrandAffiliateInvite $invite): JsonResponse
    {
        if ($invite->status === 'accepted') {
            return $this->error('Cannot cancel an accepted invite.', 422);
        }

        if ($invite->status === 'expired') {
            return $this->success(['id' => $invite->id, 'status' => 'expired']);
        }

        $invite->status = 'expired';
        $invite->save();

        return $this->success(['id' => $invite->id, 'status' => 'expired']);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/api/staff.php`, add import and read route in the read group:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffInviteController;
```

Read group:
```php
// View invites for a brand
Route::get('/professionals/{professional}/invites', [StaffInviteController::class, 'index']);
```

Admin group (the second `Route::group` block with `staff.admin` middleware):
```php
// Expire a stuck invite (admin only)
Route::delete('/professionals/{professional}/invites/{invite}', [StaffInviteController::class, 'cancel'])
    ->whereUuid('invite');
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffInviteControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffInviteController.php \
        tests/Feature/Staff/StaffInviteControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add invite list + cancel endpoints for brand affiliate invites"
```

---

## Task 7: Edit Brand Profile (Admin Only)

**Endpoint:** `PATCH /api/staff/professionals/{professional}/brand-profile`
**Auth:** staff.admin
**Purpose:** Staff admin updates operational fields on a brand's profile — status, visibility, setup completion, and business details. Does not touch BrandStoreSettings (commission rate has its own endpoint).

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandProfileController.php`
- Create: `tests/Feature/Staff/StaffBrandProfileControllerTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffBrandProfileControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandProfileController;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns 404 when brand has no brand profile', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);
    $professional->setRelation('brandProfile', null);

    $controller = new StaffBrandProfileController();
    $request = Request::create('/', 'PATCH', [
        'brand_status' => 'active',
    ]);

    $response = $controller->update($request, $professional);

    expect($response->status())->toBe(404);
});

it('updates allowed brand profile fields', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $profile = Mockery::mock(BrandProfile::class)->makePartial();
    $profile->professional_id   = $professional->id;
    $profile->brand_status      = 'pending';
    $profile->affiliate_visibility = 'invite_only';
    $profile->setup_complete    = false;
    $profile->legal_business_name = null;
    $profile->abn               = null;
    $profile->acn               = null;
    $profile->business_website  = null;
    $profile->shouldReceive('save')->once();

    $professional->setRelation('brandProfile', $profile);

    $controller = new StaffBrandProfileController();
    $request = Request::create('/', 'PATCH', [
        'brand_status'         => 'active',
        'affiliate_visibility' => 'public',
        'setup_complete'       => true,
        'legal_business_name'  => 'Cuts & Co Pty Ltd',
    ]);

    $response = $controller->update($request, $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKey('brand_profile');
});

it('rejects unknown brand_status values', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $profile = new BrandProfile([
        'professional_id' => $professional->id,
        'brand_status'    => 'active',
    ]);
    $professional->setRelation('brandProfile', $profile);

    $controller = new StaffBrandProfileController();
    $request = Request::create('/', 'PATCH', ['brand_status' => 'hacked']);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffBrandProfileControllerTest.php --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandProfileController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff admin updates a brand's profile fields. Only operational fields are exposed — sensitive financial config stays in BrandStoreSettings.
class StaffBrandProfileController extends ApiController
{
    /**
     * PATCH /api/staff/professionals/{professional}/brand-profile
     *
     * Updatable fields: brand_status, affiliate_visibility, setup_complete,
     * legal_business_name, abn, acn, business_website
     */
    public function update(Request $request, Professional $professional): JsonResponse
    {
        $profile = $professional->brandProfile;

        if (! $profile) {
            return $this->error('This professional has no brand profile.', 404);
        }

        $data = $request->validate([
            'brand_status'         => ['sometimes', 'nullable', 'string', 'in:pending,active,suspended,rejected'],
            'affiliate_visibility' => ['sometimes', 'nullable', 'string', 'in:public,invite_only'],
            'setup_complete'       => ['sometimes', 'nullable', 'boolean'],
            'legal_business_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'abn'                  => ['sometimes', 'nullable', 'string', 'max:20'],
            'acn'                  => ['sometimes', 'nullable', 'string', 'max:20'],
            'business_website'     => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        foreach ($data as $field => $value) {
            $profile->{$field} = $value;
        }
        $profile->save();

        return $this->success([
            'brand_profile' => [
                'id'                   => $profile->id,
                'brand_status'         => $profile->brand_status,
                'affiliate_visibility' => $profile->affiliate_visibility,
                'setup_complete'       => (bool) $profile->setup_complete,
                'legal_business_name'  => $profile->legal_business_name,
                'abn'                  => $profile->abn,
                'acn'                  => $profile->acn,
                'business_website'     => $profile->business_website,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Add route**

In `routes/api/staff.php`, add import and route in the admin group:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandProfileController;
```

Admin group:
```php
// Edit brand profile (admin only)
Route::patch('/professionals/{professional}/brand-profile', [StaffBrandProfileController::class, 'update']);
```

- [ ] **Step 5: Run test to confirm it passes**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && php artisan test tests/Feature/Staff/StaffBrandProfileControllerTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Run full suite**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend && composer test
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandProfileController.php \
        tests/Feature/Staff/StaffBrandProfileControllerTest.php \
        routes/api/staff.php
git commit -m "feat(staff): add PATCH /staff/professionals/{professional}/brand-profile (admin)"
```

---

## Self-Review

### Spec coverage

| Endpoint | Task |
|---|---|
| `GET /staff/stats` | Task 1 ✅ |
| `GET /staff/professionals/{p}/affiliates` | Task 2 ✅ |
| `GET /staff/professionals/{p}/commissions` | Task 3 ✅ |
| `GET /staff/commission-payouts` | Task 4 ✅ |
| `GET /staff/professionals/{p}/integrations` | Task 5 ✅ |
| `GET /staff/professionals/{p}/invites` | Task 6 ✅ |
| `DELETE /staff/professionals/{p}/invites/{invite}` | Task 6 ✅ |
| `PATCH /staff/professionals/{p}/brand-profile` | Task 7 ✅ |

### Auth review

| Endpoint | Middleware |
|---|---|
| All GETs | `staff` (read) |
| DELETE invite cancel | `staff.admin` |
| PATCH brand-profile | `staff.admin` |

### No placeholders — confirmed. All steps contain complete code.

### Type consistency — confirmed. `Professional`, `BrandPartnerLink`, `BrandAffiliateInvite`, `ProfessionalIntegration`, `CommissionLedgerEntry`, `CommissionPayout`, `BrandProfile` are all referenced by their actual namespaces and used consistently across tasks.
