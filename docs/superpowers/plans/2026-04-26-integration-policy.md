# Integration Policy + BasePolicy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the project's first Laravel auth Policy — `IntegrationPolicy` — gating `ProfessionalIntegration` access (Square, Fresha, Shopify OAuth credentials), backed by a reusable `BasePolicy` that enforces `pending_deletion` read-only at the policy layer (HTTP 423). Migrate `FreshaIntegrationController`, `SquareIntegrationController`, and `ShopifyIntegrationController` to use it.

**Architecture:**
- `app/Policies/BasePolicy.php` — abstract class providing `denyIfPendingDeletion(Professional): ?Response` returning `Response::denyWithStatus(423, …)`.
- `app/Policies/IntegrationPolicy.php` — extends `BasePolicy`. Two abilities: `view` (owner OR brand-team member with capability) and `manage` (same + pending_deletion guard). Delegates brand-team checks to `BrandAccessService::canManageShopify()`.
- Single bind in `AppServiceProvider::boot()`: `Gate::policy(ProfessionalIntegration::class, IntegrationPolicy::class)`.
- Controllers call `$this->authorize('view'|'manage', $integration)`. For "connect" (no model yet), use an unsaved `ProfessionalIntegration` skeleton with `professional_id` set so the policy can authorize on the target owner.
- Existing `EnforcePendingDeletionReadOnly` middleware stays — defense in depth.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Mockery, Supabase PostgreSQL (sqlite in tests via the pgsql redirect), `BrandAccessService` for RBAC.

---

## File Structure

**New files:**
- `app/Policies/BasePolicy.php` — abstract base for all future policies.
- `app/Policies/IntegrationPolicy.php` — first concrete policy.
- `tests/Unit/Policies/BasePolicyTest.php` — proves the pending-deletion helper.
- `tests/Unit/Policies/IntegrationPolicyTest.php` — `view` and `manage` matrix.
- `tests/Feature/Security/IntegrationPolicy/PolicyEnforcementTest.php` — end-to-end through the migrated controllers.

**Modified files:**
- `app/Http/Controllers/Controller.php` — add `AuthorizesRequests` trait so `$this->authorize()` works in every API controller.
- `app/Providers/AppServiceProvider.php` — register the policy in `boot()`.
- `app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php` — add policy calls on write actions.
- `app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php` — same.
- `app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php` — replace inline `canManageShopify` calls with `$this->authorize('manage', …)`.

---

## Task 1: Add `AuthorizesRequests` trait to base `Controller`

The base `app/Http/Controllers/Controller.php` is currently empty. Adding the `AuthorizesRequests` trait once at the root makes `$this->authorize()` available to every API controller without modifying each one. This is the idiomatic Laravel 12 placement.

**Files:**
- Modify: `app/Http/Controllers/Controller.php`

- [ ] **Step 1: Edit the base controller**

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

// V2: Base controller class. All API controllers extend this abstract class.
abstract class Controller
{
    use AuthorizesRequests;
}
```

- [ ] **Step 2: Confirm autoload still resolves**

Run: `php artisan about | head -5`
Expected: No fatal error; standard "Environment" / "Laravel Version" output.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Controller.php
git commit -m "chore(controllers): wire AuthorizesRequests trait into base Controller"
```

---

## Task 2: Create `BasePolicy` with `denyIfPendingDeletion()` helper (TDD)

**Files:**
- Test: `tests/Unit/Policies/BasePolicyTest.php`
- Create: `app/Policies/BasePolicy.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

// Concrete subclass purely for testing the protected helper.
class FakePolicy extends BasePolicy
{
    public function callDenyIfPendingDeletion(Professional $professional): ?Response
    {
        return $this->denyIfPendingDeletion($professional);
    }
}

it('returns null when the professional is active', function () {
    $pro = new Professional(['status' => 'active']);

    $result = (new FakePolicy())->callDenyIfPendingDeletion($pro);

    expect($result)->toBeNull();
});

it('returns a 423 deny response when the professional is pending deletion', function () {
    $pro = new Professional(['status' => 'pending_deletion']);

    $result = (new FakePolicy())->callDenyIfPendingDeletion($pro);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('returns null when the professional has any other status', function () {
    $pro = new Professional(['status' => 'suspended']);

    $result = (new FakePolicy())->callDenyIfPendingDeletion($pro);

    expect($result)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Policies/BasePolicyTest.php`
Expected: FAIL — class `App\Policies\BasePolicy` not found.

- [ ] **Step 3: Create `app/Policies/BasePolicy.php`**

```php
<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;

// V2: Base for all auth Policies. Provides the shared pending_deletion read-only
// guard. Concrete Policies call denyIfPendingDeletion() as the first line of any
// ability that mutates state — this mirrors the EnforcePendingDeletionReadOnly
// HTTP middleware so background jobs and console commands get the same gate.
abstract class BasePolicy
{
    /**
     * Returns a 423 deny Response when the actor's account is pending deletion,
     * otherwise null. Caller convention: any write-capable ability returns
     * this result early when non-null.
     */
    protected function denyIfPendingDeletion(Professional $professional): ?Response
    {
        if (($professional->status ?? null) === 'pending_deletion') {
            return Response::denyWithStatus(423, 'Account is pending deletion.');
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Policies/BasePolicyTest.php`
Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/BasePolicy.php tests/Unit/Policies/BasePolicyTest.php
git commit -m "feat(policies): add BasePolicy with pending_deletion 423 guard"
```

---

## Task 3: Create `IntegrationPolicy::view()` ability (TDD)

**Files:**
- Test: `tests/Unit/Policies/IntegrationPolicyTest.php`
- Create: `app/Policies/IntegrationPolicy.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Policies\IntegrationPolicy;
use App\Services\Store\BrandAccessService;

beforeEach(function () {
    $this->brandAccess = Mockery::mock(BrandAccessService::class);
    $this->policy = new IntegrationPolicy($this->brandAccess);
});

it('allows view when the actor owns the integration', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-1', 'provider' => 'fresha']);

    $this->brandAccess->shouldReceive('canManageShopify')->never();

    expect($this->policy->view($actor, $integration))->toBeTrue();
});

it('denies view when the actor does not own the integration and is not a brand team member', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-2', 'provider' => 'fresha']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'pro-2')
        ->andReturn(false);

    expect($this->policy->view($actor, $integration))->toBeFalse();
});

it('allows view when the actor is a brand team member with manage capability', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'brand-9', 'provider' => 'shopify']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'brand-9')
        ->andReturn(true);

    expect($this->policy->view($actor, $integration))->toBeTrue();
});

it('denies view when the integration has no professional_id', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => null, 'provider' => 'fresha']);

    expect($this->policy->view($actor, $integration))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Policies/IntegrationPolicyTest.php`
Expected: FAIL — class `App\Policies\IntegrationPolicy` not found.

- [ ] **Step 3: Create `app/Policies/IntegrationPolicy.php`**

```php
<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\Response;

// V2: Authorization for ProfessionalIntegration (Square / Fresha / Shopify
// OAuth credentials). Two abilities:
//
//   view   — actor owns the integration OR has brand-team manage capability
//            for the integration's owning professional.
//   manage — same as view, plus the actor must not be pending_deletion
//            (returns 423 via BasePolicy::denyIfPendingDeletion).
//
// We use a single 'manage' ability covering connect/disconnect/sync because
// BrandAccessService already buckets these into CAPABILITY_SHOPIFY_MANAGE.
// Split if a role ever needs "sync but not disconnect".
class IntegrationPolicy extends BasePolicy
{
    public function __construct(private readonly BrandAccessService $brandAccess) {}

    public function view(Professional $actor, ProfessionalIntegration $integration): bool
    {
        return $this->actorCanReachOwner($actor, $integration);
    }

    private function actorCanReachOwner(Professional $actor, ProfessionalIntegration $integration): bool
    {
        $ownerId = trim((string) ($integration->professional_id ?? ''));
        if ($ownerId === '') {
            return false;
        }

        if ((string) $actor->id === $ownerId) {
            return true;
        }

        return $this->brandAccess->canManageShopify($actor, $ownerId);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Policies/IntegrationPolicyTest.php`
Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/IntegrationPolicy.php tests/Unit/Policies/IntegrationPolicyTest.php
git commit -m "feat(policies): add IntegrationPolicy::view with brand-team delegation"
```

---

## Task 4: Add `IntegrationPolicy::manage()` ability (TDD)

**Files:**
- Modify: `tests/Unit/Policies/IntegrationPolicyTest.php` (append)
- Modify: `app/Policies/IntegrationPolicy.php`

- [ ] **Step 1: Append failing tests**

Add to the bottom of `tests/Unit/Policies/IntegrationPolicyTest.php`:

```php
it('allows manage when the actor owns the integration and is active', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-1', 'provider' => 'fresha']);

    expect($this->policy->manage($actor, $integration))->toBeTrue();
});

it('denies manage with a 423 deny response when the actor is pending deletion', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'pending_deletion', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-1', 'provider' => 'fresha']);

    $result = $this->policy->manage($actor, $integration);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('denies manage when the actor is not the owner and lacks brand-team capability', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-2', 'provider' => 'fresha']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'pro-2')
        ->andReturn(false);

    expect($this->policy->manage($actor, $integration))->toBeFalse();
});

it('allows manage when the actor is a brand team member with manage capability', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'brand-9', 'provider' => 'shopify']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'brand-9')
        ->andReturn(true);

    expect($this->policy->manage($actor, $integration))->toBeTrue();
});

it('denies manage on an unsaved integration skeleton when caller has no claim on the target owner', function () {
    $actor = new Professional(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    // "Connect" flow: caller passes an unsaved skeleton with professional_id set.
    $skeleton = new ProfessionalIntegration(['professional_id' => 'brand-9', 'provider' => 'shopify']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'brand-9')
        ->andReturn(false);

    expect($this->policy->manage($actor, $skeleton))->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Policies/IntegrationPolicyTest.php`
Expected: FAIL — `manage` method does not exist on `IntegrationPolicy`.

- [ ] **Step 3: Implement `manage()` in `app/Policies/IntegrationPolicy.php`**

Add this method to `IntegrationPolicy` (immediately below `view()`):

```php
    public function manage(Professional $actor, ProfessionalIntegration $integration): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return $this->actorCanReachOwner($actor, $integration);
    }
```

Update the imports at the top of `IntegrationPolicy.php` if `Response` is not already imported (it is — verify).

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Policies/IntegrationPolicyTest.php`
Expected: PASS — 9 tests total (4 view + 5 manage).

- [ ] **Step 5: Commit**

```bash
git add app/Policies/IntegrationPolicy.php tests/Unit/Policies/IntegrationPolicyTest.php
git commit -m "feat(policies): add IntegrationPolicy::manage with pending_deletion 423"
```

---

## Task 5: Register the policy in `AppServiceProvider::boot()`

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Edit `app/Providers/AppServiceProvider.php`**

Add the import at the top of the file (after existing `use` statements):

```php
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Policies\IntegrationPolicy;
use Illuminate\Support\Facades\Gate;
```

In the `boot()` method, add this **as the first statement** (above `$this->configureRateLimiting()`):

```php
        Gate::policy(ProfessionalIntegration::class, IntegrationPolicy::class);
```

Final shape of `boot()`'s opening lines:

```php
    public function boot(): void
    {
        Gate::policy(ProfessionalIntegration::class, IntegrationPolicy::class);

        $this->configureRateLimiting();

        // Scheduler heartbeat — feeds GET /api/health/scheduler so a stopped cron
        // runner becomes visible. See RecordScheduledTaskHeartbeat for rationale.
        Event::listen(ScheduledTaskStarting::class, RecordScheduledTaskHeartbeat::class);
        // …
    }
```

- [ ] **Step 2: Smoke-test policy resolution**

Run: `php artisan tinker --execute="echo get_class(\\Illuminate\\Support\\Facades\\Gate::getPolicyFor(\\App\\Models\\Core\\Professional\\ProfessionalIntegration::class));"`
Expected: `App\Policies\IntegrationPolicy`

- [ ] **Step 3: Run the full unit test suite**

Run: `./vendor/bin/pest tests/Unit/Policies`
Expected: PASS — 12 tests across both files.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "feat(policies): register IntegrationPolicy on ProfessionalIntegration"
```

---

## Task 6: Migrate `FreshaIntegrationController` (TDD via feature test)

The Fresha controller currently authorizes implicitly (looks up by `professional_id = currentProfessional()->id`). After migration, every write action calls `$this->authorize('manage', …)` so the policy is the source of truth.

**Files:**
- Create: `tests/Feature/Security/IntegrationPolicy/FreshaPolicyEnforcementTest.php`
- Modify: `app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

use App\Http\Controllers\Api\Professional\FreshaIntegration\FreshaIntegrationController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Fresha\FreshaServiceSyncService;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        provider_metadata TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // Default: BrandAccessService denies all cross-tenant manage attempts.
    $this->mock(BrandAccessService::class, function ($mock) {
        $mock->shouldReceive('canManageShopify')->andReturn(false);
        $mock->shouldReceive('isBrandProfessional')->andReturn(false);
    });
});

it('allows the owner to disconnect their own Fresha integration', function () {
    [$a] = createTwoTenants('professional');
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'fresha',
        'external_account_id' => 'biz-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($a, [], 'POST');
    $response = app(FreshaIntegrationController::class)->disconnect($req);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a pending_deletion owner from disconnecting Fresha with 423', function () {
    [$a] = createTwoTenants('professional');
    DB::connection('pgsql')->table('core.professionals')->where('id', $a->id)->update([
        'status' => 'pending_deletion',
    ]);
    $a->refresh();

    $now = now()->toDateTimeString();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'fresha',
        'external_account_id' => 'biz-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($a, [], 'POST');

    try {
        app(FreshaIntegrationController::class)->disconnect($req);
        expect(false)->toBeTrue('Expected AuthorizationException with 423 status');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

it('blocks one tenant from triggering Fresha sync against another tenants integration', function () {
    [$a, $b] = createTwoTenants('professional');
    $now = now()->toDateTimeString();

    // Tenant A has a Fresha integration; tenant B should not be able to act on it.
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'fresha',
        'external_account_id' => 'biz-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Tenant B calls syncServicesNow — they have no Fresha integration of their own.
    $req = tenantRequestAs($b, [], 'POST');
    $sync = Mockery::mock(FreshaServiceSyncService::class);
    $sync->shouldReceive('syncFromFresha')->never();

    $response = app(FreshaIntegrationController::class)->syncServicesNow($req, $sync);

    // Tenant B has no integration of their own → 404 not connected, never reaches the policy.
    expect($response->getStatusCode())->toBe(404);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Security/IntegrationPolicy/FreshaPolicyEnforcementTest.php`
Expected: FAIL — the pending_deletion test will not throw `AuthorizationException` because the policy is not yet wired in the controller.

- [ ] **Step 3: Update `FreshaIntegrationController` to call the policy**

Open `app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php`.

Add this private helper inside the class (place it right after `ensureFreshaConnected`):

```php
    /**
     * Build an unsaved ProfessionalIntegration carrying just professional_id +
     * provider. Used for connect-style policy checks where no row exists yet.
     */
    private function freshaSkeletonFor(Professional $pro): ProfessionalIntegration
    {
        return new ProfessionalIntegration([
            'professional_id' => $pro->id,
            'provider' => ProfessionalIntegration::PROVIDER_FRESHA,
        ]);
    }
```

Add the `Professional` import at the top of the file:

```php
use App\Models\Core\Professional\Professional;
```

In `connect()`, immediately after `$pro = $this->currentProfessional($request);` (currently line 102), add:

```php
        $this->authorize('manage', $this->freshaSkeletonFor($pro));
```

In `disconnect()`, immediately after `$pro = $this->currentProfessional($request);` (currently line 170), add:

```php
        $this->authorize('manage', $this->freshaSkeletonFor($pro));
```

In `syncServicesNow()`, replace the existing block (lines 210-215) with:

```php
        if ($error = $this->ensureFreshaConnected($request)) {
            return $error;
        }

        $pro = $this->currentProfessional($request);
        $integration = $this->currentFreshaIntegration($request);

        $this->authorize('manage', $integration);
```

In `pushServiceNow()`, replace the existing first block (lines 241-247) with:

```php
        $pro = $this->currentProfessional($request);

        abort_unless($service->professional_id === $pro->id, 404);

        if ($error = $this->ensureFreshaConnected($request)) {
            return $error;
        }

        $integration = $this->currentFreshaIntegration($request);
        $this->authorize('manage', $integration);
```

In `token()` (read-only), immediately after the `ensureFreshaConnected` check, add:

```php
        $integration = $this->currentFreshaIntegration($request);
        $this->authorize('view', $integration);
```

(Replaces the duplicate `$integration = $this->currentFreshaIntegration($request);` lookup that's already there — keep one assignment, then call `authorize`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Security/IntegrationPolicy/FreshaPolicyEnforcementTest.php`
Expected: PASS — 3 tests.

- [ ] **Step 5: Run the full Fresha test surface to catch regressions**

Run: `./vendor/bin/pest --filter=Fresha`
Expected: PASS — all existing Fresha tests + the 3 new ones.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php tests/Feature/Security/IntegrationPolicy/FreshaPolicyEnforcementTest.php
git commit -m "feat(fresha): authorize integration mutations through IntegrationPolicy"
```

---

## Task 7: Migrate `SquareIntegrationController` (TDD via feature test)

Mirrors Task 6 exactly — Square has the same controller shape as Fresha.

**Files:**
- Create: `tests/Feature/Security/IntegrationPolicy/SquarePolicyEnforcementTest.php`
- Modify: `app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

use App\Http\Controllers\Api\Professional\SquareIntegration\SquareIntegrationController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Square\SquareServiceSyncService;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        provider_metadata TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $this->mock(BrandAccessService::class, function ($mock) {
        $mock->shouldReceive('canManageShopify')->andReturn(false);
        $mock->shouldReceive('isBrandProfessional')->andReturn(false);
    });
});

it('allows the owner to disconnect their own Square integration', function () {
    [$a] = createTwoTenants('professional');
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'square',
        'external_account_id' => 'merch-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($a, [], 'POST');
    $response = app(SquareIntegrationController::class)->disconnect($req);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a pending_deletion owner from disconnecting Square with 423', function () {
    [$a] = createTwoTenants('professional');
    DB::connection('pgsql')->table('core.professionals')->where('id', $a->id)->update([
        'status' => 'pending_deletion',
    ]);
    $a->refresh();

    $now = now()->toDateTimeString();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'square',
        'external_account_id' => 'merch-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($a, [], 'POST');

    try {
        app(SquareIntegrationController::class)->disconnect($req);
        expect(false)->toBeTrue('Expected AuthorizationException with 423 status');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

it('blocks tenant B from syncing tenant As Square integration', function () {
    [$a, $b] = createTwoTenants('professional');
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'square',
        'external_account_id' => 'merch-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($b, [], 'POST');
    $sync = Mockery::mock(SquareServiceSyncService::class);
    $sync->shouldReceive('syncFromSquare')->never();

    $response = app(SquareIntegrationController::class)->syncServicesNow($req, $sync);

    expect($response->getStatusCode())->toBe(404);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Security/IntegrationPolicy/SquarePolicyEnforcementTest.php`
Expected: FAIL — pending_deletion test does not throw `AuthorizationException`.

- [ ] **Step 3: Update `SquareIntegrationController`**

Open `app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php`.

Add the `Professional` import at the top of the file:

```php
use App\Models\Core\Professional\Professional;
```

Add this private helper inside the class, immediately after `ensureSquareConnected`:

```php
    /**
     * Build an unsaved ProfessionalIntegration carrying just professional_id +
     * provider. Used for connect-style policy checks where no row exists yet.
     */
    private function squareSkeletonFor(Professional $pro): ProfessionalIntegration
    {
        return new ProfessionalIntegration([
            'professional_id' => $pro->id,
            'provider' => ProfessionalIntegration::PROVIDER_SQUARE,
        ]);
    }
```

In `connect()`, immediately after `$pro = $this->currentProfessional($request);` (currently line 102), add:

```php
        $this->authorize('manage', $this->squareSkeletonFor($pro));
```

In `disconnect()`, immediately after `$pro = $this->currentProfessional($request);` (currently line 169), add:

```php
        $this->authorize('manage', $this->squareSkeletonFor($pro));
```

In `syncServicesNow()`, replace the existing block (lines 210-215) with:

```php
        if ($error = $this->ensureSquareConnected($request)) {
            return $error;
        }

        $pro = $this->currentProfessional($request);
        $integration = $this->currentSquareIntegration($request);

        $this->authorize('manage', $integration);
```

In `pushServiceNow()`, replace the existing first block (lines 257-263) with:

```php
        $pro = $this->currentProfessional($request);

        abort_unless($service->professional_id === $pro->id, 404);

        if ($error = $this->ensureSquareConnected($request)) {
            return $error;
        }

        $integration = $this->currentSquareIntegration($request);
        $this->authorize('manage', $integration);
```

In `token()` (read-only), immediately after the `ensureSquareConnected` check, add:

```php
        $integration = $this->currentSquareIntegration($request);
        $this->authorize('view', $integration);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Security/IntegrationPolicy/SquarePolicyEnforcementTest.php`
Expected: PASS — 3 tests.

- [ ] **Step 5: Run the full Square test surface**

Run: `./vendor/bin/pest --filter=Square`
Expected: PASS — all existing Square tests + the 3 new ones.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php tests/Feature/Security/IntegrationPolicy/SquarePolicyEnforcementTest.php
git commit -m "feat(square): authorize integration mutations through IntegrationPolicy"
```

---

## Task 8: Migrate `ShopifyIntegrationController` (TDD via feature test)

Shopify already calls `BrandAccessService::canManageShopify()` inline. The migration replaces those calls with `$this->authorize('manage', …)` so authorization runs through the same Policy as Fresha and Square. The brand-target-resolution logic (`resolveTargetBrandProfessionalId`) stays — it's resolving *which* brand to act on, not *whether* the actor is allowed.

**Files:**
- Create: `tests/Feature/Security/IntegrationPolicy/ShopifyPolicyEnforcementTest.php`
- Modify: `app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

use App\Http\Controllers\Api\Professional\ShopifyIntegration\ShopifyIntegrationController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        access_token TEXT,
        provider_metadata TEXT,
        status TEXT,
        expires_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        product_gid TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('blocks a pending_deletion brand from disconnecting their Shopify integration with 423', function () {
    [$a] = createTwoTenants('brand');
    DB::connection('pgsql')->table('core.professionals')->where('id', $a->id)->update([
        'status' => 'pending_deletion',
    ]);
    $a->refresh();

    $now = now()->toDateTimeString();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'access_token' => 'token-a',
        'provider_metadata' => json_encode(['shop_domain' => 'brand-a.myshopify.com']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // BrandAccessService confirms brand A can manage their own Shopify, so authz only
    // fails because of pending_deletion.
    $this->mock(BrandAccessService::class, function ($mock) use ($a) {
        $mock->shouldReceive('isBrandProfessional')->andReturn(true);
        $mock->shouldReceive('canManageShopify')
            ->andReturnUsing(fn ($pro, $brandId) => $pro->id === $a->id && $brandId === $a->id);
    });

    $req = tenantRequestAs($a, [], 'POST');

    try {
        app(ShopifyIntegrationController::class)->disconnect($req);
        expect(false)->toBeTrue('Expected AuthorizationException with 423 status');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

it('blocks brand B from disconnecting brand As Shopify integration', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'access_token' => 'token-a',
        'provider_metadata' => json_encode(['shop_domain' => 'brand-a.myshopify.com']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->mock(BrandAccessService::class, function ($mock) use ($b) {
        $mock->shouldReceive('isBrandProfessional')->andReturnUsing(fn ($pro) => $pro->id === $b->id);
        $mock->shouldReceive('canManageShopify')
            ->andReturnUsing(fn ($pro, $brandId) => $pro->id === $b->id && $brandId === $b->id);
    });

    $req = tenantRequestAs($b, [], 'POST');
    $response = app(ShopifyIntegrationController::class)->disconnect($req);

    // Brand B resolves to its own brand_professional_id (no integration there) — so
    // disconnect is idempotent against B's empty record. The key assertion: brand A's
    // record is untouched.
    expect($response->getStatusCode())->toBe(200);
    expect(DB::table('core.professional_integrations')
        ->where('professional_id', $a->id)
        ->where('provider', 'shopify')
        ->exists())->toBeTrue();
});

it('allows a brand-team member with shopify.manage capability to disconnect on behalf of the brand', function () {
    $brand = createBrandTenant('brand-z');
    $teamMember = createAffiliateTenant('team-member');
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brand->id,
        'provider' => 'shopify',
        'access_token' => 'token-z',
        'provider_metadata' => json_encode(['shop_domain' => 'brand-z.myshopify.com']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // BrandAccessService confirms team-member has shopify.manage on brand-z.
    $this->mock(BrandAccessService::class, function ($mock) use ($teamMember, $brand) {
        $mock->shouldReceive('isBrandProfessional')->andReturnUsing(fn ($pro) => $pro->id === $brand->id);
        $mock->shouldReceive('canManageShopify')
            ->andReturnUsing(fn ($pro, $brandId) => $pro->id === $teamMember->id && $brandId === $brand->id);
    });

    $req = tenantRequestAs($teamMember, ['brand_professional_id' => $brand->id], 'POST');
    $response = app(ShopifyIntegrationController::class)->disconnect($req);

    expect($response->getStatusCode())->toBe(200);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Security/IntegrationPolicy/ShopifyPolicyEnforcementTest.php`
Expected: FAIL — the pending_deletion test does not produce a 423; current Shopify code only checks `canManageShopify` and would proceed.

- [ ] **Step 3: Migrate `ShopifyIntegrationController`**

Open `app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php`.

`resolveTargetBrandProfessionalId()` currently calls `$this->brandAccess->canManageShopify(...)` directly (around line 69). Replace that authorization step so the policy is the source of truth.

Replace lines 69-71 (the current `canManageShopify` block):

```php
        if (! $this->brandAccess->canManageShopify($professional, $requestedBrandProfessionalId)) {
            return ['', $this->error('You are not permitted to manage Shopify integrations for this brand.', 403)];
        }
```

With a delegation that uses the policy. **The replacement depends on whether the call site is read-only or write.** Since `resolveTargetBrandProfessionalId` is shared by both, refactor it to accept an `$ability` argument and delegate to `Gate::authorize()`:

Update the method signature (around line 51-55):

```php
    /**
     * @return array{0: string, 1: JsonResponse|null}
     */
    private function resolveTargetBrandProfessionalId(
        Request $request,
        ?string $requestedBrandProfessionalId,
        bool $requireForNonBrand,
        string $ability = 'manage'
    ): array {
```

Replace the inner block (lines 69-71) with:

```php
        $skeleton = new ProfessionalIntegration([
            'professional_id' => $requestedBrandProfessionalId,
            'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        ]);

        try {
            $this->authorize($ability, $skeleton);
        } catch (AuthorizationException $e) {
            // Re-throw — Laravel translates AuthorizationException::status() into the
            // HTTP response. 423 for pending_deletion, 403 otherwise.
            throw $e;
        }
```

Add the import at the top of the file:

```php
use Illuminate\Auth\Access\AuthorizationException;
```

Confirm that `App\Models\Core\Professional\ProfessionalIntegration` is already imported (it is — line 16).

Update the **read-only** call sites of `resolveTargetBrandProfessionalId` to pass `'view'`:

- `status()` method — change the existing call (around line 95) to pass `'view'`:

```php
        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            false,
            'view'
        );
```

All other call sites (`connect`, `disconnect`, `registerWebhooks`, `token` if it uses this resolver, `resolveShop` if applicable) keep the default `'manage'`. **Verify by searching the file for `resolveTargetBrandProfessionalId(` and inspecting each call.** Read endpoints take `'view'`; write endpoints take `'manage'` (the default — no change needed).

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Security/IntegrationPolicy/ShopifyPolicyEnforcementTest.php`
Expected: PASS — 3 tests.

- [ ] **Step 5: Run the existing Shopify isolation suite to confirm no regression**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/IntegrationsIsolationTest.php`
Expected: PASS — 2 existing tests still pass.

- [ ] **Step 6: Run the full Shopify-touching test surface**

Run: `./vendor/bin/pest --filter=Shopify`
Expected: PASS — all existing Shopify tests.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php tests/Feature/Security/IntegrationPolicy/ShopifyPolicyEnforcementTest.php
git commit -m "feat(shopify): authorize integration mutations through IntegrationPolicy"
```

---

## Task 9: Final regression sweep

**Files:**
- None modified.

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: PASS — every test green. The composer guard (`guard:no-laravel-migrations`) confirms no Laravel migration files were created.

- [ ] **Step 2: Run pint**

Run: `php artisan pint`
Expected: clean output or trivial whitespace fixes only. If pint touches anything, commit as a separate hygiene commit:

```bash
git add -A
git commit -m "style: apply pint after IntegrationPolicy migration"
```

- [ ] **Step 3: Confirm policy is wired**

Run: `php artisan tinker --execute="echo get_class(\\Illuminate\\Support\\Facades\\Gate::getPolicyFor(\\App\\Models\\Core\\Professional\\ProfessionalIntegration::class));"`
Expected: `App\Policies\IntegrationPolicy`

- [ ] **Step 4: No additional commit unless pint changed something**

The work in this plan is the seven feature commits from Tasks 1–8 plus an optional pint commit.

---

## Self-Review

**1. Spec coverage:**
- "Build IntegrationPolicy + BasePolicy" — Tasks 2, 3, 4. ✓
- "with a pending_deletion read-only guard" — Task 2 (`denyIfPendingDeletion`), exercised in Task 4 (`manage` ability). ✓
- "tests/Unit/Policies/IntegrationPolicyTest.php" — Tasks 3, 4 build it incrementally. ✓
- "Migrate FreshaIntegrationController and SquareIntegrationController" — Tasks 6, 7. ✓
- User addition: "include Shopify please" — Task 8. ✓
- Three confirmed decisions: `denyWithStatus(423)` (Task 2), one `manage` ability (Task 4), Shopify migrated (Task 8). ✓

**2. Placeholder scan:** No "TBD", "TODO", "fill in", or "similar to" placeholders. Every step shows the exact code or command.

**3. Type consistency:**
- `BasePolicy::denyIfPendingDeletion(Professional): ?Response` — referenced by name in Task 4. ✓
- `IntegrationPolicy::view(Professional, ProfessionalIntegration): bool` — defined Task 3, no rename. ✓
- `IntegrationPolicy::manage(Professional, ProfessionalIntegration): bool|Response` — defined Task 4, called from controllers in Tasks 6/7/8 with the same signature. ✓
- Skeleton helper names: `freshaSkeletonFor(Professional): ProfessionalIntegration` (Task 6), `squareSkeletonFor(Professional): ProfessionalIntegration` (Task 7), inline skeleton in Shopify (Task 8). All consistent — `professional_id + provider`. ✓
- `BrandAccessService::canManageShopify($professional, $brandId): bool` — called in Task 3 and Task 4 with the matching signature shown in `app/Services/Store/BrandAccessService.php:51`. ✓

No issues found.
