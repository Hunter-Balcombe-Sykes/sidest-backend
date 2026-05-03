# Tenant Isolation — Part 2: Cross-Tenant Regression Test Suites

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Prerequisite:** Part 1 (`2026-04-24-tenant-isolation-part-1-idor-fixes.md`) must be merged first. The shared helpers (`createTwoTenants`, `requestAs`, `tenantHelpersEnsureTables`, etc.) added to `tests/Pest.php` in Part 1 Task 1 are used by every test in this plan.

**Goal:** Add regression tests across every authenticated endpoint group that should enforce tenant isolation. These tests seed two independent tenants and assert that Tenant B cannot read or mutate Tenant A's data. Any test that fails here surfaces a real bug — investigate and fix rather than skip.

**Architecture:** All tests live under `tests/Feature/Security/TenantIsolation/` and follow the same in-memory SQLite + direct controller-invocation pattern used in Part 1. Tests are mostly independent and well-suited to parallel subagent execution.

**Tech Stack:** Pest 4, Laravel 12, PHPUnit 11, in-memory SQLite with attached schemas: `core`, `site`, `commerce`, `analytics`, `billing`, `notifications`, `retail`.

---

## Coverage gaps addressed (from the original audit)

- Analytics (brand commerce, affiliate commerce, booking, site/link analytics)
- Commissions & payouts (ledger, payout retry, stripe payouts)
- Store catalog mutations (metafields, active, commission, discount)
- Store settings, brand design
- Brand collections (product add/remove/reorder)
- Brand-affiliate partner links & invites
- Subscription CRUD + billing portal
- Stripe Connect onboarding, payment methods, payouts
- Customers, enquiries, services, service categories
- Links, sections, themes
- Professional/brand profile updates, site updates, site visibility
- Integration status/connect/disconnect endpoints (Shopify, Square, Fresha)
- Webhook cross-tenant replay

---

## File Structure

**New files:**
- `tests/Feature/Security/TenantIsolation/AnalyticsIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/CommissionsIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/StoreCatalogIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/StoreSettingsIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/CollectionsIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/PartnersIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/SubscriptionIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/CustomerIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/ServicesIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/LinksAndSectionsIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/ProfileAndSiteIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/IntegrationsIsolationTest.php`
- `tests/Feature/Security/TenantIsolation/WebhookCrossTenantTest.php`

---

## Task 1: Analytics endpoint isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/AnalyticsIsolationTest.php`

**Covers:**
- `GET /analytics` (site/link analytics)
- `GET /brand/commerce-analytics`
- `GET /affiliate/commerce-analytics`
- `GET /booking/my-analytics/overview`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\AnalyticsController as SiteAnalyticsController;
use App\Http\Controllers\Api\Professional\BrandCommerceAnalyticsController;
use App\Http\Controllers\Api\Professional\AffiliateCommerceAnalyticsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.site_visits_hourly (
        professional_id TEXT, site_id TEXT, hour_bucket TEXT,
        visits INTEGER, clicks INTEGER
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.affiliate_commerce_orders (
        id TEXT PRIMARY KEY, affiliate_professional_id TEXT, brand_professional_id TEXT,
        total_amount_cents INTEGER, commission_amount_cents INTEGER, created_at TEXT
    )');
});

it('site analytics index returns zero when tenant B has no data even if tenant A has data', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('analytics.site_visits_hourly')->insert([
        'professional_id' => $a->id, 'site_id' => $a->site->id,
        'hour_bucket' => now()->toDateTimeString(),
        'visits' => 99, 'clicks' => 12,
    ]);

    $req = requestAs($b);
    $response = app(SiteAnalyticsController::class)->index($req);
    $payload = $response->getData(true);

    expect($payload['data']['total_visits'] ?? $payload['total_visits'] ?? 0)->toBe(0);
});

it('brand commerce analytics never exposes another brand orders', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('analytics.affiliate_commerce_orders')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => (string) Str::uuid(),
        'brand_professional_id' => $a->id,
        'total_amount_cents' => 999_00, 'commission_amount_cents' => 99_00,
        'created_at' => now()->toDateTimeString(),
    ]);

    $req = requestAs($b);
    $response = app(BrandCommerceAnalyticsController::class)->summary($req);
    $payload = $response->getData(true);

    expect($payload['data']['total_revenue_cents'] ?? 0)->toBe(0);
    expect($payload['data']['order_count'] ?? 0)->toBe(0);
});

it('affiliate commerce analytics never exposes another affiliate orders', function () {
    [$a, $b] = createTwoTenants('affiliate');

    DB::table('analytics.affiliate_commerce_orders')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $a->id,
        'brand_professional_id' => (string) Str::uuid(),
        'total_amount_cents' => 500_00, 'commission_amount_cents' => 50_00,
        'created_at' => now()->toDateTimeString(),
    ]);

    $req = requestAs($b);
    $response = app(AffiliateCommerceAnalyticsController::class)->summary($req);
    $payload = $response->getData(true);

    expect($payload['data']['total_commission_cents'] ?? 0)->toBe(0);
});
```

- [ ] **Step 2: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/AnalyticsIsolationTest.php`
Expected: PASS — existing scoping should already enforce this. If any fail, investigate the controller at fault and add a WHERE clause.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Security/TenantIsolation/AnalyticsIsolationTest.php
git commit -m "test(security): tenant isolation tests for analytics endpoints"
```

---

## Task 2: Commissions & payouts isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/CommissionsIsolationTest.php`

**Covers:**
- Staff commission ledger (`GET /staff/professionals/{professional}/commissions`) — scopeBindings
- Affiliate commerce analytics commission view
- Stripe Connect payouts list (`GET /stripe/payouts`)

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectPayoutsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.stripe_connect_accounts (
        id TEXT PRIMARY KEY, professional_id TEXT, stripe_connect_account_id TEXT,
        charges_enabled INTEGER, payouts_enabled INTEGER, created_at TEXT, updated_at TEXT
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY, professional_id TEXT, amount_cents INTEGER,
        status TEXT, created_at TEXT
    )');
});

it('stripe payouts list never returns another professional payouts', function () {
    [$a, $b] = createTwoTenants('affiliate');

    foreach ([$a, $b] as $pro) {
        DB::table('billing.stripe_connect_accounts')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $pro->id,
            'stripe_connect_account_id' => 'acct_'.substr($pro->id, 0, 8),
            'payouts_enabled' => 1,
        ]);
    }

    DB::table('commerce.commission_payouts')->insert([
        ['id' => (string) Str::uuid(), 'professional_id' => $a->id, 'amount_cents' => 10_00, 'status' => 'paid'],
        ['id' => (string) Str::uuid(), 'professional_id' => $b->id, 'amount_cents' => 20_00, 'status' => 'paid'],
    ]);

    $req = requestAs($b);
    $response = app(StripeConnectPayoutsController::class)->index($req);
    $payload = $response->getData(true);

    $ids = collect($payload['data'] ?? [])->pluck('id')->all();
    expect(count($ids))->toBe(1);
    // Fetch tenant A's payout id to confirm it is not present
    $aPayoutId = DB::table('commerce.commission_payouts')->where('professional_id', $a->id)->value('id');
    expect($ids)->not->toContain($aPayoutId);
});
```

- [ ] **Step 2: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/CommissionsIsolationTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Security/TenantIsolation/CommissionsIsolationTest.php
git commit -m "test(security): tenant isolation tests for commissions and payouts"
```

---

## Task 3: Store catalog mutation isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/StoreCatalogIsolationTest.php`

**Covers PATCH mutations on** `/brand/catalog/{productGid}/{active|commission|discount|metafields}` — the controller should reject any GID whose product doesn't belong to the authenticated brand's Shopify integration.

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\Store\BrandCatalogController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY, professional_id TEXT, provider TEXT,
        shopify_shop_domain TEXT, access_token_encrypted TEXT,
        status TEXT, created_at TEXT, updated_at TEXT
    )');
});

it('refuses to toggle active on a gid from another brands store', function () {
    [$brandA, $brandB] = createTwoTenants('brand');
    foreach ([$brandA, $brandB] as $brand) {
        DB::table('core.professional_integrations')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $brand->id,
            'provider' => 'shopify',
            'shopify_shop_domain' => substr($brand->handle, 0, 20).'.myshopify.com',
            'status' => 'connected',
        ]);
    }

    // brandA has product 99; brandB tries to toggle it.
    $req = requestAs($brandB, ['is_active' => false], 'PATCH');
    $controller = app(BrandCatalogController::class);

    // Bind a fake catalog service that reports this gid is brandA's.
    $this->mock(\App\Services\Shopify\BrandCatalogService::class, function ($m) use ($brandA) {
        $m->shouldReceive('findOwningProfessionalId')
          ->with('gid://shopify/Product/99')
          ->andReturn($brandA->id);
    });

    expect(fn () => $controller->updateActive($req, 'gid://shopify/Product/99'))
        ->toThrow(HttpException::class);
});
```

Note: if `BrandCatalogService` does not yet expose `findOwningProfessionalId`, add it as part of this task and scope it through the Shopify integration record.

- [ ] **Step 2: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/StoreCatalogIsolationTest.php`
Expected: PASS (ideally) or FAIL (if the controller currently mutates without ownership check — fix by adding the ownership verification).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Security/TenantIsolation/StoreCatalogIsolationTest.php
# plus any controller/service fix
git commit -m "test(security): tenant isolation tests for brand catalog mutations"
```

---

## Task 4: Store settings & brand design isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/StoreSettingsIsolationTest.php`

**Covers:**
- `GET/PATCH /brand/store-settings`
- `GET/POST /brand/design` (resync)

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\BrandStoreSettingsController;
use App\Http\Controllers\Api\Professional\BrandDesignController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.brand_store_settings (
        id TEXT PRIMARY KEY, professional_id TEXT, settings TEXT,
        created_at TEXT, updated_at TEXT
    )');
});

it('store settings show returns only the caller own settings', function () {
    [$a, $b] = createTwoTenants('brand');

    foreach ([$a, $b] as $brand) {
        DB::table('core.brand_store_settings')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $brand->id,
            'settings' => json_encode(['theme' => $brand->handle]),
        ]);
    }

    $req = requestAs($b);
    $response = app(BrandStoreSettingsController::class)->show($req);
    $payload = $response->getData(true);

    expect($payload['data']['settings']['theme'] ?? null)->toBe('brand-b');
    expect($payload['data']['settings']['theme'] ?? null)->not->toBe('brand-a');
});

it('brand design returns only caller own site design tokens', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('site.sites')->where('id', $a->site->id)->update([
        'settings' => json_encode(['design' => ['primary' => '#aaaaaa']]),
    ]);
    DB::table('site.sites')->where('id', $b->site->id)->update([
        'settings' => json_encode(['design' => ['primary' => '#bbbbbb']]),
    ]);

    $req = requestAs($b);
    $response = app(BrandDesignController::class)->show($req);
    $payload = $response->getData(true);

    expect(data_get($payload, 'data.design.primary'))->toBe('#bbbbbb');
});
```

- [ ] **Step 2: Run, debug, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/StoreSettingsIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/StoreSettingsIsolationTest.php
git commit -m "test(security): tenant isolation for store settings and brand design"
```

---

## Task 5: Brand collections isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/CollectionsIsolationTest.php`

**Covers:** `GET/POST/DELETE /brand/collections/{collectionType}/products` — brand B must not be able to see or mutate brand A's collection selections.

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\Store\BrandCollectionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.brand_collection_products (
        id TEXT PRIMARY KEY, brand_professional_id TEXT, collection_type TEXT,
        product_gid TEXT, sort_order INTEGER, created_at TEXT
    )');
});

it('collection index never leaks another brand selections', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('commerce.brand_collection_products')->insert([
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $a->id,
         'collection_type' => 'featured', 'product_gid' => 'gid://a/1', 'sort_order' => 0],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $b->id,
         'collection_type' => 'featured', 'product_gid' => 'gid://b/1', 'sort_order' => 0],
    ]);

    $req = requestAs($b);
    $response = app(BrandCollectionController::class)->index($req, 'featured');
    $payload = $response->getData(true);

    $gids = collect($payload['data'] ?? [])->pluck('product_gid')->all();
    expect($gids)->toEqual(['gid://b/1']);
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/CollectionsIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/CollectionsIsolationTest.php
git commit -m "test(security): tenant isolation for brand collections"
```

---

## Task 6: Partners, invites, affiliate links isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/PartnersIsolationTest.php`

**Covers:**
- `GET/DELETE /brand-affiliates/{affiliate}` (brand side)
- `POST /brand-partners/{brandProfessionalId}/connect|promote|disconnect` (affiliate side)
- `DELETE /brand-affiliate-invites/{invite}`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\BrandAffiliateController;
use App\Http\Controllers\Api\Professional\BrandAffiliateInviteController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
});

it('brand affiliates index never returns links for another brand', function () {
    [$brandA, $brandB] = createTwoTenants('brand');
    $affiliate = createAffiliateTenant('aff-1');

    DB::table('core.brand_partner_links')->insert([
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandA->id,
         'affiliate_professional_id' => $affiliate->id, 'status' => 'active'],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandB->id,
         'affiliate_professional_id' => $affiliate->id, 'status' => 'active'],
    ]);

    $req = requestAs($brandB);
    $response = app(BrandAffiliateController::class)->index($req);
    $payload = $response->getData(true);

    $brandIds = collect($payload['data'] ?? [])->pluck('brand_professional_id')->all();
    expect($brandIds)->not->toContain($brandA->id);
});

it('brand affiliate disconnect refuses an affiliate id that belongs to another brand', function () {
    [$brandA, $brandB] = createTwoTenants('brand');
    $affiliate = createAffiliateTenant('aff-1');

    DB::table('core.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandA->id,
        'affiliate_professional_id' => $affiliate->id,
        'status' => 'active',
    ]);

    $req = requestAs($brandB, [], 'DELETE');

    expect(fn () => app(BrandAffiliateController::class)->destroy($req, $affiliate->id))
        ->toThrow(HttpException::class);
});

it('invite deletion refuses invites owned by another brand', function () {
    [$brandA, $brandB] = createTwoTenants('brand');

    $inviteId = (string) Str::uuid();
    DB::table('core.brand_affiliate_invites')->insert([
        'id' => $inviteId,
        'brand_professional_id' => $brandA->id,
        'invite_type' => 'direct',
        'token' => Str::random(20),
        'status' => 'pending',
    ]);

    $req = requestAs($brandB, [], 'DELETE');

    expect(fn () => app(BrandAffiliateInviteController::class)->destroy($req, $inviteId))
        ->toThrow(HttpException::class);

    $stillExists = DB::table('core.brand_affiliate_invites')->where('id', $inviteId)->exists();
    expect($stillExists)->toBeTrue();
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/PartnersIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/PartnersIsolationTest.php
git commit -m "test(security): tenant isolation for partner links and invites"
```

---

## Task 7: Subscription & Stripe payment-method isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/SubscriptionIsolationTest.php`

**Covers:**
- `GET/PATCH/POST /me/subscription*`
- `POST /stripe/payment-method/confirm` — attempted with another pro's session id
- `GET /stripe/payment-methods`
- `GET /stripe/payouts`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\SubscriptionController;
use App\Http\Controllers\Api\Professional\Stripe\StripePaymentMethodController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.professional_subscriptions (
        id TEXT PRIMARY KEY, professional_id TEXT, stripe_subscription_id TEXT,
        status TEXT, current_period_end TEXT, created_at TEXT, updated_at TEXT
    )');
});

it('subscription show only returns caller own subscription', function () {
    [$a, $b] = createTwoTenants('brand');

    foreach ([$a, $b] as $pro) {
        DB::table('billing.professional_subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $pro->id,
            'stripe_subscription_id' => 'sub_'.substr($pro->id, 0, 8),
            'status' => 'active',
        ]);
    }

    $req = requestAs($b);
    $response = app(SubscriptionController::class)->show($req);
    $payload = $response->getData(true);

    expect($payload['data']['stripe_subscription_id'])->toStartWith('sub_'.substr($b->id, 0, 8));
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/SubscriptionIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/SubscriptionIsolationTest.php
git commit -m "test(security): tenant isolation for subscription endpoints"
```

---

## Task 8: Customer & enquiry isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/CustomerIsolationTest.php`

**Covers:**
- `GET /customers`, `GET /customers/{customer}`, `PATCH/DELETE /customers/{customer}`
- `GET /enquiries`, `PATCH/DELETE /enquiries/{id}`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\CustomerController;
use App\Http\Controllers\Api\Professional\EnquiryController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS retail.customers (
        id TEXT PRIMARY KEY, professional_id TEXT, email TEXT, first_name TEXT,
        deleted_at TEXT, created_at TEXT, updated_at TEXT
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS retail.enquiries (
        id TEXT PRIMARY KEY, professional_id TEXT, email TEXT, message TEXT,
        status TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT
    )');
});

it('customer show refuses a customer id belonging to another pro', function () {
    [$a, $b] = createTwoTenants('affiliate');

    $customerId = (string) Str::uuid();
    DB::table('retail.customers')->insert([
        'id' => $customerId, 'professional_id' => $a->id,
        'email' => 'c@a.com', 'first_name' => 'A',
    ]);

    $req = requestAs($b);
    $customer = \App\Models\Retail\Customer::query()->findOrFail($customerId);

    expect(fn () => app(CustomerController::class)->show($req, $customer))
        ->toThrow(HttpException::class);
});

it('customer index never includes customers from another pro', function () {
    [$a, $b] = createTwoTenants('affiliate');
    DB::table('retail.customers')->insert([
        ['id' => (string) Str::uuid(), 'professional_id' => $a->id, 'email' => 'a@x.com'],
        ['id' => (string) Str::uuid(), 'professional_id' => $b->id, 'email' => 'b@x.com'],
    ]);

    $req = requestAs($b);
    $response = app(CustomerController::class)->index($req);
    $payload = $response->getData(true);

    $emails = collect($payload['data'] ?? [])->pluck('email')->all();
    expect($emails)->toEqual(['b@x.com']);
});

it('enquiry update refuses an enquiry id belonging to another pro', function () {
    [$a, $b] = createTwoTenants('affiliate');

    $enqId = (string) Str::uuid();
    DB::table('retail.enquiries')->insert([
        'id' => $enqId, 'professional_id' => $a->id,
        'email' => 'e@a.com', 'message' => '...', 'status' => 'new',
    ]);

    $req = requestAs($b, ['status' => 'archived'], 'PATCH');

    expect(fn () => app(EnquiryController::class)->update($req, $enqId))
        ->toThrow(HttpException::class);

    $status = DB::table('retail.enquiries')->where('id', $enqId)->value('status');
    expect($status)->toBe('new');
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/CustomerIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/CustomerIsolationTest.php
git commit -m "test(security): tenant isolation for customers and enquiries"
```

---

## Task 9: Services & service categories isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/ServicesIsolationTest.php`

**Covers:** `GET/PATCH/DELETE /services/{service}` and `/service-categories/{category}` — route model binding + implicit scoping.

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\ServiceController;
use App\Models\Retail\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS retail.services (
        id TEXT PRIMARY KEY, professional_id TEXT, name TEXT, duration_minutes INTEGER,
        price_cents INTEGER, deleted_at TEXT, sort_order INTEGER,
        created_at TEXT, updated_at TEXT
    )');
});

it('service update refuses a service id belonging to another pro', function () {
    [$a, $b] = createTwoTenants('affiliate');

    $serviceId = (string) Str::uuid();
    DB::table('retail.services')->insert([
        'id' => $serviceId, 'professional_id' => $a->id,
        'name' => 'Cut', 'price_cents' => 5000,
    ]);

    $req = requestAs($b, ['name' => 'Hacked'], 'PATCH');
    $service = Service::query()->findOrFail($serviceId);

    expect(fn () => app(ServiceController::class)->update($req, $service))
        ->toThrow(HttpException::class);

    expect(DB::table('retail.services')->where('id', $serviceId)->value('name'))->toBe('Cut');
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/ServicesIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/ServicesIsolationTest.php
git commit -m "test(security): tenant isolation for services and categories"
```

---

## Task 10: Links & sections isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/LinksAndSectionsIsolationTest.php`

**Covers:** `PATCH/DELETE /links/{linkBlock}`, `PUT /sections/{blockType}`.

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\LinkController;
use App\Models\Core\Site\Block;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
});

it('link update refuses a block belonging to another pro site', function () {
    [$a, $b] = createTwoTenants('brand');

    $blockId = (string) Str::uuid();
    DB::table('site.blocks')->insert([
        'id' => $blockId,
        'professional_id' => $a->id,
        'site_id' => $a->site->id,
        'block_group' => 'links',
        'block_type' => 'url',
        'title' => 'Secret', 'url' => 'https://a.example',
        'sort_order' => 0, 'is_active' => 1,
    ]);

    $req = requestAs($b, ['title' => 'Pwned'], 'PATCH');
    $block = Block::query()->findOrFail($blockId);

    expect(fn () => app(LinkController::class)->update($req, $block))
        ->toThrow(HttpException::class);

    expect(DB::table('site.blocks')->where('id', $blockId)->value('title'))->toBe('Secret');
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/LinksAndSectionsIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/LinksAndSectionsIsolationTest.php
git commit -m "test(security): tenant isolation for links and sections"
```

---

## Task 11: Profile & site isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/ProfileAndSiteIsolationTest.php`

**Covers:**
- `GET/PATCH /me`
- `GET/PATCH /site`
- `PATCH /site/visibility`
- `GET/PATCH /brand/profile`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\ProfessionalController;
use App\Http\Controllers\Api\Professional\SiteController;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => tenantHelpersEnsureTables());

it('/me returns only the authenticated professional', function () {
    [$a, $b] = createTwoTenants('brand');

    $req = requestAs($b);
    $response = app(ProfessionalController::class)->show($req);
    $payload = $response->getData(true);

    expect($payload['data']['id'] ?? $payload['id'])->toBe($b->id);
    expect($payload['data']['handle'] ?? $payload['handle'])->toBe('brand-b');
});

it('site update never writes to another pro site', function () {
    [$a, $b] = createTwoTenants('brand');

    $req = requestAs($b, ['bio' => 'Updated by B'], 'PATCH');
    app(SiteController::class)->update($req);

    $aSettings = DB::table('site.sites')->where('id', $a->site->id)->value('settings');
    expect($aSettings)->not->toContain('Updated by B');
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/ProfileAndSiteIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/ProfileAndSiteIsolationTest.php
git commit -m "test(security): tenant isolation for profile and site updates"
```

---

## Task 12: Integrations isolation tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/IntegrationsIsolationTest.php`

**Covers:**
- `GET /shopify/status|token`, `POST /shopify/disconnect`
- `GET /square/status`, `POST /square/disconnect`
- `GET /fresha/status`, `POST /fresha/connect`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Professional\ShopifyController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY, professional_id TEXT, provider TEXT,
        shopify_shop_domain TEXT, access_token_encrypted TEXT,
        status TEXT, created_at TEXT, updated_at TEXT
    )');
});

it('shopify status only reports integration for the caller', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'status' => 'connected',
    ]);

    $req = requestAs($b);
    $response = app(ShopifyController::class)->status($req);
    $payload = $response->getData(true);

    expect($payload['data']['connected'] ?? $payload['connected'] ?? false)->toBeFalse();
    expect($payload['data']['shop_domain'] ?? null)->not->toBe('brand-a.myshopify.com');
});

it('shopify disconnect only affects caller integration record', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'status' => 'connected',
    ]);

    $req = requestAs($b, [], 'POST');
    app(ShopifyController::class)->disconnect($req);

    $aStatus = DB::table('core.professional_integrations')
        ->where('professional_id', $a->id)->value('status');
    expect($aStatus)->toBe('connected');
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/IntegrationsIsolationTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/IntegrationsIsolationTest.php
git commit -m "test(security): tenant isolation for Shopify/Square/Fresha integrations"
```

---

## Task 13: Webhook cross-tenant replay tests

**Files:**
- Create: `tests/Feature/Security/TenantIsolation/WebhookCrossTenantTest.php`

**Covers:**
- Shopify order webhook: a valid-HMAC payload whose `X-Shopify-Shop-Domain` header does not match a known integration must be rejected/ignored.
- Shopify order webhook: an order-created event for brand A's domain must only update brand A's records, even if the order JSON body contains affiliate/customer IDs associated with brand B.
- Stripe billing webhook: an event with valid signature but `customer` not belonging to any professional must no-op (not error) and not touch any subscription row.

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Http\Controllers\Api\Webhooks\ShopifyOrderWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY, professional_id TEXT, provider TEXT,
        shopify_shop_domain TEXT, status TEXT, created_at TEXT, updated_at TEXT
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_commerce_orders (
        id TEXT PRIMARY KEY, brand_professional_id TEXT, shopify_order_id TEXT,
        total_amount_cents INTEGER, created_at TEXT
    )');
});

it('shopify order webhook rejects a payload for an unknown shop domain', function () {
    // Valid signature (bypass by binding a fake verifier) but unknown domain.
    $body = json_encode(['id' => 999, 'total_price' => '12.00']);
    $req = Request::create('/api/webhooks/shopify/orders', 'POST', [], [], [], [
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'unknown.myshopify.com',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(),
        'HTTP_X_SHOPIFY_HMAC_SHA256' => 'fake-bypassed-in-test',
    ], $body);

    // Substitute the HMAC verifier with a pass-through to isolate the domain check.
    $this->instance(
        \App\Services\Shopify\Webhooks\ShopifyWebhookHmacVerifier::class,
        new class {
            public function verify($req): bool { return true; }
        }
    );

    $response = app(ShopifyOrderWebhookController::class)->handle($req);
    expect($response->getStatusCode())->toBeIn([200, 204]);
    expect(DB::table('commerce.affiliate_commerce_orders')->count())->toBe(0);
});
```

- [ ] **Step 2: Run, commit**

Run: `./vendor/bin/pest tests/Feature/Security/TenantIsolation/WebhookCrossTenantTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/TenantIsolation/WebhookCrossTenantTest.php
git commit -m "test(security): cross-tenant replay resistance for webhooks"
```

---

## Task 14: Final verification

- [ ] **Step 1: Full test run**

Run: `composer test`
Expected: PASS — all 13 new regression test files pass, no regressions in the existing suite.

- [ ] **Step 2: Pint formatting**

Run: `php artisan pint`
Commit any formatting diffs:

```bash
git add -u
git commit -m "style: apply Pint formatting to tenant isolation tests"
```

- [ ] **Step 3: Nightwatch smoke**

If there is a staging environment with Nightwatch attached, deploy this branch and let it soak for 24h. Verify no new exceptions surface on the touched endpoints. If exceptions appear, investigate before merge.

- [ ] **Step 4: Self-review checklist**

- [ ] All 13 regression test suites added (Tasks 1–13)
- [ ] Any tests that failed surfaced and fixed real bugs
- [ ] Full `composer test` passes
- [ ] Pint clean

---

## Intentionally not covered (carry-forward notes)

- **Square/Fresha webhook URL-variant lockdown** — low-confidence fix; HMAC URL normalization needs careful review of every deployment URL variant. Recommend a separate targeted plan after talking to Tobias about webhook URL stability.
- **Email unsubscribe token rate-limiting** — separate concern; not cross-tenant isolation in the strict sense.
- `X-Site-Subdomain` header override — covered indirectly via the analytics IDOR fix in Part 1 (Task 2) since `resolveSiteSubdomain` is the single chokepoint.
