# Stripe Idempotency Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two remaining Stripe-related idempotency gaps: (1) inbound `StripeConnectWebhookController` currently re-processes retried events; (2) four outbound Stripe API writes (customer create × 2, Connect account create, refund create) are missing `Idempotency-Key` headers and can create duplicate resources on retry.

**Architecture:** Reuse what's already proven in this codebase. For the inbound webhook gap, copy the exact DB-unique-constraint pattern from `StripeWebhookController:56-68` (uses `billing.webhook_events` — globally unique on `stripe_event_id`, covers both platform and Connect events). For the outbound gaps, add deterministic idempotency keys derived from local resource IDs (e.g. `"customer_{professional->id}"`) matching the existing convention in `CommissionPayoutService:357` (`"pi_{payout->id}{retryKey}"`) and `:426` (`"tr_{payout->id}{retryKey}"`). No new migrations, no new middleware, no framework-level abstraction.

**Tech Stack:** Laravel 12, PHP 8.2, Stripe PHP SDK 17.x, Pest 4 (tests), PostgreSQL (prod) / SQLite in-memory (tests), existing `billing.webhook_events` table.

**Scope boundary — explicitly NOT in this plan:**
- No changes to `StripeBillingService` subscription/checkout/portal/invoice-preview calls (ephemeral or already guarded by webhook dedupe).
- No changes to `setupIntents`, `accountLinks`, or `checkoutSessions` creation (short-lived redirect flows; duplicates are harmless).
- No generic `IdempotencyKeyMiddleware` — see the review conversation at `2026-04-23-stripe-idempotency` for the decision to skip it.
- No changes to non-Stripe webhook controllers (Shopify/Square/Fresha already dedupe via `Cache::add`).

---

## File Structure

**Files modified (no new files):**

| File | Responsibility | Change |
|---|---|---|
| `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php` | Connect event ingestion | Insert event-ID dedupe block between signature verify and match() dispatch |
| `app/Services/Stripe/StripeBillingService.php` | Billing customer creation | Add `idempotency_key` request option to `customers->create` call |
| `app/Services/Stripe/StripeConnectService.php` | Connect account + brand customer creation | Add `idempotency_key` to both `accounts->create` (line 38) and `customers->create` (line 184) |
| `app/Services/Stripe/CommissionPayoutService.php` | Commission refund on transfer failure | Add `idempotency_key` to `refunds->create` (line 475) |

**Test files created:**
- `tests/Feature/Stripe/StripeConnectWebhookDedupeTest.php` — verifies Ticket 1
- `tests/Feature/Stripe/StripeIdempotencyKeysTest.php` — verifies Ticket 2 passes idempotency keys to the Stripe SDK for all four call sites

**No migration files.** The existing `billing.webhook_events` table (`supabase/migrations/20260407000000_billing_stripe_integration.sql:8-19`) is reused for Connect events — Stripe event IDs (`evt_...`) are globally unique across platform and Connect events, so dedupe on `stripe_event_id UNIQUE` remains correct.

---

## Task 1: Dedupe `StripeConnectWebhookController` via `billing.webhook_events`

**Files:**
- Modify: `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php:19-72`
- Test: `tests/Feature/Stripe/StripeConnectWebhookDedupeTest.php` (create)

**Design notes:**
- Mirrors `StripeWebhookController:56-68` exactly — atomic `insertOrIgnore` on the `stripe_event_id` UNIQUE constraint; if the row already exists the `match` is skipped and the controller returns `200 {"received": true}` (Stripe's expected ack).
- Inserted BEFORE the `match` dispatch — if any handler throws, we want the event ID recorded so the crash isn't re-triggered on Stripe's automatic retry. (This matches the existing `StripeWebhookController` behavior; if that becomes a problem in practice, the fix is to move dedupe into a transaction around the handler, but don't pre-optimize.)
- Keep the dual-secret loop untouched — that's for verifying events from either the platform or Connect webhook secret, unrelated to dedupe.

- [ ] **Step 1.1: Write the failing dedupe test**

Create `tests/Feature/Stripe/StripeConnectWebhookDedupeTest.php`:

```php
<?php

use App\Http\Controllers\Api\Webhooks\StripeConnectWebhookController;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Dedupe-only tests for StripeConnectWebhookController. Signature gating is
// covered in StripeConnectWebhookSignatureTest (if added later); this file
// verifies that a replayed event (same stripe_event_id) is short-circuited
// and does NOT invoke the inner handlers a second time.

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT NOT NULL UNIQUE,
        event_type TEXT NOT NULL,
        payload TEXT NULL,
        processed_at TEXT NOT NULL
    )');
});

it('skips processing when stripe_event_id already logged', function () {
    // Pre-seed: this event was already processed.
    DB::table('billing.webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'stripe_event_id' => 'evt_duplicate_123',
        'event_type' => 'account.updated',
        'payload' => json_encode(['id' => 'evt_duplicate_123']),
        'processed_at' => now()->toDateTimeString(),
    ]);

    // A second delivery of the same event must NOT reach the handler.
    // We assert by checking the professional's stripe_connect_status is not mutated.
    $professional = Professional::create([
        'id' => (string) Str::uuid(),
        'handle' => 'p1',
        'handle_lc' => 'p1',
        'display_name' => 'P1',
        'professional_type' => 'affiliate',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_xyz',
        'stripe_connect_status' => 'onboarding',
    ]);

    // Invoke the controller action directly with a mocked request. We bypass
    // signature verification by injecting a pre-built event via reflection on
    // the insertOrIgnore path — simpler than signing a real Stripe payload here.
    // The controller's match() dispatches to handleAccountUpdated which would
    // set stripe_connect_status='active' if it ran.

    $payload = json_encode([
        'id' => 'evt_duplicate_123',
        'type' => 'account.updated',
        'data' => ['object' => ['id' => 'acct_xyz', 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]],
    ]);

    $signingSecret = 'whsec_test';
    config(['services.stripe.connect_webhook_secret' => $signingSecret]);
    config(['services.stripe.webhook_secret' => null]);

    $timestamp = time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $signingSecret);

    $response = $this->call(
        'POST',
        '/api/webhooks/stripe-connect',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $payload
    );

    $response->assertOk();
    $response->assertJson(['received' => true]);

    // Crucial assertion: status was NOT mutated because the handler never ran.
    expect($professional->fresh()->stripe_connect_status)->toBe('onboarding');

    // And only one row in webhook_events (insertOrIgnore did not insert).
    expect(DB::table('billing.webhook_events')->where('stripe_event_id', 'evt_duplicate_123')->count())->toBe(1);
});

it('processes a fresh event and records it in webhook_events', function () {
    $professional = Professional::create([
        'id' => (string) Str::uuid(),
        'handle' => 'p2',
        'handle_lc' => 'p2',
        'display_name' => 'P2',
        'professional_type' => 'affiliate',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_fresh',
        'stripe_connect_status' => 'onboarding',
    ]);

    $payload = json_encode([
        'id' => 'evt_fresh_456',
        'type' => 'account.updated',
        'data' => ['object' => [
            'id' => 'acct_fresh',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => ['currently_due' => []],
        ]],
    ]);

    $signingSecret = 'whsec_test';
    config(['services.stripe.connect_webhook_secret' => $signingSecret]);
    config(['services.stripe.webhook_secret' => null]);

    $timestamp = time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $signingSecret);

    $response = $this->call(
        'POST',
        '/api/webhooks/stripe-connect',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $payload
    );

    $response->assertOk();

    // Event was recorded for future dedupe.
    expect(DB::table('billing.webhook_events')->where('stripe_event_id', 'evt_fresh_456')->count())->toBe(1);

    // Handler ran and mutated status (account_updated with all flags true -> 'active').
    expect($professional->fresh()->stripe_connect_status)->toBe('active');
});
```

- [ ] **Step 1.2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Stripe/StripeConnectWebhookDedupeTest.php`

Expected: both tests FAIL. The first one fails because the controller currently invokes `handleAccountUpdated` even when the event is a duplicate, which would set status to `'active'`. The second may pass accidentally (no dedupe-layer presence to break) but will fail once step 1.3 adds the insert — expected during red-green cycle.

- [ ] **Step 1.3: Add dedupe block to `StripeConnectWebhookController`**

Edit `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php`. Add imports and insert the dedupe block immediately after the `if (! $event)` guard (currently line 57), before the `match` statement (currently line 59).

Add to imports at the top:
```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
```

Replace the block between lines 56-59 (existing):

```php
        if (! $event) {
            Log::warning('Stripe webhook signature verification failed for all configured secrets');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        match ($event->type) {
```

With:

```php
        if (! $event) {
            Log::warning('Stripe webhook signature verification failed for all configured secrets');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Idempotency: atomic insert-or-skip on the UNIQUE stripe_event_id.
        // Stripe event IDs are globally unique across platform + Connect events,
        // so the billing.webhook_events table covers both this controller and
        // StripeWebhookController without a separate table.
        $alreadyProcessed = ! DB::table('billing.webhook_events')->insertOrIgnore([
            'id' => Str::uuid()->toString(),
            'stripe_event_id' => $event->id,
            'event_type' => $event->type,
            'payload' => json_encode(json_decode($payload, true)),
            'processed_at' => now(),
        ]);

        if ($alreadyProcessed) {
            return response()->json(['received' => true]);
        }

        match ($event->type) {
```

- [ ] **Step 1.4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Stripe/StripeConnectWebhookDedupeTest.php`

Expected: both tests PASS.

- [ ] **Step 1.5: Run the full Stripe test suite to confirm no regressions**

Run: `php artisan test tests/Feature/Stripe/`

Expected: all tests PASS. In particular `StripeConnectPayoutsControllerTest.php` should be unaffected (it doesn't hit the webhook route).

- [ ] **Step 1.6: Commit**

```bash
git add app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php tests/Feature/Stripe/StripeConnectWebhookDedupeTest.php
git commit -m "fix(stripe): dedupe Connect webhook events via billing.webhook_events"
```

---

## Task 2: Idempotency-Key on `StripeBillingService::ensureStripeCustomer`

**Files:**
- Modify: `app/Services/Stripe/StripeBillingService.php:29-36`
- Test: `tests/Feature/Stripe/StripeIdempotencyKeysTest.php` (create)

**Design note:** The key is `"customer_{$professional->id}"`. The UUID is immutable for the life of the professional and is the natural dedupe dimension — two concurrent requests for the same professional will both hit Stripe with the same key, and Stripe will return the same customer for the second call. Stripe idempotency keys have a 24h TTL on their side, which is more than enough — the service is already guarded by the `if ($professional->stripe_customer_id)` check for longer-range dedupe.

- [ ] **Step 2.1: Write the failing test**

Create `tests/Feature/Stripe/StripeIdempotencyKeysTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// These tests use the Stripe SDK's ApiRequestor mocking via a stub
// StripeClient. We intercept the `customers->create` call and assert
// that the second (request options) argument contains the expected
// idempotency_key. The idempotency_key is the primary assertion.

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();
});

function makeProfessional(string $id = null): Professional
{
    $id ??= (string) Str::uuid();

    return Professional::create([
        'id' => $id,
        'handle' => "h-{$id}",
        'handle_lc' => "h-{$id}",
        'display_name' => "Pro {$id}",
        'primary_email' => "{$id}@example.test",
        'professional_type' => 'affiliate',
        'status' => 'active',
    ]);
}

// StripeClient uses __get() for lazy service accessors (e.g. $client->customers
// returns a cached CustomersService). Mocks must intercept __get rather than
// assigning to the property directly.

it('ensureStripeCustomer passes deterministic idempotency_key to Stripe', function () {
    $professional = makeProfessional();

    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($professional) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("customer_{$professional->id}");
            expect($params['email'])->toBe($professional->primary_email);

            return true;
        })
        ->andReturn((object) ['id' => 'cus_fake_abc']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('__get')->with('customers')->andReturn($customersSpy);

    $service = new StripeBillingService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $customerId = $service->ensureStripeCustomer($professional);

    expect($customerId)->toBe('cus_fake_abc');
    expect($professional->fresh()->stripe_customer_id)->toBe('cus_fake_abc');
});

it('ensureStripeCustomer skips Stripe when customer already exists', function () {
    $professional = makeProfessional();
    $professional->update(['stripe_customer_id' => 'cus_existing']);

    $customersSpy = Mockery::mock();
    $customersSpy->shouldNotReceive('create');

    $stripeClient = Mockery::mock(StripeClient::class);
    // __get may or may not be called — the service early-exits before touching $this->stripe.
    // Use a permissive stub rather than a strict expectation.
    $stripeClient->shouldReceive('__get')->with('customers')->andReturn($customersSpy)->zeroOrMoreTimes();

    $service = new StripeBillingService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    expect($service->ensureStripeCustomer($professional))->toBe('cus_existing');
});
```

Note: the reflection trick works because `StripeBillingService::$stripe` is `private` and assigned in the constructor. PHP 8.2+ requires `setAccessible(true)` before calling `setValue` on a private property; the plan explicitly includes that line. If the property ever becomes `readonly`, switch to constructor DI (following `CommissionPayoutService`'s nullable-client pattern).

- [ ] **Step 2.2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Stripe/StripeIdempotencyKeysTest.php --filter="ensureStripeCustomer passes deterministic"`

Expected: FAIL with `"idempotency_key" is undefined` (or similar Mockery argument-match failure) because the production code currently calls `customers->create([...])` with no second argument.

- [ ] **Step 2.3: Add the idempotency key to the call**

Edit `app/Services/Stripe/StripeBillingService.php:29-36`. Replace:

```php
        $customer = $this->stripe->customers->create([
            'email' => $professional->primary_email,
            'name' => $professional->display_name,
            'metadata' => [
                'sidest_professional_id' => $professional->id,
                'professional_type' => $professional->professional_type,
            ],
        ]);
```

With:

```php
        $customer = $this->stripe->customers->create([
            'email' => $professional->primary_email,
            'name' => $professional->display_name,
            'metadata' => [
                'sidest_professional_id' => $professional->id,
                'professional_type' => $professional->professional_type,
            ],
        ], ['idempotency_key' => "customer_{$professional->id}"]);
```

- [ ] **Step 2.4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Stripe/StripeIdempotencyKeysTest.php`

Expected: both tests in this file PASS.

- [ ] **Step 2.5: Commit**

```bash
git add app/Services/Stripe/StripeBillingService.php tests/Feature/Stripe/StripeIdempotencyKeysTest.php
git commit -m "fix(stripe): add idempotency key to billing customer creation"
```

---

## Task 3: Idempotency-Key on `StripeConnectService::createConnectAccount`

**Files:**
- Modify: `app/Services/Stripe/StripeConnectService.php:38-50`
- Test: `tests/Feature/Stripe/StripeIdempotencyKeysTest.php` (append)

**Design note:** Key is `"acct_{$professional->id}"`. Same rationale as Task 2 — local professional UUID is the right dedupe dimension. Duplicate Connect accounts are especially bad because Stripe enforces platform-wide limits on Express account creation.

- [ ] **Step 3.1: Write the failing test**

Append to `tests/Feature/Stripe/StripeIdempotencyKeysTest.php`:

```php
it('createConnectAccount passes deterministic idempotency_key to Stripe', function () {
    $professional = makeProfessional();
    $professional->update(['country_code' => 'AU']);

    $accountsSpy = Mockery::mock();
    $accountsSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($professional) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("acct_{$professional->id}");
            expect($params['type'])->toBe('express');
            expect($params['country'])->toBe('AU');

            return true;
        })
        ->andReturn((object) ['id' => 'acct_fake_abc']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('__get')->with('accounts')->andReturn($accountsSpy);

    $service = new \App\Services\Stripe\StripeConnectService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $accountId = $service->createConnectAccount($professional);

    expect($accountId)->toBe('acct_fake_abc');
    expect($professional->fresh()->stripe_connect_account_id)->toBe('acct_fake_abc');
});
```

- [ ] **Step 3.2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Stripe/StripeIdempotencyKeysTest.php --filter="createConnectAccount passes deterministic"`

Expected: FAIL with Mockery argument-match failure — current code has no second argument.

- [ ] **Step 3.3: Add the idempotency key**

Edit `app/Services/Stripe/StripeConnectService.php:38-50`. Replace:

```php
        $account = $this->stripe->accounts->create([
            'type' => 'express',
            'country' => $this->mapCountryCode($professional->country_code),
            'email' => $professional->primary_email,
            'metadata' => [
                'sidest_professional_id' => $professional->id,
                'professional_type' => $professional->professional_type,
            ],
            'capabilities' => [
                'transfers' => ['requested' => true],
                'card_payments' => ['requested' => true],
            ],
        ]);
```

With:

```php
        $account = $this->stripe->accounts->create([
            'type' => 'express',
            'country' => $this->mapCountryCode($professional->country_code),
            'email' => $professional->primary_email,
            'metadata' => [
                'sidest_professional_id' => $professional->id,
                'professional_type' => $professional->professional_type,
            ],
            'capabilities' => [
                'transfers' => ['requested' => true],
                'card_payments' => ['requested' => true],
            ],
        ], ['idempotency_key' => "acct_{$professional->id}"]);
```

- [ ] **Step 3.4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Stripe/StripeIdempotencyKeysTest.php --filter="createConnectAccount"`

Expected: PASS.

- [ ] **Step 3.5: Commit**

```bash
git add app/Services/Stripe/StripeConnectService.php tests/Feature/Stripe/StripeIdempotencyKeysTest.php
git commit -m "fix(stripe): add idempotency key to Connect account creation"
```

---

## Task 4: Idempotency-Key on `StripeConnectService::createCustomer` (brand flow)

**Files:**
- Modify: `app/Services/Stripe/StripeConnectService.php:182-198`
- Test: `tests/Feature/Stripe/StripeIdempotencyKeysTest.php` (append)

**Design note:** Key is `"customer_{$brand->id}"` — same format as Task 2. A brand and an affiliate could theoretically share an ID namespace if we ever unified, so keying by the professional UUID is unambiguous. Both `StripeBillingService::ensureStripeCustomer` and `StripeConnectService::createCustomer` write to the same `professionals.stripe_customer_id` column, so using the same key format means a retry through either path returns the same Stripe customer — a desirable invariant.

- [ ] **Step 4.1: Write the failing test**

Append to `tests/Feature/Stripe/StripeIdempotencyKeysTest.php`:

```php
it('createCustomer (Connect/brand) passes deterministic idempotency_key to Stripe', function () {
    $brand = makeProfessional();
    $brand->update(['professional_type' => 'brand']);

    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($brand) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("customer_{$brand->id}");

            return true;
        })
        ->andReturn((object) ['id' => 'cus_brand_abc']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('__get')->with('customers')->andReturn($customersSpy);

    $service = new \App\Services\Stripe\StripeConnectService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $customerId = $service->createCustomer($brand);

    expect($customerId)->toBe('cus_brand_abc');
    expect($brand->fresh()->stripe_customer_id)->toBe('cus_brand_abc');
});
```

- [ ] **Step 4.2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Stripe/StripeIdempotencyKeysTest.php --filter="createCustomer \(Connect"`

Expected: FAIL.

- [ ] **Step 4.3: Add the idempotency key**

Edit `app/Services/Stripe/StripeConnectService.php:184-191`. Replace:

```php
        $customer = $this->stripe->customers->create([
            'email' => $brand->primary_email,
            'name' => $brand->display_name,
            'metadata' => [
                'sidest_professional_id' => $brand->id,
                'professional_type' => $brand->professional_type,
            ],
        ]);
```

With:

```php
        $customer = $this->stripe->customers->create([
            'email' => $brand->primary_email,
            'name' => $brand->display_name,
            'metadata' => [
                'sidest_professional_id' => $brand->id,
                'professional_type' => $brand->professional_type,
            ],
        ], ['idempotency_key' => "customer_{$brand->id}"]);
```

- [ ] **Step 4.4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Stripe/StripeIdempotencyKeysTest.php --filter="createCustomer \(Connect"`

Expected: PASS.

- [ ] **Step 4.5: Commit**

```bash
git add app/Services/Stripe/StripeConnectService.php tests/Feature/Stripe/StripeIdempotencyKeysTest.php
git commit -m "fix(stripe): add idempotency key to brand Connect customer creation"
```

---

## Task 5: Idempotency-Key on `CommissionPayoutService` refund

**Files:**
- Modify: `app/Services/Stripe/CommissionPayoutService.php:475-477`
- Test: `tests/Feature/Stripe/StripeIdempotencyKeysTest.php` (append)

**Design note:** This refund fires in the recovery path when a transfer fails after the brand was already charged. Without an idempotency key, if the catch block re-runs (e.g. the method is re-invoked via Horizon retry after partial failure), the brand could receive two refunds. Key: `"rf_{$payout->id}_{$payout->stripe_payment_intent_id}"` — ties the refund to the exact payout + PI being reversed, matching the naming style of the nearby `"pi_{payout->id}{retryKey}"` (line 357) and `"tr_{payout->id}{retryKey}"` (line 426).

Including the PI id makes the key safe even if an admin retry creates a fresh PI for the same payout (line 481 clears `stripe_payment_intent_id` on successful refund, so the next run's PI is distinct). Without the PI suffix, a new PI on the same payout would match a stale refund key — wrong refund target.

- [ ] **Step 5.1: Write the failing test — source-level assertion**

The refund call is buried inside `CommissionPayoutService::executePayout()` inside a `catch (ApiErrorException $e)` branch that requires a full payout fixture, brand/affiliate, wallet state, a mocked `transfers->create` throwing `ApiErrorException`, and a successful `refunds->create` response. That integration wiring is real work and belongs in `CommissionPayoutServiceTest.php` (see Step 5.6).

For this plan's scope (verifying the idempotency key is present) a source-level assertion is the honest minimal check: it catches regression if someone deletes the key or changes its format, without requiring the full payout simulation harness.

Append to `tests/Feature/Stripe/StripeIdempotencyKeysTest.php`:

```php
it('CommissionPayoutService refund call includes payout+PI idempotency_key', function () {
    $source = file_get_contents(base_path('app/Services/Stripe/CommissionPayoutService.php'));

    // The refund call is followed by an idempotency_key tied to BOTH the payout id
    // and the payment intent id. Tying the key to the PI matters: the failure-recovery
    // path clears stripe_payment_intent_id on successful refund, so a retry that
    // creates a fresh PI produces a distinct key rather than colliding with the stale one.
    expect($source)->toMatch('/refunds->create\s*\(\s*\[\s*[^\]]*payment_intent[^\]]*\]\s*,\s*\[\s*[\'"]idempotency_key[\'"]\s*=>\s*[\'"]rf_\{\$payout->id\}_\{\$payout->stripe_payment_intent_id\}[\'"]\s*\]\s*\)/');
});
```

**Why source regex, not a mocked service run:** A proper integration test would construct a `CommissionPayoutService` with a mock `StripeClient` where `transfers->create` throws `ApiErrorException` and `refunds->create` records its call args. That requires ~60 lines of payout/wallet/brand fixture setup. It's the right thing to add eventually (Step 5.6), but writing it is its own task, not a subtask of "add one idempotency key." The regex check above is narrow enough to catch format regressions and fast enough to run in every CI build.

- [ ] **Step 5.2: Add the idempotency key to the refund call**

Edit `app/Services/Stripe/CommissionPayoutService.php:475-477`. Replace:

```php
                    $this->stripe->refunds->create([
                        'payment_intent' => $payout->stripe_payment_intent_id,
                    ]);
```

With:

```php
                    $this->stripe->refunds->create([
                        'payment_intent' => $payout->stripe_payment_intent_id,
                    ], ['idempotency_key' => "rf_{$payout->id}_{$payout->stripe_payment_intent_id}"]);
```

- [ ] **Step 5.3: Run the source-assertion test to verify it passes**

Run: `php artisan test tests/Feature/Stripe/StripeIdempotencyKeysTest.php --filter="refund call includes payout"`

Expected: PASS (because Step 5.2 inserted the matching line).

To sanity-check the test's teeth, temporarily revert Step 5.2 and re-run — it should FAIL. Then re-apply Step 5.2.

- [ ] **Step 5.4: Run the existing `CommissionPayoutServiceTest` suite to confirm no regressions**

Run: `php artisan test tests/Feature/Stripe/CommissionPayoutServiceTest.php`

Expected: all tests PASS. The idempotency key is purely additive to the Stripe SDK call and does not affect the service's return values or side-effects.

- [ ] **Step 5.5: Commit**

```bash
git add app/Services/Stripe/CommissionPayoutService.php tests/Feature/Stripe/StripeIdempotencyKeysTest.php
git commit -m "fix(stripe): add idempotency key to transfer-failure refund"
```

- [ ] **Step 5.6 (OPTIONAL integration test): Add an end-to-end refund path test**

If `tests/Feature/Stripe/CommissionPayoutServiceTest.php` already exercises the transfer-failure path, extend it with an assertion that the `Idempotency-Key` was included. Otherwise skip — the contract test in 5.1 is sufficient for the idempotency-key requirement itself, and a full integration test of the refund path is a larger, separable task.

Only pursue 5.6 if `grep -n 'refunds->create\|transfer_failed' tests/Feature/Stripe/CommissionPayoutServiceTest.php` shows existing coverage.

---

## Task 6: Final verification

- [ ] **Step 6.1: Run the entire Stripe test suite**

Run: `php artisan test tests/Feature/Stripe/`

Expected: all existing tests PASS plus the two new files pass.

- [ ] **Step 6.2: Run the full Pest suite to confirm no cross-domain regressions**

Run: `composer test`

Expected: whole suite green. The no-Laravel-migrations guard continues to pass (we added no `database/migrations/` files).

- [ ] **Step 6.3: Run Pint to normalize formatting**

Run: `php artisan pint app/Services/Stripe/ app/Http/Controllers/Api/Webhooks/ tests/Feature/Stripe/`

Expected: Pint either makes no changes or applies only whitespace/ordering tweaks. Stage any Pint edits:

```bash
git add -u
git commit -m "chore: apply pint formatting after stripe idempotency changes"
```

- [ ] **Step 6.4: Smoke-check the diff for accidental changes**

Run: `git diff main...HEAD --stat`

Expected: only the six files listed in "File Structure" are modified/added. If anything else shows up, investigate before merging.

---

## Post-merge verification (not part of the plan, but worth doing)

After deploy, watch Nightwatch for the next 48h for:
- Any new exception with `billing.webhook_events` or `insertOrIgnore` in the stack → indicates a DB-side problem with the dedupe block.
- Any Stripe API errors with type `idempotency_error` → indicates a key collision (e.g. two distinct requests unintentionally sharing a key) that warrants a key-format revision.

Neither is expected; both are cheap to catch.
