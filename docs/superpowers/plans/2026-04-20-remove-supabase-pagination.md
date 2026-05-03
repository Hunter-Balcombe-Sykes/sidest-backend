# Remove Supabase Pagination from OAuth Callback — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate O(n) Supabase paginated lookups from the Shopify OAuth callback path by replacing the Supabase email lookup with a direct indexed query on `professionals.primary_email`.

**Architecture:** Remove `getUserByEmail` entirely from `SupabaseAdminService`; remove the dead pagination fallback from `createUser`; replace the two-step Supabase→Professional lookup in the OAuth callback with a single `whereRaw('lower(primary_email) = ?', ...)` query that hits an existing index. Users whose Shopify email differs from their Side St email fall through to Path C (setup wizard) where they log in normally.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Mockery, SQLite in-memory (tests), `Http::fake()` for mocking Supabase/Shopify HTTP calls.

---

## Files Changed

| File | Action |
|------|--------|
| `app/Services/Auth/SupabaseAdminService.php` | Remove `getUserByEmail`; remove pagination fallback from `createUser` |
| `app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php` | Replace Supabase lookup with `primary_email` local DB query; remove `SupabaseAdminService` import |
| `tests/Unit/Auth/SupabaseAdminServiceTest.php` | New: unit tests for updated `createUser` behaviour |
| `tests/Feature/Shopify/ShopifyOAuthCallbackPathBTest.php` | New: feature tests for Path B local lookup and Path C fallthrough |

---

## Task 1: Write failing unit tests for `SupabaseAdminService`

**Files:**
- Create: `tests/Unit/Auth/SupabaseAdminServiceTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

use App\Services\Auth\SupabaseAdminService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-service-key',
    ]);
});

it('createUser returns id, email, and created=true on success', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response([
            'id' => 'supabase-uuid-123',
            'email' => 'new@example.com',
        ], 200),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->createUser('new@example.com');

    expect($result)->toBe([
        'id' => 'supabase-uuid-123',
        'email' => 'new@example.com',
        'created' => true,
    ]);
});

it('createUser returns created=false when GoTrue v2 returns existing user id in 422 body', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response([
            'code' => 'email_exists',
            'user' => [
                'id' => 'existing-uuid-456',
                'email' => 'existing@example.com',
            ],
        ], 422),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->createUser('existing@example.com');

    expect($result)->toBe([
        'id' => 'existing-uuid-456',
        'email' => 'existing@example.com',
        'created' => false,
    ]);
});

it('createUser throws when 422 arrives without user id in body', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response([
            'code' => 'email_exists',
            'msg' => 'User already registered',
            // no 'user' key — should throw, not paginate
        ], 422),
    ]);

    $service = new SupabaseAdminService;

    expect(fn () => $service->createUser('conflict@example.com'))
        ->toThrow(RuntimeException::class);
});

it('createUser throws on generic HTTP failure', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response(['msg' => 'server error'], 500),
    ]);

    $service = new SupabaseAdminService;

    expect(fn () => $service->createUser('fail@example.com'))
        ->toThrow(RuntimeException::class);
});

it('createUser trims and lowercases the email', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response([
            'id' => 'uuid-789',
            'email' => 'user@example.com',
        ], 200),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->createUser('  USER@Example.COM  ');

    expect($result['email'])->toBe('user@example.com');

    Http::assertSent(function ($request) {
        return $request->data()['email'] === 'user@example.com';
    });
});

it('createUser throws on empty email', function () {
    $service = new SupabaseAdminService;

    expect(fn () => $service->createUser(''))
        ->toThrow(RuntimeException::class, 'Email is required');
});
```

- [ ] **Step 2: Run tests to verify they fail as expected**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pest tests/Unit/Auth/SupabaseAdminServiceTest.php --no-coverage
```

Expected: Tests 1, 2, 4, 5, 6 pass (existing behaviour). **Test 3 fails** — currently the 422 without user id triggers the pagination fallback (which would try HTTP calls), not a throw. That's the behaviour we're about to fix.

---

## Task 2: Update `SupabaseAdminService`

**Files:**
- Modify: `app/Services/Auth/SupabaseAdminService.php`

- [ ] **Step 1: Remove `getUserByEmail` and the pagination fallback from `createUser`**

Replace the entire file with the updated version below. The changes are:
1. Lines 58–81 (the 422/409 block with pagination fallback) are replaced — keep only the GoTrue v2 path
2. The entire `getUserByEmail` method (lines 98–154) is deleted

```php
<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: Server-side Supabase user management via the GoTrue Admin API. Creates users server-side for the setup wizard flow.
class SupabaseAdminService
{
    private string $baseUrl;

    private string $serviceRoleKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('supabase.url'), '/');
        $this->serviceRoleKey = (string) config('supabase.service_role_key');

        if ($this->baseUrl === '' || $this->serviceRoleKey === '') {
            throw new RuntimeException('Supabase URL and service role key must be configured.');
        }
    }

    /**
     * Create a Supabase user server-side (no password — magic link auth).
     *
     * @return array{id: string, email: string, created: bool}
     */
    public function createUser(string $email, array $metadata = []): array
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            throw new RuntimeException('Email is required to create a Supabase user.');
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->post("{$this->baseUrl}/auth/v1/admin/users", [
                'email' => $email,
                'email_confirm' => true,
                'user_metadata' => $metadata ?: (object) [],
            ]);

        if ($response->successful()) {
            $user = $response->json();

            return [
                'id' => (string) ($user['id'] ?? ''),
                'email' => (string) ($user['email'] ?? $email),
                'created' => true,
            ];
        }

        // User already exists — GoTrue v2 includes the existing user object in the error response.
        if ($response->status() === 422 || $response->status() === 409) {
            $body = $response->json();
            $existingId = $body['user']['id'] ?? null;

            if ($existingId) {
                return [
                    'id' => (string) $existingId,
                    'email' => (string) ($body['user']['email'] ?? $email),
                    'created' => false,
                ];
            }
        }

        Log::error('Supabase admin: failed to create user', [
            'email' => $email,
            'status' => $response->status(),
            'error_code' => $response->json('code'),
            'error_msg' => $response->json('msg'),
        ]);

        throw new RuntimeException('Failed to create Supabase user.');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->serviceRoleKey,
            'apikey' => $this->serviceRoleKey,
            'Content-Type' => 'application/json',
        ];
    }
}
```

- [ ] **Step 2: Run tests to verify all pass**

```bash
./vendor/bin/pest tests/Unit/Auth/SupabaseAdminServiceTest.php --no-coverage
```

Expected: All 6 tests pass.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Auth/SupabaseAdminService.php tests/Unit/Auth/SupabaseAdminServiceTest.php
git commit -m "refactor(auth): remove getUserByEmail and pagination fallback from SupabaseAdminService"
```

---

## Task 3: Write failing feature tests for OAuth Path B and Path C

**Files:**
- Create: `tests/Feature/Shopify/ShopifyOAuthCallbackPathBTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

use App\Http\Controllers\Api\Shopify\ShopifyAppOAuthController;
use App\Models\Core\Professional\Professional;
use App\Services\Shopify\BrandSignupResult;
use App\Services\Shopify\BrandSignupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Builds a valid Shopify OAuth callback request with a correctly computed HMAC.
function makeShopifyCallbackRequest(string $shop, string $nonce, string $secret): Request
{
    $params = [
        'code'      => 'authcode123',
        'shop'      => $shop,
        'state'     => $nonce,
        'timestamp' => '1713600000',
    ];
    ksort($params);
    $message = http_build_query($params);
    $hmac = hash_hmac('sha256', $message, $secret);
    $params['hmac'] = $hmac;

    return Request::create('/api/shopify/callback?' . http_build_query($params), 'GET');
}

beforeEach(function () {
    // Override pgsql to SQLite in-memory (same pattern as BrandBootstrapTest)
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default'                => 'sqlite',
        'database.connections.pgsql'      => array_merge($sqlite, ['database' => ':memory:']),
        'services.shopify.api_secret'     => 'test-shopify-secret',
        'services.shopify.api_key'        => 'test-api-key',
        'services.shopify.api_version'    => '2024-01',
        'services.shopify.app_handle'     => 'side-st',
        'supabase.url'                    => 'https://test.supabase.co',
        'supabase.service_role_key'       => 'test-key',
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    foreach (['core', 'site', 'brand', 'notifications', 'billing'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    // Schema-prefixed tables — must use core. prefix to match model $table definitions
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        handle TEXT,
        handle_lc TEXT,
        display_name TEXT,
        primary_email TEXT,
        professional_type TEXT DEFAULT "brand",
        status TEXT DEFAULT "active",
        first_name TEXT,
        last_name TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // Needed for Path A check (must be empty to reach Path B/C in these tests)
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        shopify_shop_domain TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('takes Path B and calls handleExistingBrandConnect when shop email matches a professional primary_email', function () {
    $shop = 'matching-shop.myshopify.com';
    $shopEmail = 'owner@matchedshop.com';
    $nonce = 'testnonce_' . Str::random(8);
    $secret = config('services.shopify.api_secret');

    // Seed a professional whose primary_email matches the Shopify store email
    $proId = Str::uuid()->toString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id'               => $proId,
        'auth_user_id'     => 'supabase-uid-abc',
        'handle'           => 'matchedowner',
        'handle_lc'        => 'matchedowner',
        'display_name'     => 'Matched Owner',
        'primary_email'    => $shopEmail,
        'professional_type'=> 'brand',
        'status'           => 'active',
        'created_at'       => now()->toDateTimeString(),
        'updated_at'       => now()->toDateTimeString(),
    ]);

    // Fake Shopify HTTP calls
    Http::fake([
        "https://{$shop}/admin/oauth/access_token" => Http::response(['access_token' => 'shpat_fake'], 200),
        "https://{$shop}/admin/api/*/shop.json"    => Http::response(['shop' => ['email' => $shopEmail, 'id' => 99]], 200),
    ]);

    // Mock BrandSignupService so we don't need full DB setup for integration/site/etc.
    $professional = Professional::on('pgsql')->find($proId);
    $fakeSite = Mockery::mock(\App\Models\Core\Site\Site::class)->makePartial();
    $fakeIntegration = Mockery::mock(\App\Models\Core\Professional\ProfessionalIntegration::class)->makePartial();
    $fakeResult = new BrandSignupResult(
        professional: $professional,
        site: $fakeSite,
        brandProfile: null,
        integration: $fakeIntegration,
        isReinstall: false,
    );

    $brandSignup = Mockery::mock(BrandSignupService::class);
    $brandSignup->shouldReceive('handleExistingBrandConnect')
        ->once()
        ->with($professional, $shop, 'shpat_fake', Mockery::any(), Mockery::any())
        ->andReturn($fakeResult);
    app()->instance(BrandSignupService::class, $brandSignup);

    // Set up nonce in cache
    cache()->put("shopify_oauth_nonce_{$shop}", $nonce, now()->addMinutes(10));

    $request = makeShopifyCallbackRequest($shop, $nonce, $secret);
    $controller = app(ShopifyAppOAuthController::class);
    $response = $controller->callback($request);

    expect($response->getStatusCode())->toBe(302);
    // Redirect should be to the app base path, NOT to /setup
    expect($response->headers->get('Location'))->not->toContain('setup');
});

it('falls through to Path C when shop email does not match any professional primary_email', function () {
    $shop = 'nomatch-shop.myshopify.com';
    $shopEmail = 'nomatch@shopify.com';
    $nonce = 'testnonce_' . Str::random(8);
    $secret = config('services.shopify.api_secret');

    // No professional in DB with this email

    Http::fake([
        "https://{$shop}/admin/oauth/access_token" => Http::response(['access_token' => 'shpat_fake'], 200),
        "https://{$shop}/admin/api/*/shop.json"    => Http::response(['shop' => ['email' => $shopEmail, 'id' => 88]], 200),
    ]);

    $brandSignup = Mockery::mock(BrandSignupService::class);
    $brandSignup->shouldNotReceive('handleExistingBrandConnect');
    app()->instance(BrandSignupService::class, $brandSignup);

    cache()->put("shopify_oauth_nonce_{$shop}", $nonce, now()->addMinutes(10));

    $request = makeShopifyCallbackRequest($shop, $nonce, $secret);
    $controller = app(ShopifyAppOAuthController::class);
    $response = $controller->callback($request);

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toContain('shopify_setup_token');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Shopify/ShopifyOAuthCallbackPathBTest.php --no-coverage
```

Expected: Both tests **fail** — the Path B test fails because the controller still calls `getUserByEmail` (Supabase HTTP, not mocked). The Path C test may also fail for the same reason.

---

## Task 4: Update `ShopifyAppOAuthController`

**Files:**
- Modify: `app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php`

- [ ] **Step 1: Replace Path B lookup and remove SupabaseAdminService import**

In `ShopifyAppOAuthController.php`, make two changes:

**1. Remove the `SupabaseAdminService` import (line 9):**
```php
// DELETE this line:
use App\Services\Auth\SupabaseAdminService;
```

**2. Replace Path B block (lines 136–156) with the local DB lookup:**

Old code:
```php
            // Path B: Existing account — shop email matches a Supabase user with a Professional
            if ($shopEmail !== '') {
                $supabaseUser = app(SupabaseAdminService::class)->getUserByEmail($shopEmail);

                if ($supabaseUser !== null) {
                    $existingProfessional = Professional::where('auth_user_id', $supabaseUser['id'])->first();

                    if ($existingProfessional) {
                        $result = $this->brandSignup->handleExistingBrandConnect(
                            $existingProfessional, $shop, $accessToken, $shopData, $scopes
                        );

                        Log::info('Shopify OAuth: existing account connect', [
                            'professional_id' => (string) $result->professional->id,
                            'shop_domain' => $shop,
                        ]);

                        return redirect()->away($basePath);
                    }
                }
            }
```

New code:
```php
            // Path B: Existing account — shop email matches a Professional's primary_email (indexed local lookup).
            // Users whose Shopify email differs from their Side St email fall through to Path C.
            if ($shopEmail !== '') {
                $existingProfessional = Professional::whereRaw('lower(primary_email) = ?', [$shopEmail])->first();

                if ($existingProfessional) {
                    $result = $this->brandSignup->handleExistingBrandConnect(
                        $existingProfessional, $shop, $accessToken, $shopData, $scopes
                    );

                    Log::info('Shopify OAuth: existing account connect', [
                        'professional_id' => (string) $result->professional->id,
                        'shop_domain' => $shop,
                    ]);

                    return redirect()->away($basePath);
                }
            }
```

Note: `$shopEmail` is already `strtolower(trim(...))` at line 113, so it matches the `lower(primary_email)` index directly.

- [ ] **Step 2: Run the new feature tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Shopify/ShopifyOAuthCallbackPathBTest.php --no-coverage
```

Expected: Both tests pass.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php \
        tests/Feature/Shopify/ShopifyOAuthCallbackPathBTest.php
git commit -m "perf(shopify): replace Supabase email lookup with indexed primary_email query on OAuth callback"
```

---

## Task 5: Full test suite verification

- [ ] **Step 1: Run the full test suite**

```bash
composer test
```

Expected: All tests pass. Zero failures.

- [ ] **Step 2: Commit and push if all green**

If `composer test` exits 0:

```bash
git push origin development-v2
```
