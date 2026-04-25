# Tenant Isolation — Part 1: Shared Helpers & IDOR Bug Fixes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add shared tenant-isolation test helpers and remediate the 5 confirmed IDOR bugs surfaced during the security audit. Ship as a standalone PR before Part 2.

**Architecture:** For each bug — write a failing test that proves the leak, fix the code, confirm the test passes. All tests live under `tests/Feature/Security/TenantIsolation/` and follow the existing in-memory SQLite + direct controller-invocation pattern (see `tests/Feature/Documents/DocumentControllerIntegrationTest.php` for the canonical template). Shared helpers added to `tests/Pest.php` are reused by Part 2.

**Tech Stack:** Pest 4, Laravel 12, PHPUnit 11, in-memory SQLite (attached as schemas: `core`, `site`, `commerce`, `analytics`, `billing`, `notifications`, `retail`). Tests bypass HTTP routing by instantiating controllers and Form Requests directly with a `Request` whose `attributes` carry the mocked authenticated `Professional`.

---

## Audit Findings — Confirmed IDOR Bugs (this plan)

1. `POST /public/analytics/pageviews` and `/clicks` — accepts `site_id` in body without verifying it matches the routed subdomain. `ResolvesSiteFromRequest::resolveSiteFromData()` at `app/Http/Controllers/Api/PublicSite/Concerns/ResolvesSiteFromRequest.php:14-37`.
2. `GET /public/documents/{document}/download` — only gates on UUID + `pool === documents`. No subdomain check. `PublicDocumentDownloadController.php`.
3. `GET /api/shopify/setup-prefill` — no throttle; 64-char hex tokens are strong but still guessable at infinite rate. `routes/api.php` route definition.
4. `POST/DELETE /affiliate/products/{gid}/photos` — accepts Shopify GID in path and only scopes the media by `professional_id`; does not verify the GID belongs to a product the affiliate is selected to promote. `app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php`.
5. `POST /webhooks/stripe-connect` — resolves account from `data.object.id` rather than the tamper-resistant top-level `event.account`; a forged event with mismatched fields can operate on the wrong brand. `StripeConnectWebhookController.php`.

---

## File Structure

**New files:**
- `tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php`
- `tests/Feature/Security/TenantIsolation/PublicDocumentDownloadIdorTest.php`
- `tests/Feature/Security/TenantIsolation/ShopifySetupPrefillThrottleTest.php`
- `tests/Feature/Security/TenantIsolation/AffiliateProductPhotoIdorTest.php`
- `tests/Feature/Security/TenantIsolation/StripeConnectWebhookAccountConfusionTest.php`

**Modified files:**
- `tests/Pest.php` — add shared tenant-isolation helpers
- `app/Http/Controllers/Api/PublicSite/Concerns/ResolvesSiteFromRequest.php`
- `app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php`
- `routes/api.php`
- `app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php`
- `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php`

---

## Task 1: Shared tenant-isolation test helpers

**Files:**
- Modify: `tests/Pest.php` (append at end of file, after existing helpers)

- [ ] **Step 1: Add the helper block**

Append the following block to `tests/Pest.php`:

```php
/*
|--------------------------------------------------------------------------
| Tenant Isolation Helpers
|--------------------------------------------------------------------------
| Shared between tests/Feature/Security/TenantIsolation/*. Each helper
| creates a minimal but realistic tenant (professional + site + profile)
| and returns the live Eloquent model so tests can wire it to a Request.
*/

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;

function tenantHelpersEnsureTables(): void
{
    attachTestSchemas();
    setupProfessionalsTable();
    setupSitesTable();
    setupBrandLinkTables();
}

/**
 * Create an isolated tenant. Returns the freshly-loaded Professional model
 * with its Site eager-loaded. Handle is used to namespace records so two
 * sequential calls never collide.
 */
function createTenant(string $handle, string $type = 'professional'): Professional
{
    tenantHelpersEnsureTables();

    $proId = (string) \Illuminate\Support\Str::uuid();
    $siteId = (string) \Illuminate\Support\Str::uuid();
    $now = now()->toDateTimeString();

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'auth_user_id' => 'auth-'.\Illuminate\Support\Str::random(12),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => $handle.'@example.test',
        'professional_type' => $type,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => $handle,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::query()->with('site')->findOrFail($proId);
}

function createBrandTenant(string $handle = 'brand-a'): Professional
{
    return createTenant($handle, 'brand');
}

function createAffiliateTenant(string $handle = 'affiliate-a'): Professional
{
    return createTenant($handle, 'professional');
}

/**
 * Standard pair used by almost every isolation test: two fully-independent
 * tenants of the given type. Returns [$tenantA, $tenantB].
 *
 * @return array{0: Professional, 1: Professional}
 */
function createTwoTenants(string $type = 'brand'): array
{
    $a = $type === 'brand' ? createBrandTenant('brand-a') : createAffiliateTenant('aff-a');
    $b = $type === 'brand' ? createBrandTenant('brand-b') : createAffiliateTenant('aff-b');

    return [$a, $b];
}

/**
 * Make a Request that simulates authenticated access as $tenant. Mirrors
 * the pattern used in DocumentControllerIntegrationTest — `current.pro`
 * middleware would normally set this attribute at runtime.
 */
function requestAs(Professional $tenant, array $input = [], string $method = 'GET'): \Illuminate\Http\Request
{
    $req = \Illuminate\Http\Request::create('/', $method, $input);
    $req->attributes->set('professional', $tenant);
    $req->setUserResolver(fn () => (object) ['professional' => $tenant]);

    return $req;
}
```

- [ ] **Step 2: Run the full suite to confirm nothing regresses**

Run: `composer test`
Expected: PASS (helpers are additive; nothing else changed).

- [ ] **Step 3: Commit**

```bash
git add tests/Pest.php
git commit -m "test(security): add shared tenant-isolation helpers"
```

---

## Task 2: Public analytics site_id IDOR (REAL BUG FIX)

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php`
- Modify: `app/Http/Controllers/Api/PublicSite/Concerns/ResolvesSiteFromRequest.php`

**Bug:** `ResolvesSiteFromRequest::resolveSiteFromData()` allows an attacker on subdomain `attacker.sidest.app` to submit `{"site_id": "<victim-uuid>"}` and have the pageview/click recorded against the victim's analytics. Current code: `Site::query()->find($data['site_id'])` with no subdomain cross-check.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Controllers\Api\PublicSite\AnalyticsController;
use App\Models\Core\Site\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
    // analytics.site_visit_events + link_click_events
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.site_visit_events (
        id TEXT PRIMARY KEY,
        site_id TEXT, professional_id TEXT, subdomain TEXT,
        session_id TEXT, visitor_id TEXT, path TEXT, referrer TEXT,
        user_agent TEXT, country_code TEXT, created_at TEXT
    )');
});

it('refuses to record a pageview when body site_id does not match the routed subdomain', function () {
    $victim = createBrandTenant('victim');
    $attacker = createBrandTenant('attacker');

    $req = Request::create('/public/analytics/pageviews', 'POST', [
        'site_id' => $victim->site->id,
        'session_id' => 'sess',
        'visitor_id' => 'visitor',
        'path' => '/',
    ]);
    $req->headers->set('X-Site-Subdomain', 'attacker');

    $controller = app(AnalyticsController::class);
    $response = $controller->pageview($req);

    expect($response->getStatusCode())->toBe(422);

    $recorded = DB::connection('pgsql')
        ->table('analytics.site_visit_events')
        ->where('site_id', $victim->site->id)
        ->count();

    expect($recorded)->toBe(0);
});
```

- [ ] **Step 2: Run test — confirm failure**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php`
Expected: FAIL — pageview is recorded against victim site (attacker's `X-Site-Subdomain` is ignored by `resolveSiteFromData`).

- [ ] **Step 3: Fix `ResolvesSiteFromRequest`**

Open `app/Http/Controllers/Api/PublicSite/Concerns/ResolvesSiteFromRequest.php` and replace the `resolveSiteFromData` method body so `site_id` is cross-checked against the subdomain from the request:

```php
protected function resolveSiteFromData(array $data, ?\Illuminate\Http\Request $request = null): ?Site
{
    $subdomain = $request ? $this->resolveSiteSubdomain($request) : null;

    if (! empty($data['site_id'])) {
        $query = Site::query()->whereKey($data['site_id']);
        if ($subdomain !== null) {
            $query->whereRaw('lower(subdomain) = ?', [strtolower($subdomain)]);
        }
        $site = $query->first();
        if ($site) {
            return $site;
        }
        // site_id was supplied but does not belong to this subdomain — treat as invalid input.
        if ($subdomain !== null) {
            return null;
        }
    }

    if (! empty($data['subdomain'])) {
        return Site::query()->whereRaw('lower(subdomain) = ?', [strtolower($data['subdomain'])])->first();
    }

    return null;
}
```

Then update `AnalyticsController::pageview()` and `AnalyticsController::click()` to pass `$request` as the second arg and return `422` when `$site === null` and `site_id` was present.

- [ ] **Step 4: Re-run test**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php`
Expected: PASS.

- [ ] **Step 5: Run full suite to ensure no regressions in existing public analytics tests**

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php \
        app/Http/Controllers/Api/PublicSite/Concerns/ResolvesSiteFromRequest.php \
        app/Http/Controllers/Api/PublicSite/AnalyticsController.php
git commit -m "fix(security): reject analytics pageview/click when site_id does not match subdomain"
```

---

## Task 3: Public document download subdomain bypass (REAL BUG FIX)

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/PublicDocumentDownloadIdorTest.php`
- Modify: `app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php`

**Bug:** `GET /public/documents/{document}/download` only checks pool + active + site published. An attacker who learns Site A's document UUID can download it from `attacker.sidest.app/documents/<uuid>/download`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Controllers\Api\PublicSite\PublicDocumentDownloadController;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupMediaTables();
});

it('refuses to download a document when its site does not match the request subdomain', function () {
    $victim = createBrandTenant('victim');
    $attacker = createBrandTenant('attacker');

    $docId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $docId,
        'site_id' => $victim->site->id,
        'professional_id' => $victim->id,
        'pool' => SiteMedia::POOL_DOCUMENTS,
        'path' => 'docs/secret.pdf',
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $doc = SiteMedia::query()->findOrFail($docId);

    $req = Request::create('/public/documents/'.$docId.'/download', 'GET');
    $req->headers->set('X-Site-Subdomain', 'attacker');

    $controller = app(PublicDocumentDownloadController::class);

    expect(fn () => $controller($doc, $req))
        ->toThrow(HttpException::class);
});
```

- [ ] **Step 2: Run — confirm failure**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/PublicDocumentDownloadIdorTest.php`
Expected: FAIL — controller issues a redirect (no subdomain check exists).

- [ ] **Step 3: Fix the controller**

In `PublicDocumentDownloadController.php`, modify `__invoke` to accept `Request $request` and add a subdomain check after loading the site:

```php
public function __invoke(SiteMedia $document, Request $request): RedirectResponse
{
    abort_unless(
        $document->pool === SiteMedia::POOL_DOCUMENTS
        && $document->is_active
        && $document->deleted_at === null,
        404
    );

    $site = Site::query()->find($document->site_id);
    abort_unless($site && $site->is_published, 404);

    $requestedSubdomain = $this->resolveSiteSubdomain($request);
    abort_unless(
        $requestedSubdomain !== null
        && strtolower($site->subdomain) === strtolower($requestedSubdomain),
        404
    );

    // ... existing redirect logic
}
```

Use the existing `ResolvesSiteFromRequest` trait for `resolveSiteSubdomain`.

- [ ] **Step 4: Re-run test**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/PublicDocumentDownloadIdorTest.php`
Expected: PASS.

- [ ] **Step 5: Run existing document download test to ensure happy path still works**

Run: `./vendor/bin/pest tests/Feature/Documents/PublicDocumentDownloadTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Security/TenantIsolation/PublicDocumentDownloadIdorTest.php \
        app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php
git commit -m "fix(security): enforce subdomain match on public document download"
```

---

## Task 4: Shopify setup-prefill throttle (REAL BUG FIX)

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/ShopifySetupPrefillThrottleTest.php`
- Modify: `routes/api.php`

**Bug:** No rate limit on `/shopify/setup-prefill?token=...`. 64-char hex tokens are 256 bits — uncrackable by online brute force, but the principle of defense-in-depth and the audit finding both warrant a throttle.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Illuminate\Support\Facades\Route;

it('applies a throttle middleware to the shopify setup-prefill route', function () {
    $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri === 'api/shopify/setup-prefill');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();
    $throttle = collect($middleware)->first(fn ($m) => str_starts_with($m, 'throttle:'));

    expect($throttle)->not->toBeNull('setup-prefill must be throttled to prevent token brute-force');
});
```

- [ ] **Step 2: Run — confirm failure**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/ShopifySetupPrefillThrottleTest.php`
Expected: FAIL — no `throttle:*` middleware found on route.

- [ ] **Step 3: Add throttle to route**

In `routes/api.php`, locate the `shopify/setup-prefill` route and add `->middleware('throttle:10,15')`:

```php
Route::get('shopify/setup-prefill', [ShopifyAppOAuthController::class, 'setupPrefill'])
    ->middleware('throttle:10,15')
    ->name('shopify.setup-prefill');
```

- [ ] **Step 4: Re-run test**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/ShopifySetupPrefillThrottleTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Security/TenantIsolation/ShopifySetupPrefillThrottleTest.php \
        routes/api.php
git commit -m "fix(security): throttle shopify setup-prefill to 10/15min"
```

---

## Task 5: Affiliate product photo GID validation (REAL BUG FIX)

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/AffiliateProductPhotoIdorTest.php`
- Modify: `app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php`

**Bug:** Affiliate uploads/deletes photos for `{gid}` without the controller verifying that `{gid}` is an AffiliateProductSelection this affiliate owns. A malicious affiliate could attach media records to *any* Shopify GID, polluting another affiliate's product photos (site_media is filtered later by product_gid + professional_id, so read leakage is bounded — but write pollution matters).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Controllers\Api\Professional\Store\AffiliateProductPhotoController;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupMediaTables();
    Storage::fake('media');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        brand_professional_id TEXT,
        product_gid TEXT,
        sort_order INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('rejects photo upload for a gid the affiliate has not selected', function () {
    [$affA, $affB] = createTwoTenants('affiliate');
    // affB has the real selection
    DB::connection('pgsql')->table('commerce.affiliate_product_selections')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'affiliate_professional_id' => $affB->id,
        'brand_professional_id' => (string) \Illuminate\Support\Str::uuid(),
        'product_gid' => 'gid://shopify/Product/12345',
    ]);

    $req = requestAs($affA, [
        'photo' => UploadedFile::fake()->image('x.jpg'),
    ], 'POST');

    $controller = app(AffiliateProductPhotoController::class);

    expect(fn () => $controller->store($req, 'gid://shopify/Product/12345'))
        ->toThrow(HttpException::class);
});
```

- [ ] **Step 2: Run — confirm failure**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/AffiliateProductPhotoIdorTest.php`
Expected: FAIL — controller uploads successfully.

- [ ] **Step 3: Fix the controller**

At the top of every method in `AffiliateProductPhotoController.php` (`index`, `store`, `destroy`, `reorder`), after resolving `$professional`, add:

```php
$exists = DB::table('commerce.affiliate_product_selections')
    ->where('affiliate_professional_id', $professional->id)
    ->where('product_gid', $gid)
    ->exists();

abort_unless($exists, 404);
```

- [ ] **Step 4: Re-run test**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/AffiliateProductPhotoIdorTest.php`
Expected: PASS.

- [ ] **Step 5: Verify existing affiliate product photo tests still pass**

Run: `./vendor/bin/pest tests/Feature/Store/AffiliateProductPhotoTest.php`
Expected: PASS (if it fails, the existing test's seeding needs a matching `affiliate_product_selections` row — add one at the top of the test's `beforeEach`).

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Security/TenantIsolation/AffiliateProductPhotoIdorTest.php \
        app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php \
        tests/Feature/Store/AffiliateProductPhotoTest.php
git commit -m "fix(security): verify gid is in affiliate's selections before photo ops"
```

---

## Task 6: Stripe Connect webhook account confusion (REAL BUG FIX)

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/StripeConnectWebhookAccountConfusionTest.php`
- Modify: `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php`

**Bug:** For account-scoped events (`account.updated`, `account.application.deauthorized`), the controller reads the acct id from `data.object.id`. The top-level `event.account` field is the HMAC-signed, tamper-resistant source of truth. An attacker who can mint a valid signature could craft `{event.account: acct_attacker, data.object.id: acct_victim}` — the controller would mutate the victim's record.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Controllers\Api\Webhooks\StripeConnectWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.stripe_connect_accounts (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        stripe_connect_account_id TEXT,
        charges_enabled INTEGER,
        payouts_enabled INTEGER,
        details_submitted INTEGER,
        created_at TEXT, updated_at TEXT
    )');
});

it('rejects an account.updated event whose data.object.id does not match event.account', function () {
    $victim = createBrandTenant('victim');
    $attacker = createBrandTenant('attacker');
    DB::connection('pgsql')->table('billing.stripe_connect_accounts')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'professional_id' => $victim->id,
        'stripe_connect_account_id' => 'acct_VICTIM',
        'charges_enabled' => 0,
    ]);

    // Skip signature verification by binding a fake Event into the container.
    $fakeEvent = Event::constructFrom([
        'id' => 'evt_fake',
        'type' => 'account.updated',
        'account' => 'acct_ATTACKER',
        'data' => ['object' => [
            'id' => 'acct_VICTIM',
            'charges_enabled' => true,
        ]],
    ]);

    $controller = app(StripeConnectWebhookController::class);
    $response = $controller->handleParsedEvent($fakeEvent);

    expect($response->getStatusCode())->toBe(400);

    $updated = DB::connection('pgsql')->table('billing.stripe_connect_accounts')
        ->where('stripe_connect_account_id', 'acct_VICTIM')
        ->value('charges_enabled');
    expect((int) $updated)->toBe(0);
});
```

- [ ] **Step 2: Run — confirm failure**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/StripeConnectWebhookAccountConfusionTest.php`
Expected: FAIL — either `handleParsedEvent` doesn't exist yet, or the victim's `charges_enabled` gets set to 1.

- [ ] **Step 3: Fix the controller**

In `StripeConnectWebhookController.php`:

1. Extract current event-dispatch logic into a `handleParsedEvent(Event $event)` method (if not already structured that way) so it's unit-testable.
2. At the top of that method, for account-scoped events (types starting with `account.` or `capability.`), verify consistency:

```php
$accountScopedPrefixes = ['account.', 'capability.'];
$isAccountScoped = collect($accountScopedPrefixes)
    ->contains(fn ($p) => str_starts_with($event->type, $p));

if ($isAccountScoped) {
    $topLevelAccount = $event->account ?? null;
    $objectId = $event->data->object->id ?? null;

    if ($topLevelAccount === null || $topLevelAccount !== $objectId) {
        Log::warning('stripe.connect.account_mismatch', [
            'event_id' => $event->id,
            'event_account' => $topLevelAccount,
            'object_id' => $objectId,
        ]);
        return response()->json(['error' => 'account_mismatch'], 400);
    }
}
```

- [ ] **Step 4: Re-run test**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/StripeConnectWebhookAccountConfusionTest.php`
Expected: PASS.

- [ ] **Step 5: Run existing Stripe Connect webhook tests**

Run: `./vendor/bin/pest --filter=StripeConnect`
Expected: PASS (legitimate events have matching `event.account === data.object.id`).

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Security/TenantIsolation/StripeConnectWebhookAccountConfusionTest.php \
        app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
git commit -m "fix(security): reject Stripe Connect events with mismatched account fields"
```

---

## Task 7: Final verification

- [ ] **Step 1: Full test run**

Run: `composer test`
Expected: PASS — all 5 new IDOR test files pass, no regressions.

- [ ] **Step 2: Pint formatting**

Run: `php artisan pint`
Commit any formatting diffs:

```bash
git add -u
git commit -m "style: apply Pint formatting to IDOR fix tests"
```

- [ ] **Step 3: Self-review checklist**

- [ ] All 5 IDOR bug fixes implemented with failing → passing test
- [ ] Shared helpers in `tests/Pest.php` (needed by Part 2)
- [ ] Full `composer test` passes
- [ ] No existing tests broken

---

## Execution handoff

Part 1 is self-contained and shippable as a standalone PR. Part 2 (regression tests) depends on the shared helpers added in Task 1 of this plan and should be executed after this plan merges.
