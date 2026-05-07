# Authorization Policy Coverage — Tenant-Owned Models Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Achieve full authorization-Policy coverage on every tenant-owned model in the application, replacing all inline `abort_unless`/`abort(403/404)` ownership checks in API controllers with `$this->authorizeForUser($pro, $ability, $resource)` calls, then tighten CI to enforce the rule going forward. Audit finding **#1-01** (P0).

**Architecture:**
- **Policies extend `BasePolicy`** (already exists) and live in `app/Policies/`. Per the audit, group by *ownership shape* not by model — one `SitePolicy` covers `Site`/`Block`/`SiteMedia`/`Enquiry`/`SiteSubdomainAlias` because their ownership semantics are identical (root through `Site → Professional`).
- **Three ownership shapes need policies** (Shape D = system/global needs none): **A** direct-professional (`$model->professional_id === $actor->id`), **B** site-nested (resolve via `Site → Professional`), **C** brand-scoped (delegate to `BrandAccessService` capability methods).
- **"Brand-only" route-level checks become a `brand.only` middleware**, not policy abilities. Policies enforce *resource ownership*; the middleware enforces *endpoint eligibility*. This collapses ~40 inline aborts in `Store/*` and brand controllers into one middleware.
- **404-vs-403 leak prevention** is preserved with `Response::denyAsNotFound()` in policy methods that gate routed-bound resources (the existing `abort_unless(..., 404)` pattern returns 404 to avoid leaking resource existence — policies must do the same).
- **Coverage is enforced by a sweep test** — any tenant-owned model added later without a registered Policy fails the test, with an explicit allowlist for genuinely-public/global models.
- **CI tightening (Phase 5) extends the existing `INLINE_403` regex** to also reject `abort(404, ...)` and `abort_unless(..., 404, ...)` patterns inside `app/Http/Controllers/`.
- **Skeleton pattern for pre-create authorization** (no DB row yet) is the documented `new Model([...])` instance trick from `CLAUDE.md`. Reused for every `create` ability.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Mockery, in-memory SQLite test database (with the pgsql connection redirect), `BrandAccessService` for RBAC, existing `IntegrationPolicy` and tests as the canonical reference template (`docs/superpowers/plans/2026-04-26-integration-policy.md`).

**Phasing & Effort:**
- **Phase 0** (~2h) — Foundation: sweep coverage test + `brand.only` middleware
- **Phase 1** (~6h) — Pilot policies, one per ownership shape: `SitePolicy`, `CustomerPolicy`, `CommissionPayoutPolicy`, `BrandPartnerLinkPolicy`
- **Phase 2** (~6h) — Shape A sweep: 13 remaining direct-professional models + controller refactors
- **Phase 3** (~3h) — Shape B sweep: nested site models inherit through `SitePolicy` (mostly mechanical)
- **Phase 4** (~6h) — Shape C sweep: 5 remaining brand-scoped models + brand-only middleware migration
- **Phase 5** (~2h) — CI regex tightening + remove sweep-test allowlist

Total: 25h, lower end of the audit's 16–32h estimate. Each phase commits cleanly and leaves the codebase in a working, deployable state.

---

## Design Decisions

### Decision 1: Group policies by ownership shape, not 1:1 with models

The audit explicitly endorses `SitePolicy` covering `Site`/`Block`/`SiteMedia`. Extending that principle:

| Policy | Models Covered | Rationale |
|---|---|---|
| `SitePolicy` | `Site`, `Block`, `SiteMedia`, `Enquiry`, `SiteSubdomainAlias`, `LeadSubmission` | All resolve ownership through `Site → Professional` (or carry denormalized `professional_id` for query speed) |
| `CustomerPolicy` | `Customer` | Distinct shape: customer ↔ professional bidirectional, may need `viewAny` filter for staff impersonation |
| `ServicePolicy` | `Service`, `ServiceCategory` | Co-managed; same ownership column; same controllers |
| `BrandResourcePolicy` | `BrandProfile`, `BrandStoreSettings`, `BrandTeamMembership` | Brand-account-only resources, ownership = the brand professional |
| `BrandPartnerLinkPolicy` | `BrandPartnerLink`, `BrandPartnerLinkEvent`, `BrandAffiliateInvite` | Two-sided ownership (brand + affiliate); both can read, only brand can write |
| `CommissionPolicy` | `CommissionPayout`, `CommissionPayoutItem`, `CommissionLedgerEntry`, `BrandCommissionTopup` | Same ownership shape (brand-scoped, financial); `viewAny` differs by financial vs non-financial capability |
| `AffiliateProductPolicy` | `AffiliateProductSelection` | Two-sided (brand creates, affiliate selects); unique enough to warrant its own policy |
| `NotificationPolicy` | `Notification`, `NotificationReceipt`, `NotificationEmailPreference`, `NotificationEmailPolicy`, `EmailSubscription` | All keyed on `professional_id`; `Notification` is special (nullable `professional_id` = global) |
| `GdprPolicy` | `GdprRequest`, `DataExportAudit` | Owner-only access; pending-deletion lock doesn't apply (these *drive* deletion) |
| `SubscriptionPolicy` | `Subscription` | Owner-only |
| `ProfessionalSelfPolicy` | `Professional`, `ProfessionalConfirmationPreference`, `WalletCurrencySwitchAudit`, `ProfessionalDeletionAuditEntry` | Self-referential — actor can only act on own record |

Total: **11 policies** for 33 tenant-owned models. (Plus existing `IntegrationPolicy`.)

**Models that get NO policy** (Shape D — global/system, allowlisted in sweep test):
- `Plan` (catalog), `WebhookEvent` (system log), `MediaVariant` (parent-controlled), `PartnaStaff` (separate auth surface), `WaitlistSignup` (public submission), `CartEvent`/`LinkClick`/`SiteVisit` (public ingestion endpoints, scoped by site at write-time).

### Decision 2: Brand-only checks become middleware, not policy abilities

`BrandCatalogController`, `BrandStoreSettingsController`, `BrandDesignController`, `AffiliateProductController` (the brand-side checks), and several others gate on `$pro->isBrandProfessional()` *at the endpoint level* — every action in those controllers requires a brand account before any resource-level check applies. That's a route concern.

Create `App\Http\Middleware\EnsureBrandAccount` registered as `brand.only`. Apply to route groups. Resource policies still run *after* the middleware for ownership checks. This collapses ~40 inline aborts into one middleware + one test.

Affiliate-only checks (e.g., `AffiliateProductController` lines that reject brand callers) become a sibling `EnsureAffiliateAccount` middleware (`affiliate.only`).

### Decision 3: 404 vs 403 — preserve "don't leak existence"

Existing inline aborts use `404` for tenant-isolation (`abort_unless($x->professional_id === $pro->id, 404)`). Policies returning bare `false` produce `403`, which leaks "this resource exists, you just can't access it."

Use Laravel's `Response::denyAsNotFound('...')` in policy methods that gate route-bound resources. The base `BasePolicy` gets a sibling helper:

```php
protected function denyAsNotFound(): Response
{
    return Response::denyAsNotFound('Not found.');
}
```

Methods that gate non-routed actions (e.g., `create` against a class) can return plain `false` (→ 403) since there's no resource to leak.

### Decision 4: Sweep test enforces coverage going forward

`tests/Feature/Security/PolicyCoverageTest.php` reflects every model under `app/Models/`, asserts each is either:
1. Registered via `Gate::policy()` in `AppServiceProvider`, OR
2. Explicitly listed in a `POLICY_EXEMPT` allowlist (the Shape D models above).

Adding a new tenant-owned model later without registering a policy or amending the allowlist fails CI. Phase 5 audits the allowlist and shrinks it where possible.

### Decision 5: TDD pattern mirrors `IntegrationPolicyTest`

Every new policy gets:
- A unit test (`tests/Unit/Policies/<Name>PolicyTest.php`) covering each ability with positive + negative + edge cases (Mockery mocks for `BrandAccessService` where used)
- A feature test (`tests/Feature/Security/PolicyEnforcement/<Name>EnforcementTest.php`) proving the controller refactor enforces the policy end-to-end (one cross-tenant attempt per ability — the existing `tests/Feature/Security/IntegrationPolicy/SquarePolicyEnforcementTest.php` is the template).

---

## File Structure

**New files (Phase 0):**
- `app/Http/Middleware/EnsureBrandAccount.php`
- `app/Http/Middleware/EnsureAffiliateAccount.php`
- `tests/Feature/Security/PolicyCoverageTest.php`
- `tests/Unit/Middleware/EnsureBrandAccountTest.php`
- `tests/Unit/Middleware/EnsureAffiliateAccountTest.php`

**New files (Phase 1):**
- `app/Policies/SitePolicy.php`
- `app/Policies/CustomerPolicy.php`
- `app/Policies/CommissionPolicy.php`
- `app/Policies/BrandPartnerLinkPolicy.php`
- `tests/Unit/Policies/SitePolicyTest.php`
- `tests/Unit/Policies/CustomerPolicyTest.php`
- `tests/Unit/Policies/CommissionPolicyTest.php`
- `tests/Unit/Policies/BrandPartnerLinkPolicyTest.php`
- `tests/Feature/Security/PolicyEnforcement/SitePolicyEnforcementTest.php`
- `tests/Feature/Security/PolicyEnforcement/CustomerPolicyEnforcementTest.php`
- `tests/Feature/Security/PolicyEnforcement/CommissionPolicyEnforcementTest.php`
- `tests/Feature/Security/PolicyEnforcement/BrandPartnerLinkPolicyEnforcementTest.php`

**Modified files (Phase 0–1):**
- `app/Policies/BasePolicy.php` — add `denyAsNotFound()` helper
- `app/Providers/AppServiceProvider.php` — register the four new policies + bind middleware aliases
- `bootstrap/app.php` — register middleware aliases (`brand.only`, `affiliate.only`)
- `app/Http/Controllers/Api/Professional/ProfessionalCustomerController.php` — replace 6 inline aborts
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCustomerManagementController.php` — replace 7 inline aborts
- `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php` — replace 2 inline aborts (SitePolicy)
- `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php` — replace 1 site-scoped abort (SitePolicy; brand-pool aborts wait for Phase 4)
- `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` — replace 9 brand-only aborts with route-level `brand.only` middleware
- `app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php` — replace 8 brand-only aborts (route-level)
- `routes/api/professional.php` — apply `brand.only` to brand route group
- `app/Http/Controllers/Api/Professional/CommissionPayoutController.php` (or wherever payout endpoints live — verify in Phase 1) — wire policy

**Files modified in later phases:** see Phase 2–5 sections.

---

## Phase 0: Foundation (sweep test + middleware)

### Task 0.1: Add `denyAsNotFound()` helper to BasePolicy (TDD)

**Files:**
- Test: `tests/Unit/Policies/BasePolicyTest.php` (extend existing)
- Modify: `app/Policies/BasePolicy.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/Policies/BasePolicyTest.php` (after the existing `denyIfPendingDeletion` tests):

```php
it('returns a 404 deny response from denyAsNotFound', function () {
    // Concrete subclass exposes the protected helper.
    $policy = new class extends \App\Policies\BasePolicy {
        public function callDenyAsNotFound(): \Illuminate\Auth\Access\Response
        {
            return $this->denyAsNotFound();
        }
    };

    $result = $policy->callDenyAsNotFound();

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
    expect($result->message())->toBe('Not found.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Policies/BasePolicyTest.php --filter "denyAsNotFound"`
Expected: FAIL — `Method denyAsNotFound does not exist.`

- [ ] **Step 3: Implement the helper**

Edit `app/Policies/BasePolicy.php`. Add this method below `denyIfPendingDeletion`:

```php
/**
 * Deny with a 404 to avoid leaking resource existence to non-owners.
 * Use in policy methods that gate route-bound resources (i.e. when the
 * actor reaching this point implies they already submitted a valid UUID
 * for some resource — we don't want to confirm or deny it exists if they
 * don't have access).
 */
protected function denyAsNotFound(): Response
{
    return Response::denyAsNotFound('Not found.');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Policies/BasePolicyTest.php`
Expected: PASS (all 4 BasePolicy tests)

- [ ] **Step 5: Commit**

```bash
git add app/Policies/BasePolicy.php tests/Unit/Policies/BasePolicyTest.php
git commit -m "feat(policies): add denyAsNotFound helper to BasePolicy"
```

---

### Task 0.2: Sweep test — every tenant-owned model has a registered policy (TDD)

The test reflects every model under `app/Models/`, exempts a documented allowlist of system/global models, and asserts the rest have a `Gate::getPolicyFor()` resolution. This locks the convention going forward.

**Files:**
- Create: `tests/Feature/Security/PolicyCoverageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Security/PolicyCoverageTest.php`:

```php
<?php

use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Policy Coverage Sweep
|--------------------------------------------------------------------------
| Every model under app/Models/ must either (a) have a Gate-registered
| Policy, or (b) appear in POLICY_EXEMPT below with a justification.
|
| Adding a new model? Either register a policy in AppServiceProvider::boot
| or add an entry below explaining why this model doesn't need one.
| Untracked models silently allow IDOR — this test prevents that.
*/

const POLICY_EXEMPT = [
    // Catalog & system tables — no tenant ownership; admin-only or read-only.
    \App\Models\Billing\Plan::class,
    \App\Models\Billing\WebhookEvent::class,
    \App\Models\Core\MediaVariant::class,           // owned via parent SiteMedia
    \App\Models\Core\Staff\PartnaStaff::class,      // separate auth surface
    \App\Models\Core\Waitlist\WaitlistSignup::class, // public submission, no actor

    // Public ingestion — write-only via public site endpoints; scoped by
    // ResolvesSiteFromRequest at write time. Reads happen via the analytics
    // API, gated by the parent Site/CommissionPolicy.
    \App\Models\Analytics\CartEvent::class,
    \App\Models\Analytics\LinkClick::class,
    \App\Models\Analytics\SiteVisit::class,

    // Nested under CommissionPayout — gated transitively by CommissionPolicy.
    \App\Models\Retail\CommissionPayoutItem::class,
];

it('every tenant-owned model has a registered policy', function () {
    $modelFiles = (new Finder())
        ->files()
        ->in(app_path('Models'))
        ->name('*.php')
        ->notName('BaseModel.php')
        ->notPath('Views') // read-only DB views are not policy-gated
        ->getIterator();

    $missing = [];

    foreach ($modelFiles as $file) {
        $relative = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $file->getRealPath());
        if (! class_exists($relative)) {
            continue;
        }

        if (in_array($relative, POLICY_EXEMPT, true)) {
            continue;
        }

        $policy = Gate::getPolicyFor($relative);
        if ($policy === null) {
            $missing[] = $relative;
        }
    }

    expect($missing)->toBe([], "Models without a registered Policy:\n  - " . implode("\n  - ", $missing) . "\n\nEither register one in AppServiceProvider::boot() or add to POLICY_EXEMPT in this test with a justification.");
});

it('every POLICY_EXEMPT entry resolves to a real model class', function () {
    foreach (POLICY_EXEMPT as $class) {
        expect(class_exists($class))->toBeTrue("POLICY_EXEMPT entry {$class} does not resolve to an existing class.");
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Security/PolicyCoverageTest.php`
Expected: FAIL — long list of ~25 models without registered policies (Customer, Site, BrandPartnerLink, CommissionPayout, etc.). This is the *expected* failure baseline; subsequent phases shrink the list to zero.

- [ ] **Step 3: Mark the test pending until Phase 5**

The test will fail every CI run until all policies are registered (end of Phase 4). To avoid red CI during the rollout, mark it pending with a tracking note:

Edit `tests/Feature/Security/PolicyCoverageTest.php`. Wrap the first `it(...)` in:

```php
it('every tenant-owned model has a registered policy', function () {
    // ... existing test body ...
})->todo('Enabled in Phase 5 of auth-policy-coverage plan once all policies are registered.');
```

The second test (`every POLICY_EXEMPT entry resolves`) stays active — it's already passing.

- [ ] **Step 4: Run test to verify the second one passes and the first is skipped**

Run: `./vendor/bin/pest tests/Feature/Security/PolicyCoverageTest.php`
Expected: 1 todo / pending, 1 PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Security/PolicyCoverageTest.php
git commit -m "test(policies): add coverage sweep test (todo until Phase 5)"
```

---

### Task 0.3: `EnsureBrandAccount` middleware (TDD)

**Files:**
- Test: `tests/Unit/Middleware/EnsureBrandAccountTest.php`
- Create: `app/Http/Middleware/EnsureBrandAccount.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Middleware/EnsureBrandAccountTest.php`:

```php
<?php

use App\Http\Middleware\EnsureBrandAccount;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('passes the request through when the resolved professional is a brand', function () {
    $pro = new Professional(['professional_type' => 'brand']);
    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $pro);

    $next = fn ($req) => new Response('ok', 200);

    $response = (new EnsureBrandAccount())->handle($request, $next);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('returns 403 with a JSON error when the professional is not a brand', function () {
    $pro = new Professional(['professional_type' => 'professional']);
    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $pro);

    $response = (new EnsureBrandAccount())->handle($request, fn ($req) => new Response('ok'));

    expect($response->getStatusCode())->toBe(403);
    expect(json_decode($response->getContent(), true))
        ->toMatchArray(['error' => 'This endpoint is only available for brand accounts.']);
});

it('returns 401 when no professional is bound to the request', function () {
    $request = Request::create('/test', 'GET');

    $response = (new EnsureBrandAccount())->handle($request, fn ($req) => new Response('ok'));

    expect($response->getStatusCode())->toBe(401);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Middleware/EnsureBrandAccountTest.php`
Expected: FAIL — `Class App\Http\Middleware\EnsureBrandAccount does not exist.`

- [ ] **Step 3: Implement the middleware**

Create `app/Http/Middleware/EnsureBrandAccount.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Core\Professional\Professional;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the resolved Professional bound to the request is a brand account.
 * The auth context middleware sets `professional` on the request attributes
 * before this runs; if missing, return 401 (auth pipeline misconfiguration).
 *
 * Resource-level ownership is still enforced by the relevant Policy after
 * this middleware passes — this only gates *eligibility for the endpoint*.
 */
class EnsureBrandAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $pro = $request->attributes->get('professional');

        if (! $pro instanceof Professional) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        if (($pro->professional_type ?? null) !== 'brand') {
            return response()->json(
                ['error' => 'This endpoint is only available for brand accounts.'],
                403,
            );
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Middleware/EnsureBrandAccountTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Register the alias in `bootstrap/app.php`**

Open `bootstrap/app.php`. Inside the `withMiddleware(function (Middleware $middleware) { ... })` block, add:

```php
$middleware->alias([
    // ... existing aliases ...
    'brand.only' => \App\Http\Middleware\EnsureBrandAccount::class,
]);
```

(If an `alias([...])` block already exists, append the entry. If not, add the call.)

- [ ] **Step 6: Smoke test the alias resolves**

Run: `php artisan route:list --columns=method,uri,middleware 2>&1 | head -3`
Expected: No "Class not found" errors. (We haven't applied it to a route yet — that's Phase 4.)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsureBrandAccount.php tests/Unit/Middleware/EnsureBrandAccountTest.php bootstrap/app.php
git commit -m "feat(middleware): add brand.only middleware for brand-account endpoints"
```

---

### Task 0.4: `EnsureAffiliateAccount` middleware (TDD)

Mirror of Task 0.3 for the affiliate side (the pattern in `AffiliateProductController` rejecting brand callers).

**Files:**
- Test: `tests/Unit/Middleware/EnsureAffiliateAccountTest.php`
- Create: `app/Http/Middleware/EnsureAffiliateAccount.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Middleware/EnsureAffiliateAccountTest.php`:

```php
<?php

use App\Http\Middleware\EnsureAffiliateAccount;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('passes through when the professional is an affiliate (non-brand)', function () {
    $pro = new Professional(['professional_type' => 'professional']);
    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $pro);

    $response = (new EnsureAffiliateAccount())->handle($request, fn ($req) => new Response('ok', 200));

    expect($response->getStatusCode())->toBe(200);
});

it('returns 403 when the professional is a brand', function () {
    $pro = new Professional(['professional_type' => 'brand']);
    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $pro);

    $response = (new EnsureAffiliateAccount())->handle($request, fn ($req) => new Response('ok'));

    expect($response->getStatusCode())->toBe(403);
    expect(json_decode($response->getContent(), true))
        ->toMatchArray(['error' => 'Brand accounts cannot use this endpoint.']);
});

it('returns 401 when no professional is bound', function () {
    $request = Request::create('/test', 'GET');
    $response = (new EnsureAffiliateAccount())->handle($request, fn ($req) => new Response('ok'));
    expect($response->getStatusCode())->toBe(401);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Middleware/EnsureAffiliateAccountTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement the middleware**

Create `app/Http/Middleware/EnsureAffiliateAccount.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Core\Professional\Professional;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inverse of EnsureBrandAccount — gates endpoints that are only valid for
 * affiliates (non-brand professionals selecting brand products, claiming
 * invites, etc.). Resource ownership still enforced by the Policy layer.
 */
class EnsureAffiliateAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $pro = $request->attributes->get('professional');

        if (! $pro instanceof Professional) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        if (($pro->professional_type ?? null) === 'brand') {
            return response()->json(
                ['error' => 'Brand accounts cannot use this endpoint.'],
                403,
            );
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Middleware/EnsureAffiliateAccountTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Register the alias**

Edit `bootstrap/app.php`. Add to the same alias block from Task 0.3:

```php
'affiliate.only' => \App\Http\Middleware\EnsureAffiliateAccount::class,
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/EnsureAffiliateAccount.php tests/Unit/Middleware/EnsureAffiliateAccountTest.php bootstrap/app.php
git commit -m "feat(middleware): add affiliate.only middleware"
```

---

### Phase 0 Checkpoint

Run the full test suite to confirm nothing regressed:

```bash
composer test
```

Expected: All tests pass except the `PolicyCoverageTest` "every tenant-owned model has a registered policy" todo (deliberately skipped until Phase 5).

If any *other* test fails, stop and investigate before starting Phase 1.

---

## Phase 1: Pilot policies — one per ownership shape

Each task in this phase:
1. Writes the unit test (Pest) for the policy with positive + negative + edge cases
2. Implements the policy class
3. Registers it in `AppServiceProvider::boot()`
4. Refactors the relevant controller(s) to use `$this->authorizeForUser($pro, ...)`
5. Adds a feature test proving the controller refactor enforces the policy across the wire (cross-tenant attempt returns 404 or 403 as appropriate)
6. Commits

The pattern is established by `tests/Unit/Policies/IntegrationPolicyTest.php` and `tests/Feature/Security/IntegrationPolicy/SquarePolicyEnforcementTest.php` — every later task in this plan reuses the same shape.

### Task 1.1: `CustomerPolicy` (Shape A: direct professional ownership)

**Files:**
- Create: `app/Policies/CustomerPolicy.php`
- Test: `tests/Unit/Policies/CustomerPolicyTest.php`
- Test: `tests/Feature/Security/PolicyEnforcement/CustomerPolicyEnforcementTest.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalCustomerController.php` (lines 112, 116, 126, 128, 141, 161)
- Modify: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCustomerManagementController.php` (7 sites)

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Policies/CustomerPolicyTest.php`:

```php
<?php

use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Policies\CustomerPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->actor = new Professional([
        'id' => 'pro-actor',
        'status' => 'active',
        'professional_type' => 'professional',
    ]);

    $this->ownedCustomer = new Customer([
        'id' => 'cust-1',
        'professional_id' => 'pro-actor',
    ]);

    $this->otherCustomer = new Customer([
        'id' => 'cust-2',
        'professional_id' => 'pro-other',
    ]);

    $this->policy = new CustomerPolicy();
});

it('allows view when the actor owns the customer', function () {
    expect($this->policy->view($this->actor, $this->ownedCustomer))->toBeTrue();
});

it('denies view as 404 when the actor does not own the customer', function () {
    $result = $this->policy->view($this->actor, $this->otherCustomer);
    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

it('allows update when the actor owns the customer and is active', function () {
    expect($this->policy->update($this->actor, $this->ownedCustomer))->toBeTrue();
});

it('denies update with 423 when the actor is pending deletion', function () {
    $this->actor->status = 'pending_deletion';
    $result = $this->policy->update($this->actor, $this->ownedCustomer);
    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
});

it('denies update as 404 for a non-owned customer', function () {
    $result = $this->policy->update($this->actor, $this->otherCustomer);
    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

it('allows delete with the same rules as update', function () {
    expect($this->policy->delete($this->actor, $this->ownedCustomer))->toBeTrue();

    $result = $this->policy->delete($this->actor, $this->otherCustomer);
    expect($result->status())->toBe(404);
});

it('allows create when the skeleton matches the actor', function () {
    $skeleton = new Customer(['professional_id' => 'pro-actor']);
    expect($this->policy->create($this->actor, $skeleton))->toBeTrue();
});

it('denies create with 423 when the actor is pending deletion', function () {
    $this->actor->status = 'pending_deletion';
    $skeleton = new Customer(['professional_id' => 'pro-actor']);
    $result = $this->policy->create($this->actor, $skeleton);
    expect($result->status())->toBe(423);
});

it('denies create as 403 (not 404) when the skeleton targets another professional', function () {
    $skeleton = new Customer(['professional_id' => 'pro-other']);
    expect($this->policy->create($this->actor, $skeleton))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Policies/CustomerPolicyTest.php`
Expected: FAIL — `Class App\Policies\CustomerPolicy does not exist.`

- [ ] **Step 3: Implement the policy**

Create `app/Policies/CustomerPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;

/**
 * V2: Authorization for Customer (CRM contact records owned by Professional).
 *
 * Abilities:
 *   view   — actor owns the customer
 *   create — actor is creating a customer for themselves; pending_deletion blocks
 *   update — actor owns AND is not pending_deletion
 *   delete — same as update
 *
 * Read denials are returned as 404 to avoid leaking customer existence to
 * non-owners. Write denials use the same 404 (not 403) for the same reason —
 * an attacker testing arbitrary UUIDs should not learn which IDs exist.
 */
class CustomerPolicy extends BasePolicy
{
    public function view(Professional $actor, Customer $customer): bool|Response
    {
        if ((string) $customer->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }
        return true;
    }

    public function create(Professional $actor, Customer $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }
        return (string) $skeleton->professional_id === (string) $actor->id;
    }

    public function update(Professional $actor, Customer $customer): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }
        if ((string) $customer->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }
        return true;
    }

    public function delete(Professional $actor, Customer $customer): bool|Response
    {
        return $this->update($actor, $customer);
    }
}
```

- [ ] **Step 4: Run unit test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Policies/CustomerPolicyTest.php`
Expected: PASS (9 tests)

- [ ] **Step 5: Register the policy**

Edit `app/Providers/AppServiceProvider.php`. Add inside `boot()` next to the existing `Gate::policy(ProfessionalIntegration::class, ...)` call:

```php
Gate::policy(\App\Models\Core\Professional\Customer::class, \App\Policies\CustomerPolicy::class);
```

- [ ] **Step 6: Refactor `ProfessionalCustomerController`**

Open `app/Http/Controllers/Api/Professional/ProfessionalCustomerController.php`. For each of lines 112, 126, 141, 161 — they all look like:

```php
abort_unless($customer->professional_id === $pro->id, 404);
```

Replace with:

```php
$this->authorizeForUser($pro, 'update', $customer); // or 'view' / 'delete' depending on the action
```

For lines 116 and 128 (the bare `abort(404)` calls — these typically follow a `Customer::find($id)` returning null), replace with `Customer::findOrFail($id)` so the not-found is automatic, then add the authorize call before any read of `$customer`.

The exact ability mapping (read which line is which method):
- Line 112 (show action) → `'view'`
- Line 116 (still in show) → covered by `findOrFail`
- Line 126 (update) → `'update'`
- Line 128 (still update) → covered by `findOrFail`
- Line 141 (destroy) → `'delete'`
- Line 161 (notes update or similar — check method context) → `'update'`

- [ ] **Step 7: Refactor `StaffCustomerManagementController`**

Same treatment as Step 6 but with the staff-impersonation `Professional` (the `$pro` parameter is the *target* professional being managed, resolved by the staff controller's context middleware). The policy doesn't care that it's a staff actor — staff still pass `$pro` (the impersonated target) to `authorizeForUser`, which is consistent with the existing pattern in `ShopifyIntegrationController`.

- [ ] **Step 8: Write the feature enforcement test**

Create `tests/Feature/Security/PolicyEnforcement/CustomerPolicyEnforcementTest.php`. Pattern after `tests/Feature/Security/IntegrationPolicy/SquarePolicyEnforcementTest.php`. Cover:
- Owner can `GET /pro/customers/{id}` → 200
- Other professional gets 404 (not 403) on the same endpoint
- Other professional gets 404 on `PATCH` and `DELETE`
- Pending-deletion owner gets 423 on `PATCH` and `DELETE`
- Owner can `POST /pro/customers` → 201; pending-deletion owner gets 423 on `POST`

```php
<?php

// Reuses the tenant helpers from tests/Pest.php (added in tenant-isolation Part 1).

it('allows the owner to view their own customer', function () {
    [$pro, $customer] = createOwnedCustomer();
    $response = $this->actingAsProfessional($pro)
        ->getJson("/api/pro/customers/{$customer->id}");
    expect($response->status())->toBe(200);
});

it('returns 404 (not 403) when another professional tries to view the customer', function () {
    [$proA, $customer] = createOwnedCustomer();
    $proB = createTenant('thief')->load('site');
    $response = $this->actingAsProfessional($proB)
        ->getJson("/api/pro/customers/{$customer->id}");
    expect($response->status())->toBe(404);
});

// ... mirror tests for update (PATCH), delete, create, and pending_deletion ...
```

If `createOwnedCustomer()` and `actingAsProfessional()` helpers don't exist, add them to `tests/Pest.php` next to the existing tenant helpers (use the same insert pattern). The factories used here mirror the structure of the helpers added in `2026-04-24-tenant-isolation-part-1` task 1.

- [ ] **Step 9: Run feature test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Security/PolicyEnforcement/CustomerPolicyEnforcementTest.php`
Expected: PASS

- [ ] **Step 10: Run full test suite**

Run: `composer test`
Expected: All tests pass (PolicyCoverageTest todo still skipped).

- [ ] **Step 11: Commit**

```bash
git add app/Policies/CustomerPolicy.php \
        tests/Unit/Policies/CustomerPolicyTest.php \
        tests/Feature/Security/PolicyEnforcement/CustomerPolicyEnforcementTest.php \
        app/Providers/AppServiceProvider.php \
        app/Http/Controllers/Api/Professional/ProfessionalCustomerController.php \
        app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCustomerManagementController.php \
        tests/Pest.php
git commit -m "feat(policies): add CustomerPolicy and migrate Customer controllers (#1-01 phase 1)"
```

---

### Task 1.2: `SitePolicy` (Shape B: site-nested ownership)

This policy covers `Site` itself plus the nested resources (`Block`, `SiteMedia`, `Enquiry`, `SiteSubdomainAlias`, `LeadSubmission`). All ownership flows: nested model → `site_id` → Site → `professional_id`.

For models with denormalized `professional_id` (Block, Enquiry, LeadSubmission), the policy uses the denormalized column directly to avoid a query — but both columns must agree. A defensive consistency check (in update/delete) is *not* needed here because the writes that set those columns already enforce the relationship; verify by reading the model write paths during implementation.

**Files:**
- Create: `app/Policies/SitePolicy.php`
- Test: `tests/Unit/Policies/SitePolicyTest.php`
- Test: `tests/Feature/Security/PolicyEnforcement/SitePolicyEnforcementTest.php`
- Modify: `app/Providers/AppServiceProvider.php` (register policy for *all six* models)
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php` (lines 124, 180)
- Modify: `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php` (line 401 only — brand-pool aborts wait for Phase 4)

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Policies/SitePolicyTest.php`. Mirror the structure of `CustomerPolicyTest` but with one `describe()` block per nested model type so the matrix is readable. Cover:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Policies\SitePolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->actor = new Professional(['id' => 'pro-actor', 'status' => 'active']);
    $this->policy = new SitePolicy();
});

describe('Site itself', function () {
    it('allows view when actor owns the site', function () {
        $site = new Site(['professional_id' => 'pro-actor']);
        expect($this->policy->view($this->actor, $site))->toBeTrue();
    });

    it('denies view as 404 when actor does not own the site', function () {
        $site = new Site(['professional_id' => 'pro-other']);
        expect($this->policy->view($this->actor, $site)->status())->toBe(404);
    });

    it('denies update with 423 when pending deletion', function () {
        $this->actor->status = 'pending_deletion';
        $site = new Site(['professional_id' => 'pro-actor']);
        expect($this->policy->update($this->actor, $site)->status())->toBe(423);
    });
});

describe('SiteMedia', function () {
    it('allows view when the parent site belongs to the actor', function () {
        $site = new Site(['id' => 'site-1', 'professional_id' => 'pro-actor']);
        $media = new SiteMedia(['site_id' => 'site-1']);
        $media->setRelation('site', $site);
        expect($this->policy->view($this->actor, $media))->toBeTrue();
    });

    it('denies view as 404 when the parent site belongs to another actor', function () {
        $site = new Site(['id' => 'site-2', 'professional_id' => 'pro-other']);
        $media = new SiteMedia(['site_id' => 'site-2']);
        $media->setRelation('site', $site);
        expect($this->policy->view($this->actor, $media)->status())->toBe(404);
    });
});

describe('Block (denormalized professional_id)', function () {
    it('allows view when the denormalized owner matches actor', function () {
        $block = new Block(['site_id' => 'site-1', 'professional_id' => 'pro-actor']);
        expect($this->policy->view($this->actor, $block))->toBeTrue();
    });

    it('denies view as 404 when denormalized owner differs', function () {
        $block = new Block(['site_id' => 'site-1', 'professional_id' => 'pro-other']);
        expect($this->policy->view($this->actor, $block)->status())->toBe(404);
    });
});

// Same shape for Enquiry, SiteSubdomainAlias, LeadSubmission.
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Policies/SitePolicyTest.php`
Expected: FAIL — `Class App\Policies\SitePolicy does not exist.`

- [ ] **Step 3: Implement the policy**

Create `app/Policies/SitePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Analytics\LeadSubmission;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for Site and its nested resources.
 *
 * Covers: Site, Block, SiteMedia, Enquiry, SiteSubdomainAlias, LeadSubmission.
 *
 * Ownership resolution:
 *   - Site itself: $site->professional_id
 *   - Models with denormalized professional_id (Block, Enquiry, LeadSubmission):
 *     read the denormalized column directly (no JOIN at policy layer)
 *   - SiteMedia, SiteSubdomainAlias: resolve via $model->site->professional_id
 *     (Eloquent will lazy-load if not eager-loaded — Phase 1 controllers should
 *     eager-load with `->with('site')` to avoid N+1)
 *
 * 404 returns are used for read denials (don't leak existence). Write
 * denials also use 404 because an unauthorized actor reaching a write path
 * would have first had to know the resource UUID — same leak surface.
 */
class SitePolicy extends BasePolicy
{
    public function view(Professional $actor, Model $resource): bool|Response
    {
        return $this->ownerMatches($actor, $resource)
            ? true
            : $this->denyAsNotFound();
    }

    public function update(Professional $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }
        return $this->ownerMatches($actor, $resource)
            ? true
            : $this->denyAsNotFound();
    }

    public function delete(Professional $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }

    public function create(Professional $actor, Model $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }
        return $this->ownerMatches($actor, $skeleton);
    }

    private function ownerMatches(Professional $actor, Model $resource): bool
    {
        $ownerId = $this->resolveOwnerId($resource);
        return $ownerId !== null && (string) $ownerId === (string) $actor->id;
    }

    private function resolveOwnerId(Model $resource): ?string
    {
        // Direct cases — model carries professional_id (Site itself, plus
        // denormalized columns on Block/Enquiry/LeadSubmission).
        if (isset($resource->professional_id)) {
            return (string) $resource->professional_id;
        }

        // Indirect cases — resolve through the parent Site relationship.
        if ($resource instanceof SiteMedia || $resource instanceof SiteSubdomainAlias) {
            $site = $resource->site;
            return $site ? (string) $site->professional_id : null;
        }

        return null;
    }
}
```

- [ ] **Step 4: Run unit test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Policies/SitePolicyTest.php`
Expected: PASS

- [ ] **Step 5: Register the policy for all six model classes**

Edit `app/Providers/AppServiceProvider.php`. Add (next to the existing `Gate::policy` calls):

```php
Gate::policy(\App\Models\Core\Site\Site::class, \App\Policies\SitePolicy::class);
Gate::policy(\App\Models\Core\Site\Block::class, \App\Policies\SitePolicy::class);
Gate::policy(\App\Models\Core\Site\SiteMedia::class, \App\Policies\SitePolicy::class);
Gate::policy(\App\Models\Core\Site\SiteSubdomainAlias::class, \App\Policies\SitePolicy::class);
Gate::policy(\App\Models\Core\Site\Enquiry::class, \App\Policies\SitePolicy::class);
Gate::policy(\App\Models\Analytics\LeadSubmission::class, \App\Policies\SitePolicy::class);
```

- [ ] **Step 6: Refactor `ProfessionalGalleryController`**

Open `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php`. Replace the line-124 and line-180 `abort_unless($image->site_id === $site->id, 404);` with:

```php
$this->authorizeForUser($pro, 'update', $image);
```

(The check `$image->site_id === $site->id` is implied by the policy resolving owner through the parent Site — but only if `$site->professional_id === $pro->id` also holds. If the controller has *both* a `$site` and an `$image`, eager-load the relationship: `$image->setRelation('site', $site)` before the authorize call to avoid an extra query.)

- [ ] **Step 7: Refactor the gallery line in `ProfessionalUploadController` (line 401)**

Same treatment for the site-nested check at line 401. The brand-pool checks elsewhere in this file are Shape C and stay until Phase 4.

- [ ] **Step 8: Write the feature enforcement test**

Create `tests/Feature/Security/PolicyEnforcement/SitePolicyEnforcementTest.php`. Mirror the IntegrationPolicy enforcement structure but cover at least one nested model (e.g., a SiteMedia cross-tenant access attempt returning 404 not 403, and the same for Block via its endpoint).

- [ ] **Step 9: Run full test suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Policies/SitePolicy.php \
        tests/Unit/Policies/SitePolicyTest.php \
        tests/Feature/Security/PolicyEnforcement/SitePolicyEnforcementTest.php \
        app/Providers/AppServiceProvider.php \
        app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php \
        app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php
git commit -m "feat(policies): add SitePolicy covering site-nested resources (#1-01 phase 1)"
```

---

### Task 1.3: `CommissionPolicy` (Shape C: brand-scoped via BrandAccessService)

`CommissionPayout` and `CommissionLedgerEntry` have *two* legitimate readers: the brand professional (or a team member with financial-analytics capability) AND the affiliate the payout is for. This is the canonical brand-scoped shape; the policy delegates to `BrandAccessService::canReadBrandFinancialAnalytics()`.

**Files:**
- Create: `app/Policies/CommissionPolicy.php`
- Test: `tests/Unit/Policies/CommissionPolicyTest.php`
- Test: `tests/Feature/Security/PolicyEnforcement/CommissionPolicyEnforcementTest.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: the commission payout controller(s) — verify file paths during implementation. Likely `app/Http/Controllers/Api/Professional/Store/CommissionPayoutController.php` or similar; if one doesn't exist yet, this task only adds the policy + tests + registration, and controller migration moves to Phase 4 with the rest of the Store endpoints.

- [ ] **Step 1: Verify which controller(s) read CommissionPayout**

Run: `grep -rln "CommissionPayout\|CommissionLedger" app/Http/Controllers/`

Record the file list — it informs Step 6's refactor scope.

- [ ] **Step 2: Write the failing unit test**

Create `tests/Unit/Policies/CommissionPolicyTest.php` (mock `BrandAccessService`, mirror the `IntegrationPolicyTest` mocking pattern):

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Policies\CommissionPolicy;
use App\Services\Store\BrandAccessService;

beforeEach(function () {
    $this->brandAccess = Mockery::mock(BrandAccessService::class);
    $this->policy = new CommissionPolicy($this->brandAccess);

    $this->actor = new Professional(['id' => 'pro-actor', 'status' => 'active']);
    $this->payout = new CommissionPayout([
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
    ]);
});

it('allows view when actor is the affiliate the payout is for', function () {
    $this->actor->id = 'aff-1';
    expect($this->policy->view($this->actor, $this->payout))->toBeTrue();
});

it('allows view when actor is the brand owner', function () {
    $this->actor->id = 'brand-1';
    $this->brandAccess->shouldNotReceive('canReadBrandFinancialAnalytics');
    expect($this->policy->view($this->actor, $this->payout))->toBeTrue();
});

it('allows view when actor has brand financial analytics capability', function () {
    $this->actor->id = 'team-member-1';
    $this->brandAccess->shouldReceive('canReadBrandFinancialAnalytics')
        ->once()->with($this->actor, 'brand-1')->andReturn(true);
    expect($this->policy->view($this->actor, $this->payout))->toBeTrue();
});

it('denies view as 404 when actor has no claim on either side', function () {
    $this->brandAccess->shouldReceive('canReadBrandFinancialAnalytics')->andReturn(false);
    $result = $this->policy->view($this->actor, $this->payout);
    expect($result->status())->toBe(404);
});

// ... mirror for update — affiliates can NOT update; only brand or capability holders can.
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Policies/CommissionPolicyTest.php`
Expected: FAIL — class missing.

- [ ] **Step 4: Implement the policy**

```php
<?php

namespace App\Policies;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\BrandCommissionTopup;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for commission financial records (CommissionPayout,
 * CommissionLedgerEntry, BrandCommissionTopup).
 *
 * Read access:
 *   - The affiliate (for payouts/ledger entries that belong to them)
 *   - The brand owner
 *   - Brand team members with CAPABILITY_ANALYTICS_FINANCIAL_READ
 *
 * Write access (update/create):
 *   - Brand owner
 *   - Brand team members with manage capability (CAPABILITY_STORE_MANAGE)
 *   - NOT the affiliate (read-only for them)
 */
class CommissionPolicy extends BasePolicy
{
    public function __construct(private readonly BrandAccessService $brandAccess) {}

    public function view(Professional $actor, Model $record): bool|Response
    {
        $brandId = (string) ($record->brand_professional_id ?? '');
        $affiliateId = (string) ($record->affiliate_professional_id ?? '');
        $actorId = (string) $actor->id;

        if ($brandId === '') {
            return $this->denyAsNotFound();
        }
        if ($actorId === $affiliateId || $actorId === $brandId) {
            return true;
        }
        if ($this->brandAccess->canReadBrandFinancialAnalytics($actor, $brandId)) {
            return true;
        }
        return $this->denyAsNotFound();
    }

    public function update(Professional $actor, Model $record): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }
        $brandId = (string) ($record->brand_professional_id ?? '');
        $actorId = (string) $actor->id;

        if ($brandId === '') {
            return $this->denyAsNotFound();
        }
        if ($actorId === $brandId) {
            return true;
        }
        if ($this->brandAccess->canManageBrand($actor, $brandId)) {
            return true;
        }
        return $this->denyAsNotFound();
    }

    public function delete(Professional $actor, Model $record): bool|Response
    {
        return $this->update($actor, $record);
    }
}
```

- [ ] **Step 5: Run unit test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Policies/CommissionPolicyTest.php`
Expected: PASS.

- [ ] **Step 6: Register the policy**

Edit `AppServiceProvider::boot()`:

```php
Gate::policy(\App\Models\Retail\CommissionPayout::class, \App\Policies\CommissionPolicy::class);
Gate::policy(\App\Models\Retail\CommissionLedgerEntry::class, \App\Policies\CommissionPolicy::class);
Gate::policy(\App\Models\Retail\BrandCommissionTopup::class, \App\Policies\CommissionPolicy::class);
```

- [ ] **Step 7: Refactor commission controllers (per Step 1 list)**

For each controller identified in Step 1, replace inline ownership checks with `$this->authorizeForUser($pro, 'view', $payout)` etc. If the controller has brand-only checks (`isBrandProfessional`), defer those to Phase 4 (they'll be replaced by `brand.only` middleware on the route group, not by the policy).

- [ ] **Step 8: Write feature enforcement test + run full suite**

Mirror previous tasks. Run `composer test` — expect PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Policies/CommissionPolicy.php tests/Unit/Policies/CommissionPolicyTest.php tests/Feature/Security/PolicyEnforcement/CommissionPolicyEnforcementTest.php app/Providers/AppServiceProvider.php [controller files from Step 1]
git commit -m "feat(policies): add CommissionPolicy for brand-scoped financial records (#1-01 phase 1)"
```

---

### Task 1.4: `BrandPartnerLinkPolicy` (Shape C variant: two-sided participants)

`BrandPartnerLink` (and `BrandPartnerLinkEvent`, `BrandAffiliateInvite`) have a brand and an affiliate side, both of whom can read but only the brand can write. Distinct enough from CommissionPolicy to warrant a separate policy.

Follow the same 9-step pattern as Task 1.3:

1. Identify the controller(s) — `grep -rln "BrandPartnerLink\|BrandAffiliateInvite" app/Http/Controllers/`. Likely `app/Http/Controllers/Api/Professional/BrandPartnerController.php` and `app/Http/Controllers/Api/Professional/BrandAffiliateController.php`.
2. Write `tests/Unit/Policies/BrandPartnerLinkPolicyTest.php` covering: brand can view+update+delete, affiliate can view but cannot update, third party gets 404.
3. Confirm test fails.
4. Implement `app/Policies/BrandPartnerLinkPolicy.php` using the CommissionPolicy structure as a template — but the affiliate side is `affiliate_professional_id` and `update`/`delete` deny for affiliates (return `$this->denyAsNotFound()`).
5. Confirm unit test passes.
6. Register for `BrandPartnerLink`, `BrandPartnerLinkEvent`, and `BrandAffiliateInvite` in `AppServiceProvider`.
7. Refactor only the *ownership* aborts in those controllers (the brand-only `isBrandProfessional` checks stay until Phase 4 — leave them with a `// TODO(#1-01-phase4): replace with brand.only middleware` comment so Phase 4 finds them via grep).
8. Write feature test, run full suite.
9. Commit.

```bash
git commit -m "feat(policies): add BrandPartnerLinkPolicy for two-sided brand-affiliate links (#1-01 phase 1)"
```

---

### Phase 1 Checkpoint

After all four pilot policies are merged:

- [ ] Run `composer test` — full suite passes.
- [ ] Inspect the count of remaining inline aborts: `grep -rE "abort\(403|abort\(404|abort_unless|abort_if" app/Http/Controllers/Api/ | grep -E "403|404" | wc -l`. Expected: still ~120 (we've removed ~25 in Phase 1; the brand-only ones await Phase 4 middleware swap).
- [ ] Run the (still-todo) coverage sweep test mentally: 4 + existing IntegrationPolicy = 5 of ~11 needed policies registered.
- [ ] **Pause for user review.** Phase 1 establishes the pattern; Phases 2–4 are mechanical applications. Confirm the established pattern is sound before sweeping.

---

## Phase 2: Sweep Shape A (direct professional ownership)

Apply the Task 1.1 (CustomerPolicy) template to each remaining Shape A model. Each gets its own task with the same 11-step structure (test → implement → register → refactor → feature test → commit).

**Models requiring policies in this phase:**

| # | Model | Likely Controller(s) | Notes |
|---|---|---|---|
| 2.1 | `Service` + `ServiceCategory` | `ProfessionalServiceController`, `ProfessionalServiceCategoryController`, plus staff variants | Combine into one `ServicePolicy` covering both — same ownership column, same controllers. Lines 174/183/201/338 in service controller; 80/85/95/97/110/112/193 in category controller |
| 2.2 | `BrandStoreSettings` | `BrandStoreSettingsController` | Brand-only — defer brand-only check to Phase 4; this task only handles ownership |
| 2.3 | `ProfessionalConfirmationPreference` | `ProfessionalConfirmationPreferenceController` (find via grep) | Self-only |
| 2.4 | `WalletCurrencySwitchAudit` | Likely read-only via `ProfessionalAccountController` | Read-only audit log; only `view` ability |
| 2.5 | `ProfessionalDeletionAuditEntry` | Same as 2.4 | Read-only audit |
| 2.6 | `Subscription` (Billing) | `ProfessionalSubscriptionController` (find) | Owner-only `view` + `update` for plan changes |
| 2.7 | `EmailSubscription`, `NotificationEmailPreference`, `NotificationEmailPolicy`, `NotificationReceipt`, `Notification` | `Notifications/NotificationController` (lines 152/157/160) | Combine into `NotificationPolicy`. Note: `Notification.professional_id` is *nullable* (global notifications) — global ones are viewable by all, but only deletable/markable by the targeted recipient |
| 2.8 | `GdprRequest` + `DataExportAudit` | GDPR controllers (find via grep) | `GdprPolicy`. Note: `denyIfPendingDeletion` does NOT apply here — these endpoints *drive* deletion; an actor in `pending_deletion` MUST still be able to read their export status |

**Phase 2 commit cadence:** one commit per model/policy task. After all are merged, run `composer test` and confirm `grep "abort_unless\|abort(404)\|abort(403)" app/Http/Controllers/Api/ | wc -l` is approximately halved.

---

## Phase 3: Sweep Shape B (site-nested) — already covered by SitePolicy

Phase 1 Task 1.2 registered `SitePolicy` against all six Shape B models. Phase 3 only needs to refactor the remaining controllers that touch them:

| # | Controller | Lines | Notes |
|---|---|---|---|
| 3.1 | `ProfessionalLinkBlockController` (lines 44, 113, 243) | Use `'update'` against the Block model |
| 3.2 | `ProfessionalSectionBlockController` (lines 73, 241) | Block model — same |
| 3.3 | `ProfessionalDocumentController` (lines 199, 249) | Documents are likely SiteMedia variants — verify model + ability |
| 3.4 | `PublicSite/AnalyticsController` (4 calls) | Site-scoped reads — verify whether these are *public* (resolve site by subdomain, no actor) or *authenticated* (use SitePolicy `'view'`) |
| 3.5 | `PublicSite/QrCodeController` (3 calls) | Same eval as 3.4 |
| 3.6 | `PublicSite/PublicDocumentDownloadController` (3 calls) | Public — already partly hardened in `2026-04-24-tenant-isolation-part-1`; these may already be fine, just verify |
| 3.7 | `Square/Fresha integration site checks` (line 277 / 260) | Verify whether these need SitePolicy or are already covered by IntegrationPolicy |

**Phase 3 deliverables:** 7 controller refactor tasks + their feature tests. No new policies, no new model registrations. Each commits independently.

---

## Phase 4: Sweep Shape C (brand-scoped) + brand-only middleware migration

This phase has two parts that interleave:

### Part A: Apply `brand.only` and `affiliate.only` middleware to route groups

Open `routes/api/professional.php`. Identify the route groups containing brand-only controllers:

```php
// Brand-only endpoints
Route::middleware(['brand.only'])->group(function () {
    Route::resource('store/catalog', BrandCatalogController::class);
    Route::resource('store/settings', BrandStoreSettingsController::class);
    Route::apiResource('store/design', BrandDesignController::class);
    Route::resource('brand/profile', BrandProfileController::class);
    Route::resource('brand/affiliates', BrandAffiliateController::class);
    Route::resource('brand/affiliate-invites', BrandAffiliateInviteController::class);
    Route::resource('brand/gallery', BrandGalleryController::class);
    Route::resource('stripe/connect', StripeConnectController::class);
    Route::resource('shopify/embedded-connection', ShopifyEmbeddedConnectionController::class);
    Route::resource('shopify/resync', ShopifyResyncController::class);
    Route::get('brand/setup', [BrandSetupController::class, 'show']);
    Route::get('brand/onboarding-readiness', [BrandOnboardingReadinessController::class, 'show']);
    Route::resource('brand/partners', BrandPartnerController::class);
});

// Affiliate-only endpoints
Route::middleware(['affiliate.only'])->group(function () {
    Route::resource('affiliate/products', AffiliateProductController::class);
    Route::resource('affiliate/products/{gid}/photos', AffiliateProductPhotoController::class);
    Route::get('affiliate/invite/{token}', [AffiliateInviteController::class, 'show']);
});
```

(Exact route definitions vary — adapt to existing structure. The principle is: identify the routes whose controllers currently have `isBrandProfessional` checks, group them, apply `brand.only`.)

After applying middleware, **remove the inline brand-only aborts** from each of those controllers. The middleware now handles the eligibility check; the controller is left with only resource-level ownership checks (already migrated to policies in earlier tasks or migrated now).

### Part B: New brand-scoped policies for remaining models

| # | Policy | Models | Notes |
|---|---|---|---|
| 4.1 | `BrandResourcePolicy` | `BrandProfile`, `BrandTeamMembership` | Brand-account-only; ownership = the brand professional |
| 4.2 | `AffiliateProductPolicy` | `AffiliateProductSelection` | Two-sided: brand creates, affiliate selects/customizes; both can read their participation, only their own side can write. Photos endpoint should reuse this for `'managePhotos'` ability |
| 4.3 | `ProfessionalSelfPolicy` | `Professional` | Only the actor can `view`/`update` their own Professional row. Used by account/profile endpoints. |

For each, follow the Task 1.3/1.4 pattern (test → implement → register → refactor → feature test → commit).

### Phase 4 Checkpoint

- [ ] All inline `abort_unless`/`abort(403)`/`abort(404)` calls removed from `app/Http/Controllers/Api/` (verify with `grep`).
- [ ] `composer test` passes.
- [ ] The `PolicyCoverageTest` (when manually un-`todo`ed) passes.

---

## Phase 5: Tighten CI + finalize coverage test

### Task 5.1: Enable the `PolicyCoverageTest`

- [ ] Remove the `->todo(...)` from `tests/Feature/Security/PolicyCoverageTest.php`.
- [ ] Run: `./vendor/bin/pest tests/Feature/Security/PolicyCoverageTest.php`
- [ ] Expected: PASS. If it fails, the failure message lists the exact models still missing a policy or allowlist entry — fix and re-run.

### Task 5.2: Audit and shrink `POLICY_EXEMPT`

Re-read each entry in `POLICY_EXEMPT`. For each one, justify why it doesn't need a policy. Update the comment block at the top of the test with the per-entry justifications. Remove any entry that *should* have a policy and add one (this catches Shape D models the team later realizes are tenant-owned).

### Task 5.3: Tighten the CI inline-abort regex

Edit `.github/workflows/ci.yml`. Update the `INLINE_403` block:

```yaml
- name: Check for inline auth bypasses in controllers
  run: |
    CAPABILITY_PATTERN="canManageShopify|canManageBrand|canReadBrandAnalytics|canReadBrandFinancialAnalytics"
    CAPABILITY_BYPASS=$(grep -rEn "$CAPABILITY_PATTERN" app/Http/Controllers/ | wc -l | tr -d ' ')

    # Inline 403/404 aborts in controllers bypass the Policy layer.
    # Use authorizeForUser() with a Policy returning denyAsNotFound() for 404s
    # (preserves the "don't leak existence" pattern).
    INLINE_AUTH_ABORT=$(grep -rEn "abort\((403|404)|abort_unless|abort_if" app/Http/Controllers/ | grep -E "403|404" | wc -l | tr -d ' ')

    if [ "$CAPABILITY_BYPASS" -gt 0 ]; then
      echo "::error::Found $CAPABILITY_BYPASS direct BrandAccessService capability call(s) in controllers. Route authorization through a Policy via authorizeForUser() instead."
      grep -rEn "$CAPABILITY_PATTERN" app/Http/Controllers/
      exit 1
    fi

    if [ "$INLINE_AUTH_ABORT" -gt 0 ]; then
      echo "::error::Found $INLINE_AUTH_ABORT inline 403/404 abort(s) in controllers. Use a Policy via authorizeForUser() — denyAsNotFound() for tenant-isolation 404s, or abort(422,...) if it's actually input validation."
      grep -rEn "abort\((403|404)|abort_unless|abort_if" app/Http/Controllers/ | grep -E "403|404"
      exit 1
    fi

    echo "No inline auth bypasses found."
```

- [ ] Run the regex locally: `grep -rEn "abort\((403|404)|abort_unless|abort_if" app/Http/Controllers/ | grep -E "403|404"`
- [ ] Expected: zero matches. If any remain, finish their migration before merging.

### Task 5.4: Update CLAUDE.md

Append a sentence under the existing "Authorization Pattern" section:

> **Coverage is sweep-tested:** `tests/Feature/Security/PolicyCoverageTest.php` asserts every tenant-owned model has a registered Policy or appears in `POLICY_EXEMPT` with a justification. Adding a model? Either register a policy in `AppServiceProvider::boot()` or add an exempt entry.

### Task 5.5: Final commit

```bash
git add tests/Feature/Security/PolicyCoverageTest.php .github/workflows/ci.yml CLAUDE.md
git commit -m "chore(policies): enable coverage sweep test and extend CI to reject inline 404 aborts (#1-01 phase 5 — closes audit finding)"
```

---

## Verification & Self-Review

### Spec coverage check
- [x] Task per finding requirement: every tenant-owned model gets a policy registration → Phase 1 + 2 + 4
- [x] Sweep + replace inline `abort_unless`/`abort(403/404)` → Phase 1 (pilot) + 2/3/4 (sweep)
- [x] Tighten CI regex → Phase 5 Task 5.3
- [x] Phased rollout (high-value resources first) → Phase 1 covers Customer, Site, CommissionPayout, BrandPartnerLink (the audit's named priorities)
- [x] Use grouping where ownership semantics are identical → Decision 1 (11 policies, not 33)

### Edge cases addressed
- 404-vs-403 leak prevention → `BasePolicy::denyAsNotFound()`
- Pre-create checks (no DB row) → skeleton pattern (CLAUDE.md)
- Pending-deletion lock → existing `BasePolicy::denyIfPendingDeletion()` reused
- Brand-account endpoint eligibility (route concern, not resource concern) → `brand.only` / `affiliate.only` middleware
- Two-sided ownership (brand + affiliate) → `BrandPartnerLinkPolicy`, `CommissionPolicy`, `AffiliateProductPolicy`
- Public/system models that don't need policies → documented `POLICY_EXEMPT` allowlist with justifications
- Coverage enforcement going forward → sweep test + CI regex tightening

### Pre-execution sanity
- [ ] Read `docs/superpowers/plans/2026-04-26-integration-policy.md` to internalize the established pattern.
- [ ] Read `tests/Pest.php` tenant helpers added by `2026-04-24-tenant-isolation-part-1` — many feature tests in this plan reuse them.
- [ ] Confirm working on a branch that is up-to-date with `main`.
- [ ] Confirm the worktree is clean of unrelated changes.

---

## Execution Notes

**Recommended execution mode:** Subagent-driven, one task per subagent.
- Phase 0 + Phase 1 are foundational — supervise closely, review every commit.
- Phase 2/3/4 sweeps are mechanical applications of Phase 1 patterns — well-suited to fresh subagents per task with two-stage review.
- Phase 5 is small but high-impact (CI change can break the build for everyone) — execute inline with extra scrutiny.

**Branch strategy:** This is large enough to warrant its own branch (`feat/auth-policy-coverage` or similar). Each phase can be its own PR if Josh prefers smaller reviews, or one PR per phase set. Bundling Phases 0 + 1 into one PR is reasonable; sweep phases (2/3/4) work better as separate PRs to keep diffs reviewable.

**Pause points:** End of Phase 0 (foundation in place), end of Phase 1 (pattern proven), end of Phase 4 (all coverage in place but CI still permissive). Each is a natural stopping point if priorities shift mid-rollout.
