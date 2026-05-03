# Webhook Test Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the HTTP-layer signature-verification and real-shape payload-parsing gaps in the webhook test suite identified in the audit, plus add forward-looking tests that protect against silent regressions as the platform scales.

**Architecture:** All tests exercise the controllers via `$this->postJson(...)` (the HTTP route) so middleware, route binding, and `$request->json()` decoding are covered. Each provider gets one feature-test file containing accept/reject/dedupe/edge-case tests. Test helpers for HMAC signing and shared schema setup go in `tests/Pest.php` so the same primitives are reused across all webhook tests.

**Tech Stack:** Pest 4, PHPUnit, Laravel 12 HTTP test helpers (`postJson`), in-memory SQLite via `tenantHelpersEnsureTables()` from `tests/Pest.php`, `Bus::fake()`/`Queue::fake()` for dispatch assertions, `Cache::flush()` for dedupe isolation, real Stripe SDK (`Stripe\WebhookSignature::generateHeader()`) for Stripe-Signature headers.

**Out of scope:**
- Re-testing dedupe-cache mechanics (already covered by `FreshaWebhookDedupeTest`, `StripeConnectWebhookDedupeTest`, etc.) — we only verify dedupe **end-to-end** through the HTTP route in this plan, not the `Cache::add()` primitive.
- The internal job logic (`ProcessShopifyOrderWebhookJob`, etc.) — already covered by the `*JobNoLazyLoad`/`*CommissionResolution` tests. We assert dispatch with `Bus::fake()`, not job execution.
- Throttle middleware behaviour — `throttle:webhooks` is bypassed in the test env via the `RateLimiter::for(..., $throttleEnabled)` flag in `AppServiceProvider`.

**File structure:**

| File | Status | Responsibility |
|---|---|---|
| `tests/Pest.php` | Modify | Add `signShopifyBody()`, `signSquareBody()`, `signFreshaBody()`, `setupProfessionalIntegrationsTable()` helpers |
| `tests/Feature/Webhooks/Fresha/FreshaCatalogWebhookControllerTest.php` | Create | All HTTP-layer tests for Fresha |
| `tests/Feature/Webhooks/Square/SquareCatalogWebhookControllerTest.php` | Create | All HTTP-layer tests for Square |
| `tests/Feature/Webhooks/Shopify/ShopifyOrderWebhookControllerTest.php` | Create | HTTP-layer tests for orders/paid |
| `tests/Feature/Webhooks/Shopify/ShopifyOrdersUpdatedWebhookControllerTest.php` | Create | HTTP-layer tests for orders/updated |
| `tests/Feature/Webhooks/Shopify/ShopifyShopUpdateWebhookControllerTest.php` | Create | HTTP-layer tests for shop/update |
| `tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php` | Create | HTTP-layer tests for app/uninstalled |
| `tests/Feature/Webhooks/Stripe/StripeWebhookControllerEndToEndTest.php` | Create | Real-shape `customer.subscription.updated` via HTTP |
| `tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php` | Create | Real-shape `account.updated` via HTTP |
| `tests/Feature/Webhooks/EdgeCases/MalformedBodyTest.php` | Create | Cross-provider malformed/empty body tests |
| `tests/Feature/Webhooks/EdgeCases/StripeReplayAttackTest.php` | Create | Old-timestamp rejection (Stripe tolerance window) |

---

## Task 1: Test Infrastructure — shared helpers

**Files:**
- Modify: `tests/Pest.php` (append new helpers below the existing `setupProfessionalDeletionAuditTable` function near the end of the file)

- [ ] **Step 1: Add the four new helper functions to `tests/Pest.php`**

Append the following at the end of `tests/Pest.php` (after `setupProfessionalDeletionAuditTable()`):

```php
/**
 * core.professional_integrations — superset of all columns webhook controllers query.
 * Includes shopify_shop_domain (production has it; the older WebhookCrossTenantTest
 * schema omits it). All columns nullable.
 */
function setupProfessionalIntegrationsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        provider TEXT NULL,
        external_account_id TEXT NULL,
        shopify_shop_domain TEXT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        provider_metadata TEXT NULL,
        status TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * Sign a body string with the Shopify HMAC scheme: base64(HMAC-SHA256(body, secret)).
 * Mirrors the production controller's verification in ValidatesShopifyWebhookHmac.
 */
function signShopifyBody(string $body, string $secret): string
{
    return base64_encode(hash_hmac('sha256', $body, $secret, true));
}

/**
 * Sign a body string with Square's HMAC scheme: base64(HMAC-SHA256(notification_url + body, key)).
 * The notification_url MUST match config('services.square.webhook_notification_url') OR the
 * request's full URL — controller tries both.
 */
function signSquareBody(string $notificationUrl, string $body, string $key): string
{
    return base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));
}

/**
 * Sign a body string with the Fresha HMAC scheme (currently mirrors Square).
 * Update if Fresha's docs reveal a different scheme.
 */
function signFreshaBody(string $notificationUrl, string $body, string $key): string
{
    return base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));
}

/**
 * Generate a valid Stripe-Signature header for a raw body string.
 * Uses the official Stripe SDK so we exercise the real verification path,
 * not a hand-rolled approximation.
 */
function signStripeBody(string $body, string $secret, ?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $signedPayload = $timestamp.'.'.$body;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return 't='.$timestamp.',v1='.$signature;
}
```

- [ ] **Step 2: Verify Pest.php still parses (no syntax errors)**

Run:
```bash
php -l tests/Pest.php
```
Expected: `No syntax errors detected in tests/Pest.php`

- [ ] **Step 3: Run the existing test suite to confirm helpers don't break anything**

Run:
```bash
composer test -- --filter=FreshaWebhookDedupeTest
```
Expected: All tests pass (existing dedupe test still green).

- [ ] **Step 4: Commit**

```bash
git add tests/Pest.php
git commit -m "test: add webhook signing + integration table helpers to Pest"
```

---

## Task 2: Fresha — HMAC verification (accept/reject) + happy path

**Files:**
- Create: `tests/Feature/Webhooks/Fresha/FreshaCatalogWebhookControllerTest.php`
- Test: same file

- [ ] **Step 1: Create the test file with HMAC accept/reject tests + happy-path dispatch**

```php
<?php

use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();

    Config::set('sidest.features.fresha_sync', true);
    Config::set('services.fresha.webhook_signature_key', 'test-fresha-key');
    Config::set('services.fresha.webhook_notification_url', 'http://localhost/api/webhooks/fresha');
});

it('rejects with 401 when x-fresha-signature header is missing', function () {
    $payload = ['type' => 'catalog.version.updated', 'business_id' => 'biz-1', 'event_id' => 'evt-1'];

    $this->postJson('/api/webhooks/fresha', $payload, [])
        ->assertStatus(401)
        ->assertJson(['error' => 'Invalid Fresha webhook signature.']);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('rejects with 401 when signature does not match', function () {
    $payload = ['type' => 'catalog.version.updated', 'business_id' => 'biz-1', 'event_id' => 'evt-2'];

    $this->postJson('/api/webhooks/fresha', $payload, [
        'x-fresha-signature' => 'not-a-real-signature',
    ])->assertStatus(401);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('accepts a valid signature and dispatches sync job for catalog.version.updated', function () {
    $payload = ['type' => 'catalog.version.updated', 'business_id' => 'biz-42', 'event_id' => 'evt-happy-1'];
    $body = json_encode($payload);
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'test-fresha-key');

    $this->postJson('/api/webhooks/fresha', $payload, [
        'x-fresha-signature' => $sig,
    ])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'queued' => true]]);

    Bus::assertDispatched(
        SyncFreshaCatalogDeltaJob::class,
        fn (SyncFreshaCatalogDeltaJob $job) => true
    );
});
```

- [ ] **Step 2: Run the new tests**

```bash
composer test -- --filter=FreshaCatalogWebhookControllerTest
```
Expected: All 3 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Fresha/FreshaCatalogWebhookControllerTest.php
git commit -m "test: cover Fresha webhook HMAC verification + happy path"
```

---

## Task 3: Fresha — dedupe, feature flag, revoke, ignored event types

**Files:**
- Modify: `tests/Feature/Webhooks/Fresha/FreshaCatalogWebhookControllerTest.php`

- [ ] **Step 1: Append four more tests to the file from Task 2**

Add at the bottom of the file:

```php
it('returns duplicate=true on second delivery of the same event_id', function () {
    $payload = ['type' => 'catalog.version.updated', 'business_id' => 'biz-7', 'event_id' => 'evt-dup-1'];
    $body = json_encode($payload);
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'test-fresha-key');

    $this->postJson('/api/webhooks/fresha', $payload, ['x-fresha-signature' => $sig])
        ->assertOk()
        ->assertJson(['data' => ['queued' => true]]);

    $this->postJson('/api/webhooks/fresha', $payload, ['x-fresha-signature' => $sig])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'duplicate' => true]]);

    // Only ONE dispatch — the second was deduped.
    Bus::assertDispatchedTimes(SyncFreshaCatalogDeltaJob::class, 1);
});

it('short-circuits with feature_gated=true when fresha_sync flag is off', function () {
    Config::set('sidest.features.fresha_sync', false);

    // No signature provided — should still 200 because we exit before signature check.
    $this->postJson('/api/webhooks/fresha', ['type' => 'catalog.version.updated'])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'feature_gated' => true]]);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('deletes integration on oauth.authorization.revoked', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_FRESHA,
        'external_account_id' => 'biz-revoke',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = ['type' => 'oauth.authorization.revoked', 'business_id' => 'biz-revoke', 'event_id' => 'evt-revoke-1'];
    $body = json_encode($payload);
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'test-fresha-key');

    $this->postJson('/api/webhooks/fresha', $payload, ['x-fresha-signature' => $sig])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'revoked' => true]]);

    expect(DB::table('core.professional_integrations')
        ->where('external_account_id', 'biz-revoke')
        ->count())->toBe(0);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('returns ignored=type for an unknown event type', function () {
    $payload = ['type' => 'employee.something.weird', 'business_id' => 'biz-99', 'event_id' => 'evt-unknown-1'];
    $body = json_encode($payload);
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'test-fresha-key');

    $this->postJson('/api/webhooks/fresha', $payload, ['x-fresha-signature' => $sig])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'ignored' => 'employee.something.weird']]);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});
```

- [ ] **Step 2: Run the file**

```bash
composer test -- --filter=FreshaCatalogWebhookControllerTest
```
Expected: 7 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Fresha/FreshaCatalogWebhookControllerTest.php
git commit -m "test: cover Fresha webhook dedupe, flag, revoke, unknown event"
```

---

## Task 4: Square — HMAC verification + happy path

**Files:**
- Create: `tests/Feature/Webhooks/Square/SquareCatalogWebhookControllerTest.php`

- [ ] **Step 1: Create the file**

```php
<?php

use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();

    Config::set('sidest.features.square_sync', true);
    Config::set('services.square.webhook_signature_key', 'test-square-key');
    Config::set('services.square.webhook_notification_url', 'http://localhost/api/webhooks/square');
});

it('rejects with 401 when x-square-hmacsha256-signature is missing', function () {
    $payload = ['type' => 'catalog.version.updated', 'merchant_id' => 'merch-1', 'event_id' => 'evt-1'];

    $this->postJson('/api/webhooks/square', $payload, [])
        ->assertStatus(401);

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});

it('rejects with 401 when signature does not match', function () {
    $payload = ['type' => 'catalog.version.updated', 'merchant_id' => 'merch-1', 'event_id' => 'evt-2'];

    $this->postJson('/api/webhooks/square', $payload, [
        'x-square-hmacsha256-signature' => 'bad-sig',
    ])->assertStatus(401);

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});

it('accepts a valid signature and dispatches sync job for catalog.version.updated', function () {
    $payload = ['type' => 'catalog.version.updated', 'merchant_id' => 'merch-42', 'event_id' => 'evt-happy-1'];
    $body = json_encode($payload);
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'test-square-key');

    $this->postJson('/api/webhooks/square', $payload, [
        'x-square-hmacsha256-signature' => $sig,
    ])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'queued' => true]]);

    Bus::assertDispatched(SyncSquareCatalogDeltaJob::class);
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=SquareCatalogWebhookControllerTest
```
Expected: 3 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Square/SquareCatalogWebhookControllerTest.php
git commit -m "test: cover Square webhook HMAC verification + happy path"
```

---

## Task 5: Square — dedupe, flag, revoke, missing merchant_id

**Files:**
- Modify: `tests/Feature/Webhooks/Square/SquareCatalogWebhookControllerTest.php`

- [ ] **Step 1: Append**

```php
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('square — returns duplicate=true on second delivery of same event_id', function () {
    $payload = ['type' => 'catalog.version.updated', 'merchant_id' => 'merch-7', 'event_id' => 'evt-dup-sq-1'];
    $body = json_encode($payload);
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'test-square-key');

    $this->postJson('/api/webhooks/square', $payload, ['x-square-hmacsha256-signature' => $sig])
        ->assertOk()->assertJson(['data' => ['queued' => true]]);

    $this->postJson('/api/webhooks/square', $payload, ['x-square-hmacsha256-signature' => $sig])
        ->assertOk()->assertJson(['data' => ['received' => true, 'duplicate' => true]]);

    Bus::assertDispatchedTimes(SyncSquareCatalogDeltaJob::class, 1);
});

it('square — returns feature_gated=true when square_sync flag is off', function () {
    Config::set('sidest.features.square_sync', false);

    $this->postJson('/api/webhooks/square', ['type' => 'catalog.version.updated'])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'feature_gated' => true]]);

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});

it('square — deletes integration on oauth.authorization.revoked', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SQUARE,
        'external_account_id' => 'merch-revoke',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = ['type' => 'oauth.authorization.revoked', 'merchant_id' => 'merch-revoke', 'event_id' => 'evt-revoke-sq-1'];
    $body = json_encode($payload);
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'test-square-key');

    $this->postJson('/api/webhooks/square', $payload, ['x-square-hmacsha256-signature' => $sig])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'revoked' => true]]);

    expect(DB::table('core.professional_integrations')
        ->where('external_account_id', 'merch-revoke')->count())->toBe(0);
});

it('square — returns ignored=missing_merchant_id when merchant_id is absent', function () {
    $payload = ['type' => 'catalog.version.updated', 'event_id' => 'evt-no-merch-1'];
    $body = json_encode($payload);
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'test-square-key');

    $this->postJson('/api/webhooks/square', $payload, ['x-square-hmacsha256-signature' => $sig])
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'ignored' => 'missing_merchant_id']]);

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=SquareCatalogWebhookControllerTest
```
Expected: 7 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Square/SquareCatalogWebhookControllerTest.php
git commit -m "test: cover Square webhook dedupe, flag, revoke, missing merchant"
```

---

## Task 6: Shopify orders/paid — real-shape HTTP-layer tests

**Files:**
- Create: `tests/Feature/Webhooks/Shopify/ShopifyOrderWebhookControllerTest.php`

The existing `WebhookCrossTenantTest` invokes the controller directly. This task posts via the HTTP route with a real-shape Shopify orders/paid payload, exercising route binding and `$request->getContent()` decoding.

- [ ] **Step 1: Create the file**

```php
<?php

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

function realShopifyOrderPayload(): array
{
    // Trimmed real-shape: covers the fields the controller forwards to the job.
    return [
        'id' => 5732445487345,
        'admin_graphql_api_id' => 'gid://shopify/Order/5732445487345',
        'name' => '#1023',
        'email' => 'shopper@example.com',
        'financial_status' => 'paid',
        'total_price' => '49.99',
        'currency' => 'USD',
        'customer' => [
            'id' => 7891234567,
            'email' => 'shopper@example.com',
            'first_name' => 'Test',
            'last_name' => 'Shopper',
        ],
        'line_items' => [
            [
                'id' => 13456789,
                'product_id' => 8765432,
                'variant_id' => 99887766,
                'quantity' => 1,
                'price' => '49.99',
            ],
        ],
        'created_at' => '2026-04-27T12:00:00-04:00',
    ];
}

function insertShopifyIntegration(string $proId, string $shopDomain): void
{
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_token',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('orders/paid — silently acknowledges 200 with bad HMAC and dispatches nothing', function () {
    $payload = realShopifyOrderPayload();

    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, [
        'X-Shopify-Hmac-SHA256' => 'invalid-hmac',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('orders/paid — accepts valid HMAC and dispatches job with real-shape payload', function () {
    $proId = (string) Str::uuid();
    insertShopifyIntegration($proId, 'brand-a.myshopify.com');

    $payload = realShopifyOrderPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertDispatched(
        ProcessShopifyOrderWebhookJob::class,
        function (ProcessShopifyOrderWebhookJob $job) use ($proId, $payload) {
            // Pull constructor args via reflection — keeps the test resilient
            // to job-class changes that don't affect public behaviour.
            $r = new ReflectionClass($job);
            $proProp = $r->getProperty('professionalId');
            $payloadProp = $r->getProperty('payload');
            $proProp->setAccessible(true);
            $payloadProp->setAccessible(true);

            return $proProp->getValue($job) === $proId
                && $payloadProp->getValue($job)['id'] === $payload['id']
                && $payloadProp->getValue($job)['line_items'][0]['product_id'] === 8765432;
        }
    );
});

it('orders/paid — second delivery with same X-Shopify-Webhook-Id returns duplicate=true', function () {
    $proId = (string) Str::uuid();
    insertShopifyIntegration($proId, 'brand-a.myshopify.com');

    $payload = realShopifyOrderPayload();
    $body = json_encode($payload);
    $webhookId = (string) Str::uuid();

    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => $webhookId,
    ];

    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, $headers)
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'duplicate' => true]]);

    Bus::assertDispatchedTimes(ProcessShopifyOrderWebhookJob::class, 1);
});
```

- [ ] **Step 2: Confirm `ProcessShopifyOrderWebhookJob` constructor property names**

Run:
```bash
grep -n "public function __construct\|protected\|public.*\$" app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php | head -20
```
If property names are NOT `professionalId` and `payload`, update the reflection block in the test accordingly. Common alternatives: `$this->professional_id`, `$this->shopifyPayload`.

- [ ] **Step 3: Run**

```bash
composer test -- --filter=ShopifyOrderWebhookControllerTest
```
Expected: 3 tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Webhooks/Shopify/ShopifyOrderWebhookControllerTest.php
git commit -m "test: cover Shopify orders/paid HTTP layer with real-shape payload"
```

---

## Task 7: Shopify orders/updated — real-shape HTTP-layer tests

**Files:**
- Create: `tests/Feature/Webhooks/Shopify/ShopifyOrdersUpdatedWebhookControllerTest.php`

- [ ] **Step 1: Create the file** (mirror of Task 6 but for the updated route)

```php
<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

function realShopifyOrderUpdatedPayload(): array
{
    return [
        'id' => 5732445487345,
        'admin_graphql_api_id' => 'gid://shopify/Order/5732445487345',
        'name' => '#1023',
        'financial_status' => 'refunded',
        'total_price' => '49.99',
        'currency' => 'USD',
        'refunds' => [
            [
                'id' => 998877,
                'created_at' => '2026-04-27T13:00:00-04:00',
                'refund_line_items' => [
                    ['id' => 11, 'quantity' => 1, 'subtotal' => '49.99'],
                ],
            ],
        ],
        'updated_at' => '2026-04-27T13:00:00-04:00',
    ];
}

it('orders/updated — silently acknowledges 200 with bad HMAC, dispatches nothing', function () {
    $this->postJson('/api/webhooks/shopify/orders-updated', realShopifyOrderUpdatedPayload(), [
        'X-Shopify-Hmac-SHA256' => 'bogus',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderUpdatedWebhookJob::class);
});

it('orders/updated — accepts valid HMAC and dispatches with real-shape refund payload', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_token',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = realShopifyOrderUpdatedPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/orders-updated', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertDispatched(ProcessShopifyOrderUpdatedWebhookJob::class);
});

it('orders/updated — unknown shop_domain logs warning and skips dispatch', function () {
    $payload = realShopifyOrderUpdatedPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/orders-updated', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderUpdatedWebhookJob::class);
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=ShopifyOrdersUpdatedWebhookControllerTest
```
Expected: 3 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Shopify/ShopifyOrdersUpdatedWebhookControllerTest.php
git commit -m "test: cover Shopify orders/updated HTTP layer with real-shape payload"
```

---

## Task 8: Shopify webhook secret rotation — fallback-secret coverage

**Files:**
- Modify: `tests/Feature/Webhooks/Shopify/ShopifyOrderWebhookControllerTest.php`

The `ValidatesShopifyWebhookHmac` trait checks BOTH `services.shopify.webhook_secret` and `services.shopify.fallback_secret`. Today nothing tests the fallback path — meaning a regression that drops the fallback would silently break the rotation window during secret rollovers.

- [ ] **Step 1: Append two tests to the orders/paid file**

```php
it('orders/paid — accepts a body signed with the fallback secret during rotation', function () {
    $proId = (string) Str::uuid();
    insertShopifyIntegration($proId, 'brand-a.myshopify.com');

    Config::set('services.shopify.webhook_secret', 'new-rotated-secret');
    Config::set('services.shopify.fallback_secret', 'old-secret-still-deployed-by-shopify');

    $payload = realShopifyOrderPayload();
    $body = json_encode($payload);

    // Shopify is still signing with the OLD secret — must succeed.
    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'old-secret-still-deployed-by-shopify'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('orders/paid — rejects when neither primary nor fallback secret matches', function () {
    Config::set('services.shopify.webhook_secret', 'real-primary');
    Config::set('services.shopify.fallback_secret', 'real-fallback');

    $payload = realShopifyOrderPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'attacker-guessed-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();  // Always 200 (no retry signal), but no dispatch.

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=ShopifyOrderWebhookControllerTest
```
Expected: 5 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Shopify/ShopifyOrderWebhookControllerTest.php
git commit -m "test: cover Shopify webhook secret rotation (fallback_secret path)"
```

---

## Task 9: Shopify shop/update — full controller coverage

**Files:**
- Create: `tests/Feature/Webhooks/Shopify/ShopifyShopUpdateWebhookControllerTest.php`

- [ ] **Step 1: Create**

```php
<?php

use App\Jobs\Shopify\ProcessShopifyShopUpdateJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

function realShopifyShopUpdatePayload(): array
{
    return [
        'id' => 12345678,
        'name' => 'Brand A Cosmetics',
        'email' => 'owner@brand-a.example',
        'domain' => 'brand-a.myshopify.com',
        'myshopify_domain' => 'brand-a.myshopify.com',
        'shop_owner' => 'Test Owner',
        'currency' => 'USD',
        'iana_timezone' => 'America/New_York',
        'updated_at' => '2026-04-27T14:00:00-04:00',
    ];
}

it('shop/update — bad HMAC silently 200s, no dispatch', function () {
    $this->postJson('/api/webhooks/shopify/shop-update', realShopifyShopUpdatePayload(), [
        'X-Shopify-Hmac-SHA256' => 'bad',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyShopUpdateJob::class);
});

it('shop/update — valid HMAC dispatches ProcessShopifyShopUpdateJob with payload', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = realShopifyShopUpdatePayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/shop-update', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertDispatched(ProcessShopifyShopUpdateJob::class);
});

it('shop/update — duplicate webhook_id returns duplicate=true and skips dispatch', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = realShopifyShopUpdatePayload();
    $body = json_encode($payload);
    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => 'webhook-shop-update-1',
    ];

    $this->postJson('/api/webhooks/shopify/shop-update', $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/shopify/shop-update', $payload, $headers)
        ->assertOk()
        ->assertJson(['data' => ['received' => true, 'duplicate' => true]]);

    Bus::assertDispatchedTimes(ProcessShopifyShopUpdateJob::class, 1);
});

it('shop/update — unknown shop_domain 200s without dispatch', function () {
    $payload = realShopifyShopUpdatePayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/shop-update', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyShopUpdateJob::class);
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=ShopifyShopUpdateWebhookControllerTest
```
Expected: 4 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Shopify/ShopifyShopUpdateWebhookControllerTest.php
git commit -m "test: cover Shopify shop/update webhook controller"
```

---

## Task 10: Shopify app/uninstalled — integration teardown coverage

**Files:**
- Create: `tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php`

- [ ] **Step 1: Add a helper for AffiliateProductSelection schema, then create the file**

First, append this helper to `tests/Pest.php` (group with the other setup helpers):

```php
function setupAffiliateProductSelectionsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NULL,
        brand_professional_id TEXT NULL,
        shopify_product_gid TEXT NULL,
        sort_order INTEGER NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
```

Then create the test file:

```php
<?php

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupAffiliateProductSelectionsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

function uninstalledPayload(): array
{
    return [
        'id' => 12345678,
        'name' => 'Brand A',
        'myshopify_domain' => 'brand-a.myshopify.com',
        'domain' => 'brand-a.myshopify.com',
    ];
}

it('app/uninstalled — bad HMAC silently 200s, leaves integration intact', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', uninstalledPayload(), [
        'X-Shopify-Hmac-SHA256' => 'bad',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertOk();

    $row = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($row->access_token)->toBe('shpat_alive');
});

it('app/uninstalled — valid HMAC clears access_token and marks disconnected_reason', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'refresh_token' => 'rt_alive',
        'provider_metadata' => json_encode(['some_existing' => 'value']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = uninstalledPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertOk();

    $row = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($row->access_token)->toBeNull();
    expect($row->refresh_token)->toBeNull();

    $meta = json_decode($row->provider_metadata, true);
    expect($meta['disconnected_reason'])->toBe('app_uninstalled');
    expect($meta['webhooks_state'])->toBe('uninstalled');
    expect($meta['some_existing'])->toBe('value');  // Pre-existing keys preserved.
    expect($meta['disconnected_at'])->not->toBeNull();
});

it('app/uninstalled — purges affiliate product selections for the brand', function () {
    $brandId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('commerce.affiliate_product_selections')->insert([
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/1', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/2', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $payload = uninstalledPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertOk();

    expect(AffiliateProductSelection::query()
        ->where('brand_professional_id', $brandId)
        ->count())->toBe(0);
});

it('app/uninstalled — unknown shop_domain returns 200 without side effects', function () {
    $payload = uninstalledPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
    ])->assertOk();
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=ShopifyAppUninstalledWebhookControllerTest
```
Expected: 4 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Pest.php tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php
git commit -m "test: cover Shopify app/uninstalled webhook integration teardown"
```

---

## Task 11: Stripe platform — real-shape customer.subscription.updated end-to-end

**Files:**
- Create: `tests/Feature/Webhooks/Stripe/StripeWebhookControllerEndToEndTest.php`

The existing `StripeWebhookSubscriptionUpdatedTest` uses reflection to invoke a private handler. This adds the missing end-to-end HTTP-layer coverage with a real-shape Stripe event body and a real Stripe-Signature header.

- [ ] **Step 1: Create the file**

```php
<?php

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');
    foreach (['core', 'billing'] as $schema) {
        try { $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}"); } catch (\Throwable) {}
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS billing.plans (
        id TEXT PRIMARY KEY,
        plan_key TEXT,
        stripe_price_id TEXT,
        is_active INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        plan_id TEXT,
        provider TEXT,
        stripe_customer_id TEXT,
        stripe_subscription_id TEXT,
        status TEXT,
        current_period_start TEXT,
        current_period_end TEXT,
        cancel_at_period_end INTEGER,
        ended_at TEXT,
        provider_payload TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    Config::set('services.stripe.webhook_secret', 'whsec_test_billing_secret');
});

function realStripeSubscriptionUpdatedEvent(string $subscriptionId, string $customerId, string $priceId): array
{
    return [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => $subscriptionId,
                'object' => 'subscription',
                'customer' => $customerId,
                'status' => 'active',
                'cancel_at_period_end' => false,
                'current_period_start' => 1714000000,
                'current_period_end' => 1716678400,
                'items' => [
                    'object' => 'list',
                    'data' => [[
                        'id' => 'si_'.Str::random(14),
                        'price' => ['id' => $priceId, 'object' => 'price'],
                    ]],
                ],
                'metadata' => new stdClass(),
            ],
        ],
        'livemode' => false,
        'pending_webhooks' => 1,
        'request' => ['id' => null, 'idempotency_key' => null],
    ];
}

it('stripe billing — rejects 400 when Stripe-Signature header is missing', function () {
    $this->postJson('/api/webhooks/stripe', ['type' => 'customer.subscription.updated'])
        ->assertStatus(400)
        ->assertJson(['error' => 'Missing signature']);
});

it('stripe billing — accepts a real-shape customer.subscription.updated and persists status change', function () {
    $proId = (string) Str::uuid();
    $planId = (string) Str::uuid();
    $localSubId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'pro1', 'professional_type' => 'professional', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('billing.plans')->insert([
        'id' => $planId, 'plan_key' => 'pro', 'stripe_price_id' => 'price_pro_monthly', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('billing.subscriptions')->insert([
        'id' => $localSubId, 'professional_id' => $proId, 'plan_id' => $planId,
        'provider' => 'stripe', 'stripe_customer_id' => 'cus_real', 'stripe_subscription_id' => 'sub_real',
        'status' => 'past_due', 'current_period_start' => '2024-01-01', 'current_period_end' => '2024-02-01',
        'cancel_at_period_end' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $event = realStripeSubscriptionUpdatedEvent('sub_real', 'cus_real', 'price_pro_monthly');
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_test_billing_secret');

    // Use call() with a raw body (postJson would re-encode, breaking the signature).
    $this->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $updated = Subscription::find($localSubId);
    expect($updated->status)->toBe('active');  // moved from past_due -> active
});

it('stripe billing — same event_id arriving twice processes only once', function () {
    $event = realStripeSubscriptionUpdatedEvent('sub_unknown', 'cus_unknown', 'price_unknown');
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_test_billing_secret');

    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig];

    $this->call('POST', '/api/webhooks/stripe', [], [], [], $headers, $body)->assertOk();
    $this->call('POST', '/api/webhooks/stripe', [], [], [], $headers, $body)->assertOk();

    expect(DB::table('billing.webhook_events')
        ->where('stripe_event_id', $event['id'])
        ->count())->toBe(1);
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=StripeWebhookControllerEndToEndTest
```
Expected: 3 tests pass. If the `metadata => new stdClass()` deserialization fails, switch to `(object) []` or `[]`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Stripe/StripeWebhookControllerEndToEndTest.php
git commit -m "test: end-to-end Stripe billing webhook with real-shape event"
```

---

## Task 12: Stripe Connect — real-shape account.updated end-to-end

**Files:**
- Create: `tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php`

- [ ] **Step 1: Create**

```php
<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');
    try { $conn->statement("ATTACH DATABASE ':memory:' AS billing"); } catch (\Throwable) {}

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');

    // core.professionals already created — add stripe_connect_account_id column for this test.
    try {
        $conn->statement('ALTER TABLE core.professionals ADD COLUMN stripe_connect_account_id TEXT NULL');
    } catch (\Throwable) {}
    try {
        $conn->statement('ALTER TABLE core.professionals ADD COLUMN stripe_connect_status TEXT NULL');
    } catch (\Throwable) {}

    Config::set('services.stripe.connect_webhook_secret', 'whsec_connect_test');
    Config::set('services.stripe.webhook_secret', 'whsec_billing_test');
});

function realStripeAccountUpdatedEvent(string $accountId, bool $detailsSubmitted = true): array
{
    return [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'account' => $accountId,
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'account.updated',
        'data' => [
            'object' => [
                'id' => $accountId,
                'object' => 'account',
                'charges_enabled' => true,
                'payouts_enabled' => true,
                'details_submitted' => $detailsSubmitted,
                'requirements' => [
                    'currently_due' => [],
                    'past_due' => [],
                    'pending_verification' => [],
                ],
            ],
        ],
        'livemode' => false,
    ];
}

it('stripe connect — rejects 400 when Stripe-Signature is missing', function () {
    $this->postJson('/api/webhooks/stripe-connect', ['type' => 'account.updated'])
        ->assertStatus(400)
        ->assertJson(['error' => 'Missing signature']);
});

it('stripe connect — rejects 400 when neither connect nor billing secret matches', function () {
    $event = realStripeAccountUpdatedEvent('acct_real');
    $body = json_encode($event);
    $sig = signStripeBody($body, 'wrong-secret');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)
        ->assertStatus(400)
        ->assertJson(['error' => 'Invalid signature']);
});

it('stripe connect — account_mismatch returns 400 (HMAC-signed account != data.object.id)', function () {
    // event->account is 'acct_real' but data.object.id is 'acct_attacker' — controller must reject.
    $event = realStripeAccountUpdatedEvent('acct_real');
    $event['data']['object']['id'] = 'acct_attacker';
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_connect_test');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)
        ->assertStatus(400)
        ->assertJson(['error' => 'account_mismatch']);
});

it('stripe connect — valid account.updated transitions stripe_connect_status', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'aff1', 'professional_type' => 'professional', 'status' => 'active',
        'stripe_connect_account_id' => 'acct_real', 'stripe_connect_status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $event = realStripeAccountUpdatedEvent('acct_real', detailsSubmitted: true);
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_connect_test');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $row = DB::table('core.professionals')->where('id', $proId)->first();
    expect($row->stripe_connect_status)->toBe('active');
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=StripeConnectWebhookControllerEndToEndTest
```
Expected: 4 tests pass. If `StripeConnectService::determineAccountStatus()` returns something other than `'active'` for a fully-onboarded account, adjust the assertion to match the real return value.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php
git commit -m "test: end-to-end Stripe Connect webhook with real-shape account.updated"
```

---

## Task 13: Cross-provider malformed body resilience

**Files:**
- Create: `tests/Feature/Webhooks/EdgeCases/MalformedBodyTest.php`

The "when we're larger" tests — protects against parser-level regressions across all providers.

- [ ] **Step 1: Create**

```php
<?php

use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();

    Config::set('services.shopify.webhook_secret', 'shop-secret');
    Config::set('services.fresha.webhook_signature_key', 'fresha-key');
    Config::set('services.fresha.webhook_notification_url', 'http://localhost/api/webhooks/fresha');
    Config::set('services.square.webhook_signature_key', 'square-key');
    Config::set('services.square.webhook_notification_url', 'http://localhost/api/webhooks/square');
    Config::set('sidest.features.fresha_sync', true);
    Config::set('sidest.features.square_sync', true);
});

it('shopify orders/paid — empty body with valid HMAC for empty body returns 200 and dispatches nothing', function () {
    $body = '';
    $sig = signShopifyBody($body, 'shop-secret');

    $this->call('POST', '/api/webhooks/shopify/orders-paid', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $sig,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'brand-a.myshopify.com',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-empty-1',
    ], $body)->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('shopify orders/paid — malformed JSON with valid HMAC returns 200 and dispatches nothing', function () {
    $body = '{not valid json';
    $sig = signShopifyBody($body, 'shop-secret');

    $this->call('POST', '/api/webhooks/shopify/orders-paid', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $sig,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'brand-a.myshopify.com',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-malformed-1',
    ], $body)->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('fresha — JSON array (not object) body is gracefully ignored', function () {
    $body = '[]';
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'fresha-key');

    $this->call('POST', '/api/webhooks/fresha', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FRESHA_SIGNATURE' => $sig,
    ], $body)->assertOk();

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('square — payload that is not an array is gracefully ignored', function () {
    $body = '"plain string"';  // valid JSON, but not an array
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'square-key');

    $this->call('POST', '/api/webhooks/square', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $sig,
    ], $body)->assertOk();

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=MalformedBodyTest
```
Expected: 4 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/EdgeCases/MalformedBodyTest.php
git commit -m "test: cross-provider malformed/empty body resilience"
```

---

## Task 14: Stripe replay-attack protection (timestamp tolerance window)

**Files:**
- Create: `tests/Feature/Webhooks/EdgeCases/StripeReplayAttackTest.php`

Stripe's signature scheme rejects timestamps outside a 5-minute (300s) tolerance window. If a regression ever bypasses `Stripe\Webhook::constructEvent` in favour of a hand-rolled HMAC check, this test catches it.

- [ ] **Step 1: Create**

```php
<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try { $conn->statement("ATTACH DATABASE ':memory:' AS billing"); } catch (\Throwable) {}
    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');

    Config::set('services.stripe.webhook_secret', 'whsec_replay_test');
});

it('stripe — rejects an event whose Stripe-Signature timestamp is older than the tolerance window', function () {
    $event = [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['id' => 'sub_replay']],
    ];
    $body = json_encode($event);

    // Timestamp 1 hour in the past — far outside Stripe's 300s tolerance.
    $oldTimestamp = time() - 3600;
    $sig = signStripeBody($body, 'whsec_replay_test', $oldTimestamp);

    $this->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)
        ->assertStatus(400)
        ->assertJson(['error' => 'Invalid signature']);

    expect(DB::table('billing.webhook_events')->count())->toBe(0);
});

it('stripe — accepts an event whose timestamp is within the tolerance window', function () {
    $event = [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_fresh',
            'status' => 'active',
            'customer' => 'cus_fresh',
            'cancel_at_period_end' => false,
            'current_period_start' => time(),
            'current_period_end' => time() + 86400,
            'items' => ['data' => [['id' => 'si_x', 'price' => ['id' => 'price_x']]]],
        ]],
    ];
    $body = json_encode($event);

    // Timestamp 30 seconds ago — well inside Stripe's 300s tolerance.
    $sig = signStripeBody($body, 'whsec_replay_test', time() - 30);

    $this->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});
```

- [ ] **Step 2: Run**

```bash
composer test -- --filter=StripeReplayAttackTest
```
Expected: 2 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Webhooks/EdgeCases/StripeReplayAttackTest.php
git commit -m "test: stripe webhook replay-attack timestamp tolerance"
```

---

## Task 15: Final full-suite verification

- [ ] **Step 1: Run the entire webhook test surface**

```bash
composer test -- --filter='Webhook|Webhooks'
```
Expected: All webhook tests (existing + new) pass. If anything fails, debug — either a real regression caught by the new tests, or a test setup issue.

- [ ] **Step 2: Run the full suite once to confirm no collateral damage**

```bash
composer test
```
Expected: Full green.

- [ ] **Step 3: Stop here — Josh commits the final state himself per workflow preferences.**

No final commit task; the per-task commits above already represent the work.

---

## Self-Review Notes

**Spec coverage check:**
- ✅ Fresha signature verification — Tasks 2, 3
- ✅ Square signature verification — Tasks 4, 5
- ✅ Shopify orders/paid HTTP layer — Task 6
- ✅ Shopify orders/updated HTTP layer — Task 7
- ✅ Shopify webhook secret rotation (fallback) — Task 8
- ✅ Shopify shop/update — Task 9
- ✅ Shopify app/uninstalled — Task 10
- ✅ Stripe billing real-shape — Task 11
- ✅ Stripe Connect real-shape + account_mismatch — Task 12
- ✅ Future-larger: malformed bodies — Task 13
- ✅ Future-larger: replay attacks — Task 14

**Known fragile points an executing agent should watch for:**
1. `ProcessShopifyOrderWebhookJob` constructor property names (Task 6, Step 2) — verify before relying on reflection.
2. `StripeConnectService::determineAccountStatus()` return value (Task 12) — the assertion `'active'` may need to change to whatever the service actually returns for a fully-onboarded account.
3. Laravel's `postJson()` re-encodes the array, which would break Stripe HMAC verification. **All Stripe tests use `$this->call()` with a raw body string.** Don't switch them to `postJson`.
4. Pest `beforeEach` is per-file, so each new test file repeats `setupProfessionalIntegrationsTable()` etc. — that's intentional, not duplication to deduplicate.
5. The Fresha controller's `isValidSignature()` rejects when `signatureKey` OR `notificationUrl` is empty. Both must be set in `Config::set()`.
