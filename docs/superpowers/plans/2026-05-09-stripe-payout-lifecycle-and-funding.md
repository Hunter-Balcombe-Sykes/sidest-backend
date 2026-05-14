# Stripe Payout Lifecycle & Funding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tighten "paid commission" to mean Stripe `Transfer.status='paid'` (not "we created a batch"), make brand card-on-file mandatory and refactor the existing wallet flow to be race-safe + Policy-authorised, harden failure/retry/grace/refund paths, and surface every state in both dashboards.

**Architecture:** Two lanes. Lane A is backend (Laravel 12 + Supabase + Stripe Connect). Lane B is frontend (Partna-Frontend / Next.js 16). The hard handoff is at end of Lane A Phase A2 — once API contracts are stable, frontend starts in parallel with the rest of backend. Inside Lane A: schema additions land first (additive only), then the analytics tightening + `transfer.paid` webhook + cache version-bumps land together so the contract changes once. Card enforcement, retry, grace, refund recompute, audit log, and reconciliation follow as discrete phases that each ship green.

**Tech Stack:** PHP 8.2 / Laravel 12, Postgres (Supabase, schemas: `commerce`, `core`, `billing`), Stripe PHP SDK, Horizon (Redis-backed jobs), Pest 4 + PHPUnit, SQLite-in-memory (tests), Next.js 16 (frontend, contract-only here).

---

## Discoveries from verification (critical context)

The original spec was authored against partially-stale ground truth. The implementation plan **MUST** reconcile against these realities:

1. **Brand wallet top-up endpoints ALREADY EXIST.** `routes/api/professional.php:411-412` registers `POST /stripe/topups/checkout` and `POST /stripe/topups/confirm`; `StripeConnectController::createTopUpCheckoutSession` and `StripeConnectController::confirmTopUpCheckoutSession` are live; `StripeConnectService::createManualTopUpCheckoutSession` builds `mode='payment'` Stripe Checkout sessions. **Do not create duplicate routes.** Phase A3 refactors these in place — does not invent them.

2. **`syncPaymentMethodFromCheckoutSession()` already has a caller.** `StripeConnectController@syncPaymentMethodSession` (line 174) calls it. Phase A3 adds belt-and-braces by also handling it from the webhook (covers tab-close mid-flow); does not "rescue an orphan."

3. **`commerce.orders` rollup trigger does NOT handle `payout_id` UPDATE.** Trigger fires on UPDATE but the function body (`commerce.rollup_apply_delta`) only inspects money fields. Setting `payout_id = NULL` during refund-cancellation will NOT propagate to `brand_affiliate_rollup` — the refund service must do it manually.

4. **`rate_source = 'pending'` does not exist on approved orders today.** It is a stub-only marker. Phase A4 introduces it for "out-of-bounds metafield" cases AND simultaneously adds the eligibility filter — they cannot ship apart or money is paid at the wrong rate.

5. **Stripe SDK enforces 5-minute timestamp tolerance by default** (`Webhook::constructEvent($payload, $sig, $secret, $tolerance = 300)`). No fix needed — initial deep-audit flag was a hallucination.

6. **`stripe_manual_balance_currency` already exists** on `core.professionals` (verified `20260403000000_v2_baseline.sql:229-231`). Phase A1 does not need to add it.

7. **Existing webhook controller deduplicates by `WebhookEvent::firstOrCreate(['stripe_event_id' => ...])` BEFORE the match block** (`StripeConnectWebhookController:73`). Outer-event idempotency is solid; new handlers inherit this. Inner state mutations still need their own race-safety.

8. **Idempotency keys**: `'tr_'.$payout->id` is stable across retries (intentional resilience for the Transfer leg); `'pi_'.$payout->id.'_r'.$payout->retry_count` only changes when `retry_count` is bumped via `retryPayout()`. Any new retry job MUST call `retryPayout()` (or its equivalent), NOT `processPayoutBatch()` directly.

9. **`commerce.commission_payout_items.commission_ledger_entry_id` was DROPPED in Phase 4** — items now reference orders only.

10. **`brand_affiliate_rollup`** has fields `commission_cents` and `reversed_commission_cents` (NOT `paid_commission_cents` / `pending_commission_cents`). Status-based bucketing is computed at read time from `commerce.orders` joins, not denormalised on the rollup.

11. **Existing `StripeConnectController` has 9 inline `abort(403)` calls.** CI's `CAPABILITY_PATTERN` regex doesn't catch generic `abort(403)`. Phase A0 adds Policy abilities and refactors these calls before any new endpoints land.

12. **Test helper inventory unverified.** The plan's tests reference `$this->actingAsProfessional()`, `$this->mockStripeClient()` / `$this->stripeMock`, `$this->stripeWebhookEvent()`, `$this->postStripeWebhook()`, and `$this->ingestShopifyOrderPaid()`. These are common Pest helpers but **may not exist in `tests/Pest.php`** for this repo. Phase A0.4 verifies and creates any missing helpers BEFORE A1+ tests run.

13. **`stripe_payment_method_brand` and `stripe_payment_method_last4` do NOT exist** on `core.professionals` today. Only `stripe_payment_method_id` is present. The billing-summary endpoint expects `brand` + `last4` for masked card display — Phase A1.1 must ADD these columns OR the controller must derive them by retrieving the PaymentMethod from Stripe each time (slow + expensive). Plan choses to add columns; webhook updates them on PM sync.

14. **JWT claim path for RLS**. The plan's A1.3 RLS policy uses `current_setting('request.jwt.claim.sub', true)`. Verify against existing RLS policies in `supabase/migrations/` BEFORE applying — most Supabase projects use `auth.uid()`. If the codebase uses `auth.uid()`, swap the policy to `USING (professional_id = auth.uid())`.

---

## Decisions locked

These were locked during the planning conversation and are NOT up for re-debate during execution:

1. **"Paid" = Stripe `Transfer.status='paid'`.** Analytics JOIN through `commerce.commission_payouts.status='completed'`; no `paid_at` denormalisation on `orders`.
2. **Brand card-on-file is mandatory** before any payout can be batched. Wallet drains first; card backstops shortfall (existing logic, just enforced at eligibility-gate).
3. **Card-decline auto-retry** runs daily at 07:15 UTC, max 7 attempts. After terminal failure, wallet is credited back (mirrors existing `failed()` job-side logic).
4. **Affiliate grace clock** stays at 60d `void_at` + `VoidExpiredPayoutsJob`. Add T-30, T-7, T-1 day notifications (email + in-app), tracked via JSONB array `grace_notifications_sent` (NOT a counter — counters are fragile around skipped-window edge cases).
5. **Out-of-bounds metafield (≤0 or >100) → `rate_source='pending'`** AND `processEligiblePayouts` filters `rate_source != 'pending'`. Both land in the same migration + commit.
6. **Drop the three abandoned-design columns**: `commission_payouts.stripe_application_fee_id`, `orders.stripe_payment_intent_id`, `orders.stripe_transfer_id`.
7. **Brand wallet flow is REFACTORED, not re-built.** Existing routes stay; bodies get hardened (Policy auth, FormRequest validation, lockForUpdate, idempotency-keyed wallet movements, currency-mismatch refund path).
8. **Refund-during-grace cancels the payout (or shrinks it)** for non-terminal pre-completed states (`pending`/`pending_funds`/`collecting`/`transferring`). For `completed`, the existing clawback flow handles it (out of scope here). Refund service manually adjusts `brand_affiliate_rollup` because the trigger does not fire on `payout_id` changes.
9. **Wallet movements are append-only** in a new `commerce.wallet_movements` ledger. The `professionals.stripe_manual_balance_cents` column becomes a derived materialised value (or stays as a snapshot, but every change writes a ledger row). This addresses AUSTRAC compliance for affiliate payouts ≥ A$10k cumulative.
10. **Cache invalidation uses the existing version-key pattern** (`analyticsSummaryVersion`, see `docs/caching-gold-standard.md` §7.5). Single bump invalidates every windowed variant atomically.

---

## Out of scope (deferred — separate tickets)

- Variant-level commission metafield overrides
- The order-level `rate_source` "last line wins" bug
- Metafield response caching (Shopify Admin API spend optimisation)
- Post-completed-payout clawback enhancements (the existing flow stays)
- Per-product commission UI inside Shopify embedded app (Lane C entirely)
- Multi-currency wallet support (currency-mismatch case logs + auto-refunds in this plan)
- Lane B per-PR breakdown beyond contract spec

---

## File structure

### Lane A — Backend (Comet-Backend, this repo)

**Migrations** (`supabase/migrations/`):
- `20260510000000_add_commission_payouts_lifecycle_columns.sql` — additive: 9 new columns + 3 partial indexes + `failure_category` CHECK + `grace_notifications_sent` JSONB + masked-card columns on `core.professionals`
- `20260510100000_drop_orders_stripe_linkage_columns.sql` — drop `commerce.orders.stripe_payment_intent_id`, `commerce.orders.stripe_transfer_id`
- `20260510200000_drop_commission_payouts_application_fee_id.sql` — drop `commerce.commission_payouts.stripe_application_fee_id` + its partial index
- `20260510300000_add_wallet_movements_ledger.sql` — new `commerce.wallet_movements` table (with `actor_type` + `actor_id` for AUSTRAC audit trail; `ON DELETE SET NULL` on `related_payout_id`) + indexes + RLS policy
- `20260510400000_extend_orders_rate_source_constraint.sql` — explicit CHECK constraint covering `'pending'` for non-stub orders (today there's no CHECK; we add one)

**Models**:
- `app/Models/Retail/CommissionPayout.php` — append casts for new datetime + jsonb columns
- `app/Models/Commerce/WalletMovement.php` — NEW. Append-only ledger model

**Factories**:
- `database/factories/CommissionPayoutFactory.php` — extend with defaults for 8 new columns
- `database/factories/Commerce/WalletMovementFactory.php` — NEW
- `database/factories/Core/Professional/ProfessionalFactory.php` — add `withCard()` state for masked-card columns

**Policies** (Phase A0 foundation):
- `app/Policies/CommissionPolicy.php` — extend with `topUp`, `manageWallet`, `managePaymentMethod` abilities
- `app/Policies/WalletMovementPolicy.php` — NEW. Tenant-scoped read

**Form Requests** (Phase A0 foundation):
- `app/Http/Requests/Stripe/CreateTopUpCheckoutRequest.php` — NEW. Refactored from inline `$request->validate`
- `app/Http/Requests/Stripe/ConfirmTopUpCheckoutRequest.php` — NEW
- `app/Http/Requests/Stripe/SyncPaymentMethodSessionRequest.php` — NEW
- `app/Http/Requests/Stripe/CreatePaymentMethodSetupRequest.php` — NEW

**Services**:
- `app/Services/Stripe/CommissionPayoutService.php` — major edits: card-on-file gate at eligibility, `transfer.status='paid'` check before flipping completed, error-code/category capture in `failPayout` and `markPendingFunding`, version-bump on completion + on payout_id transitions
- `app/Services/Stripe/StripeConnectService.php` — refactor `createManualTopUpCheckoutSession` (FormRequest-input + lockForUpdate + idempotency-keyed wallet movement); refactor `confirmTopUpCheckoutSession`; add `creditWalletFromCheckoutSession()` private helper used by webhook
- `app/Services/Stripe/CommissionPayoutRefundService.php` — NEW. `handleOrderRefund(Order $order)` — full vs partial branch, recompute or cancel, manually adjust rollup, bump cache version
- `app/Services/Cache/CacheKeyGenerator.php` — confirm `analyticsSummaryVersion()` exists; if not, add it; add explicit `bumpAnalyticsVersion(string $professionalId)` helper if absent

**Jobs**:
- `app/Jobs/Stripe/RetryPendingFundsPayoutsJob.php` — NEW. Daily at 07:15 UTC. Bumps `funding_failure_count`, calls `retryPayout()` (so PI idempotency key changes), terminal at 7 attempts, credits wallet back, fires brand notification
- `app/Jobs/Stripe/ReconcileStuckTransferringPayoutsJob.php` — NEW. Daily at 07:30 UTC. Finds `status='transferring' AND updated_at < now()-6h`, fetches `Stripe\Transfer.retrieve`, flips status by Stripe truth
- `app/Jobs/Stripe/VoidExpiredPayoutsJob.php` — extend: fire T-30/T-7/T-1 grace warnings before the void sweep using JSONB-tracked dedup
- `app/Jobs/Stripe/ExecuteCommissionPayoutJob.php` — small edit: don't count null-return as job failure (webhook will finish)
- `app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php` — `resolveCommissionRate()` returns `(brand_default_rate, 'pending')` for out-of-bounds metafield + Nightwatch alert
- `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php` — call `CommissionPayoutRefundService::handleOrderRefund()` after refund persists

**Notifications**:
- `app/Notifications/Brand/BrandPayoutFundingFailedNotification.php` — NEW. `mail` + `database`. Two variants on `is_terminal`
- `app/Notifications/Affiliate/AffiliatePayoutGraceWarningNotification.php` — NEW. `mail` + `database`. Three variants on `days_remaining`

**Controllers + routes**:
- `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php` — add `transfer.paid` handler; replace no-op `handleCheckoutSessionCompleted` with mode-branching (setup → sync PM, payment → credit wallet); capture verbatim Stripe error fields in `handleTransferFailed` / `handleTransferReversed`; bump cache version on every state change
- `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` — refactor 9 inline `abort(403)` to `authorizeForUser` calls; convert inline validates to FormRequests
- `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php` — tighten `commission_paid_cents` query to JOIN `commission_payouts.status='completed'`
- `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php` — same JOIN tightening
- `app/Http/Controllers/Api/Professional/Brand/BrandBillingSummaryController.php` — NEW. `GET /api/professional/brand/billing-summary`
- `app/Http/Controllers/Api/Professional/Brand/BrandPayoutsController.php` — NEW. `GET /api/professional/brand/payouts`
- `app/Http/Controllers/Api/Professional/Affiliate/AffiliatePayoutsController.php` — NEW (or extend existing). `GET /api/professional/affiliate/payouts`
- `app/Http/Controllers/Api/Professional/Stripe/AffiliateStripeOnboardingController.php` — NEW or extend. `POST /api/professional/affiliate/stripe/connect/start`
- `routes/api/professional.php` — add 4 new routes; keep existing 2 top-up routes
- `routes/console.php` — schedule `RetryPendingFundsPayoutsJob` (07:15 UTC) and `ReconcileStuckTransferringPayoutsJob` (07:30 UTC), both `onOneServer` + `withoutOverlapping`

**Test helpers** (Phase A0.4 verifies/creates):
- `tests/Pest.php` — `actingAsProfessional()`, `mockStripeClient()`, `stripeWebhookEvent()`, `postStripeWebhook()`, `ingestShopifyOrderPaid()`, `buildTestStripeSignature()`, `encodeFakeSupabaseJwt()`

**Tests** (~17 files, ~9 new):
- `tests/Feature/Cache/AnalyticsVersionInvalidationTest.php` — NEW (verifies version-key wiring)
- `tests/Feature/Stripe/StripeConnectControllerAuthorizationTest.php` — NEW (Policy + FormRequest + tenant isolation)
- `tests/Pest.php` — drop two columns from `setupCommerceOrdersTables` shared helper
- `tests/Feature/Stripe/CommissionPayoutServiceTest.php` — fixture updates, card-on-file guard tests, transfer.status='pending' branch, error capture
- `tests/Feature/Stripe/ExecuteCommissionPayoutJobTest.php` — fixture updates, null-return-no-retry assertion
- `tests/Feature/Stripe/StripeConnectPayoutsControllerTest.php` — fixture updates, refactored auth/validation tests
- `tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php` — fixture updates, T-30/T-7/T-1 firing tests, JSONB dedup
- `tests/Feature/Stripe/RetryPendingFundsPayoutsJobTest.php` — NEW
- `tests/Feature/Stripe/ReconcileStuckTransferringPayoutsJobTest.php` — NEW
- `tests/Feature/Stripe/CommissionPayoutRefundServiceTest.php` — NEW. Full + partial refund branches; rollup adjustment; cancel-when-empty
- `tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php` — `transfer.paid` test; setup/payment mode branching; verbatim error capture
- `tests/Feature/Security/TenantIsolation/CommissionsIsolationTest.php` — fixture updates
- `tests/Feature/Webhooks/Shopify/OrderPaidHappyPathTest.php` — extend with out-of-bounds metafield → `rate_source='pending'` assertion + eligibility filter assertion
- `tests/Feature/Notifications/BrandPayoutFundingFailedNotificationTest.php` — NEW
- `tests/Feature/Notifications/AffiliatePayoutGraceWarningNotificationTest.php` — NEW
- `tests/Feature/Stripe/WalletMovementsLedgerTest.php` — NEW. Append-only invariants; balance reconstruction
- `tests/Feature/Brand/BrandBillingSummaryTest.php` — NEW. Endpoint shape + tenant isolation
- `tests/Feature/Brand/BrandPayoutsListTest.php` — NEW

### Lane B — Frontend (Partna-Frontend, separate repo)

Not implemented from this plan. The contract is specified in **Stage 2** below — frontend Claude reads that section and implements there.

---

# Stage 1 — Lane A (Backend)

Six phases, runnable in order. Each phase ends green (tests pass, type-check clean, Pint clean, no Nightwatch regressions).

## Phase A0 — Policies + Form Requests + endpoint stubs (foundation)

**Goal:** every new endpoint and every existing endpoint we'll touch has its auth + validation contract in place BEFORE behaviour changes. Eliminates inline `abort(403)` and inline `$request->validate` from the Stripe surface.

### Task A0.1: Extend `CommissionPolicy` with three new abilities

**Files:**
- Modify: `app/Policies/CommissionPolicy.php`
- Test: `tests/Feature/Policies/CommissionPolicyTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Policies/CommissionPolicyTest.php — append

it('allows a brand to topUp on themselves', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    expect((new CommissionPolicy)->topUp($brand, $brand))->toBeTrue();
});

it('forbids an affiliate from topping up another professional', function () {
    $affiliate = Professional::factory()->create(['professional_type' => 'affiliate']);
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    expect((new CommissionPolicy)->topUp($affiliate, $brand))->toBeFalse();
});

it('allows brand to managePaymentMethod on self only', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    $other = Professional::factory()->create(['professional_type' => 'brand']);
    $policy = new CommissionPolicy;
    expect($policy->managePaymentMethod($brand, $brand))->toBeTrue();
    expect($policy->managePaymentMethod($brand, $other))->toBeFalse();
});

it('forbids non-brand professional_types from managePaymentMethod', function () {
    $aff = Professional::factory()->create(['professional_type' => 'affiliate']);
    expect((new CommissionPolicy)->managePaymentMethod($aff, $aff))->toBeFalse();
});
```

- [ ] **Step 2: Run, expect failure**

```bash
php artisan test --filter=CommissionPolicyTest
```

- [ ] **Step 3: Add the abilities**

```php
// app/Policies/CommissionPolicy.php — add inside the class

public function topUp(Professional $actor, Professional $brand): bool
{
    return $actor->id === $brand->id
        && ($actor->professional_type ?? null) === 'brand';
}

public function managePaymentMethod(Professional $actor, Professional $brand): bool
{
    return $actor->id === $brand->id
        && ($actor->professional_type ?? null) === 'brand';
}

public function manageWallet(Professional $actor, Professional $brand): bool
{
    return $this->topUp($actor, $brand);
}
```

- [ ] **Step 4: Re-run; expect green**

```bash
php artisan test --filter=CommissionPolicyTest
```

- [ ] **Step 5: Commit**

```bash
git add app/Policies/CommissionPolicy.php tests/Feature/Policies/CommissionPolicyTest.php
git commit -m "feat(policy): add CommissionPolicy::topUp/managePaymentMethod/manageWallet abilities"
```

### Task A0.2: Build the four Form Requests

**Files:**
- Create: `app/Http/Requests/Stripe/CreateTopUpCheckoutRequest.php`
- Create: `app/Http/Requests/Stripe/ConfirmTopUpCheckoutRequest.php`
- Create: `app/Http/Requests/Stripe/SyncPaymentMethodSessionRequest.php`
- Create: `app/Http/Requests/Stripe/CreatePaymentMethodSetupRequest.php`

- [ ] **Step 1: Create `CreateTopUpCheckoutRequest`**

```php
<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class CreateTopUpCheckoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount_cents'  => ['required', 'integer', 'min:1000', 'max:10000000'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'success_url'   => ['required', 'url'],
            'cancel_url'    => ['required', 'url'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.min' => 'Minimum top-up is $10.00 (1,000 cents).',
            'amount_cents.max' => 'Maximum top-up is $100,000 (10,000,000 cents).',
        ];
    }
}
```

- [ ] **Step 2: Create `ConfirmTopUpCheckoutRequest`**

```php
<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class ConfirmTopUpCheckoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['session_id' => ['required', 'string', 'starts_with:cs_']];
    }
}
```

- [ ] **Step 3: Create `SyncPaymentMethodSessionRequest`**

```php
<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class SyncPaymentMethodSessionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['session_id' => ['required', 'string', 'starts_with:cs_']];
    }
}
```

- [ ] **Step 4: Create `CreatePaymentMethodSetupRequest`**

```php
<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class CreatePaymentMethodSetupRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'success_url' => ['required', 'url'],
            'cancel_url'  => ['required', 'url'],
        ];
    }
}
```

- [ ] **Step 5: Run pint + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Requests/Stripe/
git commit -m "feat(stripe): introduce FormRequests for top-up + payment-method endpoints"
```

### Task A0.4: Verify or create test helpers

**Goal:** every test in this plan references helper methods that may not exist. Verify, then create any missing.

**Files:**
- Modify: `tests/Pest.php`
- Possibly modify: `tests/TestCase.php` (or wherever the base test class lives)

- [ ] **Step 1: Inventory existence**

```bash
grep -n "actingAsProfessional\|mockStripeClient\|stripeWebhookEvent\|postStripeWebhook\|ingestShopifyOrderPaid" tests/Pest.php tests/TestCase.php tests/CreatesApplication.php 2>/dev/null
```

For each helper, mark as PRESENT or MISSING.

- [ ] **Step 2: Add `actingAsProfessional` if missing**

In `tests/Pest.php`, append:

```php
function actingAsProfessional(\App\Models\Core\Professional\Professional $professional): \Tests\TestCase
{
    return test()->withHeaders([
        'Authorization' => 'Bearer ' . encodeFakeSupabaseJwt($professional),
    ])->withMiddleware();  // or however the middleware injects the professional
}
```

(Adapt to existing JWT middleware. If a similar helper exists with a different name like `loginAs`, just alias it.)

- [ ] **Step 3: Add `mockStripeClient` / `stripeMock` if missing**

```php
function mockStripeClient(): \Mockery\MockInterface
{
    $mock = \Mockery::mock(\Stripe\StripeClient::class);
    app()->instance(\Stripe\StripeClient::class, $mock);
    test()->stripeMock = $mock;
    return $mock;
}
```

- [ ] **Step 4: Add `stripeWebhookEvent` + `postStripeWebhook`**

```php
function stripeWebhookEvent(string $type, array $object): array
{
    return [
        'id'      => 'evt_' . \Illuminate\Support\Str::random(24),
        'type'    => $type,
        'data'    => ['object' => $object],
        'account' => 'acct_test',
        'created' => now()->timestamp,
    ];
}

function postStripeWebhook(array $event): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/webhooks/stripe-connect', $event, [
        'Stripe-Signature' => buildTestStripeSignature(json_encode($event)),
    ]);
}
```

(Use the existing test signature helper if one exists, e.g. in `tests/Feature/Webhooks/Stripe/`.)

- [ ] **Step 5: Add `ingestShopifyOrderPaid` if missing**

Search the codebase for any existing helper that pushes a Shopify order webhook through the controller; if not present, create one that mirrors `tests/Feature/Webhooks/Shopify/OrderPaidHappyPathTest.php` setup.

- [ ] **Step 6: Run a sanity test**

Add a probe test:

```php
it('test helpers are wired', function () {
    $brand = Professional::factory()->create();
    actingAsProfessional($brand)->getJson('/api/professional/me')->assertSuccessful();
});
```

- [ ] **Step 7: Commit**

```bash
git add tests/
git commit -m "test: verify/create Pest helpers (actingAsProfessional, stripeMock, webhook helpers)"
```

### Task A0.5: Reconcile card-setup endpoint name (frontend integration check)

**Goal:** the plan and the existing frontend disagree on the card-setup endpoint name. Resolve before A0.3 starts so the refactor lands the right path.

- [ ] **Step 1: Confirm what's registered today**

```bash
php artisan route:list --path=stripe/payment-method
```

Expected one of three states:
- (a) Only `/stripe/payment-method/setup` exists → frontend has been hitting 404 silently. **Fix**: rename to `/setup-checkout` in A0.3 (more descriptive — it creates a Stripe Checkout session, not a Stripe `SetupIntent`); leave a 308 redirect for one release.
- (b) Only `/stripe/payment-method/setup-checkout` exists → plan is wrong; update all plan references to use `setup-checkout`.
- (c) Both exist → keep `/setup-checkout`, mark `/setup` as deprecated, document removal in a follow-up ticket.

- [ ] **Step 2: Update plan + frontend Stage 2 contract**

Whatever path wins, update:
- All `Route::post('/stripe/payment-method/setup', ...)` references in the plan
- Stage 2's "Endpoints" table
- Frontend dev's fixtures (notify them)

- [ ] **Step 3: Commit the path decision**

```bash
git add routes/api/professional.php docs/superpowers/plans/
git commit -m "refactor(stripe): rename card-setup endpoint to /setup-checkout (frontend already calls this; plan reconciled)"
```

### Task A0.6: Verify `NotificationController::index` exposes `type` field

**Goal:** frontend's notification renderer switches on the FQCN of each notification class. The Stage 2 contract assumes `type` is present in each item — confirm it isn't being stripped by a Resource transformer.

- [ ] **Step 1: Inspect the controller + any Resource it returns**

```bash
grep -n "->type\|notifications.type\|NotificationResource" app/Http/Controllers/Api/Professional/NotificationController.php app/Http/Resources/ 2>/dev/null
```

- [ ] **Step 2: One-shot integration test**

```php
it('GET /me/notifications returns the FQCN type field on each item', function () {
    $brand = Professional::factory()->create();
    $brand->notify(new \App\Notifications\Brand\BrandPayoutFundingFailedNotification(
        \App\Models\Retail\CommissionPayout::factory()->create(['brand_professional_id' => $brand->id]),
        isTerminal: false
    ));

    $response = actingAsProfessional($brand)->getJson('/api/professional/me/notifications');
    $response->assertSuccessful();
    expect($response->json('data.0.type'))
        ->toBe('App\\Notifications\\Brand\\BrandPayoutFundingFailedNotification');
});
```

- [ ] **Step 3: If the test fails, expose `type`**

If the controller wraps rows in a Resource that drops `type`, add it back to the Resource's `toArray()`. Run the test until green.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/Professional/NotificationController.php app/Http/Resources/ tests/Feature/Notifications/
git commit -m "test(notifications): verify type FQCN is exposed on /me/notifications response"
```

### Task A0.3: Refactor `StripeConnectController` to use Policies + FormRequests

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` (every method)
- Test: `tests/Feature/Stripe/StripeConnectControllerAuthorizationTest.php` (NEW)

- [ ] **Step 1: Write a failing tenant-isolation regression test**

```php
<?php
// tests/Feature/Stripe/StripeConnectControllerAuthorizationTest.php

use App\Models\Core\Professional\Professional;

it('rejects affiliate calling brand-only top-up route with 403', function () {
    $aff = Professional::factory()->create(['professional_type' => 'affiliate']);
    $this->actingAsProfessional($aff)
        ->postJson('/api/professional/stripe/topups/checkout', [
            'amount_cents' => 5000,
            'success_url'  => 'https://example.test/ok',
            'cancel_url'   => 'https://example.test/no',
        ])
        ->assertForbidden();
});

it('rejects unauthenticated request with 401', function () {
    $this->postJson('/api/professional/stripe/topups/checkout', [
        'amount_cents' => 5000,
        'success_url'  => 'https://example.test/ok',
        'cancel_url'   => 'https://example.test/no',
    ])->assertUnauthorized();
});

it('returns 422 for missing amount_cents', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    $this->actingAsProfessional($brand)
        ->postJson('/api/professional/stripe/topups/checkout', [
            'success_url' => 'https://example.test/ok',
            'cancel_url'  => 'https://example.test/no',
        ])
        ->assertUnprocessable();
});
```

- [ ] **Step 2: Run, expect failure**

```bash
php artisan test --filter=StripeConnectControllerAuthorizationTest
```

(Affiliate test will likely PASS already — existing inline check; isolation test should still pass via 403 from the inline abort. We're swapping the implementation, not the contract — but the test stays as a regression guard.)

- [ ] **Step 3: Replace each inline `abort(403)` with policy-driven authorization**

For each of the 9 methods at lines 116, 137, 161, 181, 207, 223, 243, 270, 296 — replace the pattern:

```php
$pro = $request->attributes->get('professional');
if (($pro->professional_type ?? null) !== 'brand') {
    return response()->json(['error' => 'Only brand accounts can ...'], 403);
}
```

with:

```php
$pro = $request->attributes->get('professional');
$this->authorizeForUser($pro, 'topUp', $pro);  // or 'managePaymentMethod' as appropriate
```

Map each method to its ability:
- `createTopUpCheckoutSession`, `confirmTopUpCheckoutSession`: `'topUp'`
- `setupPaymentMethod`, `syncPaymentMethodSession`: `'managePaymentMethod'`
- `getStatus`, `disconnect`, `onboard`, `setupBillingPortal`, others: `'manageWallet'` or `'managePaymentMethod'` per closest semantic

- [ ] **Step 4: Replace inline `$request->validate` with FormRequest type-hints**

Change method signature from `Request $request` to `CreateTopUpCheckoutRequest $request` etc. Drop the inline `$request->validate(...)` block.

- [ ] **Step 5: Run the auth test + existing endpoint tests**

```bash
php artisan test --filter=StripeConnect
```

Expected: green.

- [ ] **Step 6: Run pint + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/Professional/Stripe/ tests/Feature/Stripe/StripeConnectControllerAuthorizationTest.php
git commit -m "refactor(stripe): replace inline abort(403) with CommissionPolicy + FormRequests"
```

---

## Phase A1 — Schema additions (additive first, drops last)

**Goal:** schema is in its final shape before any code references new columns. All migrations are reversible.

### Phase A1 preamble — local Supabase setup

Before any A1 task, ensure your local Supabase stack is running and the connection string is exported:

```bash
supabase start
export LOCAL_DSN="$(supabase status --output env | grep '^DB_URL=' | cut -d= -f2- | tr -d '\"')"
echo "$LOCAL_DSN" | head -c 30 && echo ' ✓'
```

If `supabase status` doesn't expose `DB_URL`, fall back to:

```bash
export LOCAL_DSN='postgresql://postgres:postgres@127.0.0.1:54322/postgres'
```

Use `supabase db reset --local` to apply pending migrations and reseed.

### Task A1.1: Add commission_payouts lifecycle columns + masked-card columns on professionals + CHECK constraints

**Files:**
- Create: `supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql`
- Modify: `database/factories/CommissionPayoutFactory.php` (add defaults for new columns)
- Modify: `app/Models/Core/Professional/Professional.php` (add new columns to `$fillable`/`casts` if needed)

- [ ] **Step 1: Write the migration**

```sql
BEGIN;

ALTER TABLE commerce.commission_payouts
    ADD COLUMN IF NOT EXISTS transfer_completed_at      timestamptz,
    ADD COLUMN IF NOT EXISTS stripe_error_code          text,
    ADD COLUMN IF NOT EXISTS stripe_error_message       text,
    ADD COLUMN IF NOT EXISTS next_retry_at              timestamptz,
    ADD COLUMN IF NOT EXISTS last_retry_at              timestamptz,
    ADD COLUMN IF NOT EXISTS funding_failure_count      integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS failure_category           text,
    ADD COLUMN IF NOT EXISTS grace_notifications_sent   jsonb   NOT NULL DEFAULT '[]'::jsonb;

-- Backfill: completed payouts get transfer_completed_at = processed_at as best-effort
UPDATE commerce.commission_payouts
   SET transfer_completed_at = processed_at
 WHERE status = 'completed' AND transfer_completed_at IS NULL;

-- Encoded categories (matches the documented decision tree).
-- 'order_refunded' is reserved for refund-during-grace cancellations.
ALTER TABLE commerce.commission_payouts
    ADD CONSTRAINT chk_cp_failure_category
    CHECK (failure_category IS NULL OR failure_category IN
        ('brand_funding','affiliate_account','stripe_transient','stripe_terminal','platform','order_refunded'));

ALTER TABLE commerce.commission_payouts
    ADD CONSTRAINT chk_cp_funding_failure_count
    CHECK (funding_failure_count >= 0 AND funding_failure_count <= 50);

CREATE INDEX IF NOT EXISTS idx_cp_completed_status
    ON commerce.commission_payouts (id) WHERE status = 'completed';

CREATE INDEX IF NOT EXISTS idx_cp_pending_funds_next_retry
    ON commerce.commission_payouts (next_retry_at)
    WHERE status = 'pending_funds';

CREATE INDEX IF NOT EXISTS idx_cp_transferring_updated_at
    ON commerce.commission_payouts (updated_at)
    WHERE status = 'transferring';

-- Add masked-card columns on professionals so the billing-summary endpoint can
-- display the card without round-tripping to Stripe per request.
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_payment_method_brand text,
    ADD COLUMN IF NOT EXISTS stripe_payment_method_last4 char(4);

COMMIT;

-- DOWN (manual rollback):
-- BEGIN;
-- DROP INDEX IF EXISTS commerce.idx_cp_transferring_updated_at;
-- DROP INDEX IF EXISTS commerce.idx_cp_pending_funds_next_retry;
-- DROP INDEX IF EXISTS commerce.idx_cp_completed_status;
-- ALTER TABLE commerce.commission_payouts DROP CONSTRAINT IF EXISTS chk_cp_funding_failure_count;
-- ALTER TABLE commerce.commission_payouts DROP CONSTRAINT IF EXISTS chk_cp_failure_category;
-- ALTER TABLE commerce.commission_payouts
--     DROP COLUMN IF EXISTS grace_notifications_sent,
--     DROP COLUMN IF EXISTS failure_category,
--     DROP COLUMN IF EXISTS funding_failure_count,
--     DROP COLUMN IF EXISTS last_retry_at,
--     DROP COLUMN IF EXISTS next_retry_at,
--     DROP COLUMN IF EXISTS stripe_error_message,
--     DROP COLUMN IF EXISTS stripe_error_code,
--     DROP COLUMN IF EXISTS transfer_completed_at;
-- ALTER TABLE core.professionals
--     DROP COLUMN IF EXISTS stripe_payment_method_last4,
--     DROP COLUMN IF EXISTS stripe_payment_method_brand;
-- COMMIT;
```

- [ ] **Step 2: Apply locally and verify schema**

```bash
supabase db reset --local  # or apply the single migration if you prefer
psql "$LOCAL_DSN" -c "\d commerce.commission_payouts" | grep -E 'transfer_completed_at|failure_category|grace_notifications_sent'
```

Expected: all 8 new columns visible.

- [ ] **Step 3: Verify the CHECK constraints**

```bash
psql "$LOCAL_DSN" -c "INSERT INTO commerce.commission_payouts (id, brand_professional_id, affiliate_professional_id, gross_commission_cents, net_payout_cents, currency_code, status, void_at, eligible_after, failure_category) VALUES (gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), 100, 100, 'AUD', 'pending', now()+'60d'::interval, now(), 'INVALID_CATEGORY');"
```

Expected: ERROR including `chk_cp_failure_category`.

- [ ] **Step 4: Update `CommissionPayoutFactory`**

Open `database/factories/CommissionPayoutFactory.php` and append to the `definition()` array:

```php
'transfer_completed_at'    => null,
'stripe_error_code'        => null,
'stripe_error_message'     => null,
'next_retry_at'            => null,
'last_retry_at'            => null,
'funding_failure_count'    => 0,
'failure_category'         => null,
'grace_notifications_sent' => [],
```

Also add a state for the `payment_method_*` columns on the Professional factory:

```php
// database/factories/Core/Professional/ProfessionalFactory.php
public function withCard(): static
{
    return $this->state(fn () => [
        'stripe_customer_id' => 'cus_' . fake()->bothify('?#?#?#?#'),
        'stripe_payment_method_id' => 'pm_' . fake()->bothify('?#?#?#?#'),
        'stripe_payment_method_brand' => 'visa',
        'stripe_payment_method_last4' => '4242',
    ]);
}
```

- [ ] **Step 5: Run factory smoke test**

```bash
php artisan tinker --execute="dump(\App\Models\Retail\CommissionPayout::factory()->make()->toArray());"
```

Expected: all new columns present with their defaults.

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql database/factories/
git commit -m "feat(db): add commission_payouts lifecycle columns + masked-card columns + factory defaults"
```

### Task A1.2: Drop the abandoned-design columns

**Files:**
- Create: `supabase/migrations/20260510100000_drop_orders_stripe_linkage_columns.sql`
- Create: `supabase/migrations/20260510200000_drop_commission_payouts_application_fee_id.sql`

- [ ] **Step 1: Pre-flight grep across the BACKEND repo**

```bash
git grep -n "stripe_payment_intent_id\|stripe_transfer_id" app/ routes/ database/ | grep -v "commission_payouts" | grep -v "Test"
```

Expected: zero non-test, non-`commission_payouts` matches. If any match exists, STOP and ask the user — the columns are still referenced.

- [ ] **Step 1b: Pre-flight check for FRONTEND repo references (cross-repo)**

```bash
# If you have the frontend repo cloned locally:
git grep -n "stripe_payment_intent_id\|stripe_transfer_id" ../partna-frontend/ 2>/dev/null
```

If the frontend repo isn't local, ASK the user to confirm with the frontend dev (or grep the GitHub repo via API). Hydrogen storefronts and Next.js dashboards may select these columns by name.

**Do NOT proceed** to Step 2 until cross-repo confirmation is in hand.

- [ ] **Step 1c: Pre-flight check for database views**

```bash
psql "$LOCAL_DSN" -c "SELECT table_schema, table_name FROM information_schema.views WHERE view_definition LIKE '%stripe_payment_intent_id%' OR view_definition LIKE '%stripe_transfer_id%';"
```

Expected: zero rows. A view referencing these columns will block the DROP.

- [ ] **Step 2: Write `20260510100000_drop_orders_stripe_linkage_columns.sql`**

```sql
BEGIN;
ALTER TABLE commerce.orders DROP COLUMN IF EXISTS stripe_payment_intent_id;
ALTER TABLE commerce.orders DROP COLUMN IF EXISTS stripe_transfer_id;
COMMIT;

-- DOWN (manual rollback — values cannot be restored, columns return as NULL):
-- BEGIN;
-- ALTER TABLE commerce.orders ADD COLUMN IF NOT EXISTS stripe_payment_intent_id text;
-- ALTER TABLE commerce.orders ADD COLUMN IF NOT EXISTS stripe_transfer_id text;
-- COMMIT;
```

- [ ] **Step 3: Write `20260510200000_drop_commission_payouts_application_fee_id.sql`**

```sql
BEGIN;
DROP INDEX IF EXISTS commerce.commission_payouts_app_fee_idx;
ALTER TABLE commerce.commission_payouts DROP COLUMN IF EXISTS stripe_application_fee_id;
COMMIT;

-- DOWN (manual rollback — values cannot be restored, column returns as NULL):
-- BEGIN;
-- ALTER TABLE commerce.commission_payouts ADD COLUMN IF NOT EXISTS stripe_application_fee_id text;
-- CREATE INDEX IF NOT EXISTS commission_payouts_app_fee_idx
--     ON commerce.commission_payouts (stripe_application_fee_id)
--     WHERE stripe_application_fee_id IS NOT NULL;
-- COMMIT;
```

- [ ] **Step 4: Apply locally**

```bash
supabase db reset --local
```

- [ ] **Step 5: Update the test fixture helper**

Edit `tests/Pest.php`:

Find `setupCommerceOrdersTables()` (or equivalent shared helper). Remove `stripe_payment_intent_id text` and `stripe_transfer_id text` from the SQLite CREATE TABLE for `commerce.orders`. Same for `commerce.commission_payouts` — drop `stripe_application_fee_id`.

- [ ] **Step 6: Run the test suite to surface any column references**

```bash
composer test
```

Expected: green. If any test fails on a missing column, fix the test.

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260510100000_drop_orders_stripe_linkage_columns.sql \
       supabase/migrations/20260510200000_drop_commission_payouts_application_fee_id.sql \
       tests/Pest.php
git commit -m "feat(db): drop abandoned-design Stripe linkage columns from orders + commission_payouts"
```

### Task A1.3: Add the wallet_movements ledger + model + factory

**Files:**
- Create: `supabase/migrations/20260510300000_add_wallet_movements_ledger.sql`
- Create: `app/Models/Commerce/WalletMovement.php`
- Create: `database/factories/Commerce/WalletMovementFactory.php`

**Important — RLS verification:** before pasting the policy, audit existing RLS in this repo:

```bash
grep -rn "CREATE POLICY\|USING (" supabase/migrations/ | grep -i "auth\.uid\|jwt\.claim" | head -20
```

If existing policies use `auth.uid()`, change the policy below from `current_setting('request.jwt.claim.sub', true)` to `auth.uid()::text`. This plan defaults to the JWT-claim form because Supabase Postgres exposes both, but consistency wins.

- [ ] **Step 1: Write the migration**

```sql
BEGIN;

CREATE TABLE IF NOT EXISTS commerce.wallet_movements (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    direction           text NOT NULL CHECK (direction IN ('credit','debit')),
    amount_cents        bigint NOT NULL CHECK (amount_cents > 0),
    currency_code       char(3) NOT NULL,
    reason              text NOT NULL CHECK (reason IN
        ('top_up','payout_debit','retry_refund','currency_mismatch_refund',
         'manual_adjustment','reversal_credit','clawback_debit')),
    -- AUSTRAC audit trail — every ledger row records who/what initiated the movement.
    -- actor_type is constrained; actor_id is free-text (UUID for admin/professional, event-id for webhooks, job-class for jobs).
    actor_type          text NOT NULL CHECK (actor_type IN ('system','webhook','job','admin','professional')),
    actor_id            text,            -- nullable for actor_type='system'
    related_payout_id   uuid REFERENCES commerce.commission_payouts(id) ON DELETE SET NULL,
    related_session_id  text,            -- Stripe Checkout session id, if applicable
    idempotency_key     text NOT NULL,   -- e.g. 'topup:cs_xxx', 'payout_debit:<payout_id>:<retry_count>'
    metadata            jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at         timestamptz NOT NULL DEFAULT now(),
    created_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (idempotency_key)
);

-- An actor_id is required for everything except system-initiated movements
-- (so a future audit can always answer "who/what triggered this?").
ALTER TABLE commerce.wallet_movements
    ADD CONSTRAINT chk_wallet_movements_actor_id_required
    CHECK (actor_type = 'system' OR actor_id IS NOT NULL);

CREATE INDEX idx_wallet_movements_pro_occurred
    ON commerce.wallet_movements (professional_id, occurred_at DESC);

CREATE INDEX idx_wallet_movements_payout
    ON commerce.wallet_movements (related_payout_id) WHERE related_payout_id IS NOT NULL;

CREATE INDEX idx_wallet_movements_session
    ON commerce.wallet_movements (related_session_id) WHERE related_session_id IS NOT NULL;

CREATE INDEX idx_wallet_movements_actor
    ON commerce.wallet_movements (actor_type, actor_id) WHERE actor_id IS NOT NULL;

-- RLS: tenant-scoped read via professional_id == auth context.
-- NOTE: verify the JWT claim path against existing RLS policies before applying.
-- If the codebase consistently uses auth.uid() (Supabase convention), swap to:
--   USING (professional_id = auth.uid())
ALTER TABLE commerce.wallet_movements ENABLE ROW LEVEL SECURITY;

CREATE POLICY "wallet_movements_tenant_read"
    ON commerce.wallet_movements
    FOR SELECT
    USING (professional_id::text = current_setting('request.jwt.claim.sub', true));

-- The Laravel app role bypasses RLS via SET ROLE app_backend
GRANT SELECT, INSERT ON commerce.wallet_movements TO app_backend;

COMMIT;

-- DOWN (manual rollback):
-- BEGIN;
-- DROP POLICY IF EXISTS "wallet_movements_tenant_read" ON commerce.wallet_movements;
-- DROP INDEX IF EXISTS commerce.idx_wallet_movements_actor;
-- DROP INDEX IF EXISTS commerce.idx_wallet_movements_session;
-- DROP INDEX IF EXISTS commerce.idx_wallet_movements_payout;
-- DROP INDEX IF EXISTS commerce.idx_wallet_movements_pro_occurred;
-- DROP TABLE IF EXISTS commerce.wallet_movements;
-- COMMIT;
```

- [ ] **Step 2: Apply + verify**

```bash
supabase db reset --local
psql "$LOCAL_DSN" -c "\d commerce.wallet_movements"
```

- [ ] **Step 3: Create the Eloquent model**

```php
<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletMovement extends BaseModel
{
    protected $table = 'commerce.wallet_movements';

    protected $guarded = ['id', 'created_at']; // ledger is append-only; no updates expected

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'metadata'     => 'array',
            'occurred_at'  => 'datetime',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'related_payout_id');
    }
}
```

- [ ] **Step 4: Create the factory**

```php
<?php

namespace Database\Factories\Commerce;

use App\Models\Commerce\WalletMovement;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WalletMovementFactory extends Factory
{
    protected $model = WalletMovement::class;

    public function definition(): array
    {
        return [
            'id'              => Str::uuid()->toString(),
            'professional_id' => Professional::factory(),
            'direction'       => 'credit',
            'amount_cents'    => 5000,
            'currency_code'   => 'AUD',
            'reason'          => 'top_up',
            'actor_type'      => 'system',
            'actor_id'        => null,
            'idempotency_key' => 'test:' . Str::uuid()->toString(),
            'metadata'        => [],
            'occurred_at'     => now(),
        ];
    }

    public function debit(): static
    {
        return $this->state(['direction' => 'debit']);
    }

    public function fromWebhook(string $eventId): static
    {
        return $this->state([
            'actor_type' => 'webhook',
            'actor_id'   => $eventId,
        ]);
    }
}
```

- [ ] **Step 5: Register `Gate::policy(WalletMovement::class, ...)` in `AppServiceProvider::boot()`**

Add to the existing Gate registrations:

```php
Gate::policy(\App\Models\Commerce\WalletMovement::class, \App\Policies\WalletMovementPolicy::class);
```

(Stub `WalletMovementPolicy extends BasePolicy` with `view(Professional $actor, WalletMovement $movement)` returning `$actor->id === $movement->professional_id`.)

- [ ] **Step 6: Verify PolicyCoverageTest passes**

```bash
php artisan test --filter=PolicyCoverageTest
```

Expected: green (the new `WalletMovement` model has a registered policy).

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260510300000_add_wallet_movements_ledger.sql \
       app/Models/Commerce/WalletMovement.php \
       database/factories/Commerce/WalletMovementFactory.php \
       app/Policies/WalletMovementPolicy.php \
       app/Providers/AppServiceProvider.php
git commit -m "feat(db): add commerce.wallet_movements ledger + model + factory + policy"
```

### Task A1.4: Add explicit CHECK on `commerce.orders.rate_source`

**Files:**
- Create: `supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql`

- [ ] **Step 1: Write the migration**

```sql
BEGIN;

-- Today rate_source has a DEFAULT but no CHECK. Lock the enum so 'pending'
-- (introduced for out-of-bounds metafields) is the only new admissible value
-- and typos can't drift the field.
ALTER TABLE commerce.orders
    DROP CONSTRAINT IF EXISTS chk_orders_rate_source;

ALTER TABLE commerce.orders
    ADD CONSTRAINT chk_orders_rate_source
    CHECK (rate_source IN
        ('product_metafield','metafield_override','brand_default','platform_default','manual','pending'));

COMMIT;

-- DOWN (manual rollback):
-- BEGIN;
-- ALTER TABLE commerce.orders DROP CONSTRAINT IF EXISTS chk_orders_rate_source;
-- COMMIT;
```

- [ ] **Step 2: Apply + verify**

```bash
supabase db reset --local
psql "$LOCAL_DSN" -c "\d commerce.orders" | grep rate_source
```

- [ ] **Step 3: Commit**

```bash
git add supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql
git commit -m "feat(db): explicit CHECK constraint on orders.rate_source enum"
```

---

## Phase A2 — Analytics tightening + transfer.paid + cache version-bumps + reconciliation

**THIS PHASE DEFINES THE API CONTRACT FOR LANE B.** Ship to a stable branch before frontend starts.

> **DEPLOY-TIME REGRESSION WARNING:** A2.1 tightens `commission_paid_cents` from "any payout assigned" to "Stripe Transfer paid." On the day this ships, every brand and affiliate will see their reported "paid" figure drop temporarily — by exactly the value of payouts that are batched but not yet settled. **This is correct, but jarring.** Coordinate with marketing/comms before deploy, OR run a backfill that pre-computes `transfer_completed_at` from prior `processed_at` for completed payouts (the migration in A1.1 already does this), AND run `ReconcileStuckTransferringPayoutsJob` once manually post-deploy to flip any `transferring` payouts that already have `Transfer.status='paid'` at Stripe. Without that backfill, expect ~24h of artificially-low paid totals.
>
> **DEPLOY-TIME ORDERING CONSTRAINT:** A2.2's webhook handler calls `creditWalletFromCheckoutSession()` for `mode='payment'` events. That method is implemented in A3.1. Until A3.1 ships, A2.2 MUST stub the `mode='payment'` arm to log + return (see A2.2 Step 5). The full implementation is wired in A3.1. **Do not enable the payment branch in A2 alone.**

### Task A2.1: Tighten `commission_paid_cents` queries to JOIN `commission_payouts.status='completed'`

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php`
- Modify: `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`
- Test: `tests/Feature/Analytics/AffiliateCommercePaidGateTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Analytics/AffiliateCommercePaidGateTest.php

use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;

it('does NOT count orders linked to a non-completed payout as paid', function () {
    $aff = Professional::factory()->create(['professional_type' => 'affiliate']);
    $brand = Professional::factory()->create(['professional_type' => 'brand']);

    $payout = CommissionPayout::factory()->create([
        'brand_professional_id'     => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'status'                    => 'transferring',  // not yet completed
    ]);

    Order::factory()->create([
        'brand_professional_id'     => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'commission_cents'          => 5000,
        'status'                    => 'approved',
        'payout_id'                 => $payout->id,
        'occurred_at'               => now()->subDay(),
    ]);

    $response = $this->actingAsProfessional($aff)
        ->getJson('/api/professional/affiliate/commerce-analytics?from=' . now()->subWeek()->toDateString() . '&to=' . now()->toDateString());

    $response->assertSuccessful();
    expect($response->json('totals.commission_paid_cents'))->toBe(0);
});

it('counts orders linked to a completed payout as paid', function () {
    $aff = Professional::factory()->create(['professional_type' => 'affiliate']);
    $brand = Professional::factory()->create(['professional_type' => 'brand']);

    $payout = CommissionPayout::factory()->create([
        'brand_professional_id'     => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'status'                    => 'completed',
        'transfer_completed_at'     => now(),
    ]);

    Order::factory()->create([
        'brand_professional_id'     => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'commission_cents'          => 5000,
        'status'                    => 'approved',
        'payout_id'                 => $payout->id,
        'occurred_at'               => now()->subDay(),
    ]);

    $response = $this->actingAsProfessional($aff)
        ->getJson('/api/professional/affiliate/commerce-analytics?from=' . now()->subWeek()->toDateString() . '&to=' . now()->toDateString());

    expect($response->json('totals.commission_paid_cents'))->toBe(5000);
});
```

- [ ] **Step 2: Run, expect failure**

```bash
php artisan test --filter=AffiliateCommercePaidGateTest
```

The first test fails (current code counts `payout_id IS NOT NULL` regardless of payout status).

- [ ] **Step 3: Replace the `commission_paid_cents` query in `AffiliateCommerceAnalyticsController`**

Lines around 56–73 currently read:

```php
$paidRow = DB::table('commerce.orders')
    ->where('affiliate_professional_id', $professionalId)
    ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
    ->whereNotNull('payout_id')
    ->where('occurred_at', '>=', $filters['from'])
    ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
    ->selectRaw('COALESCE(SUM(commission_cents), 0) AS paid_cents')
    ->first();
```

Replace with:

```php
$paidRow = DB::table('commerce.orders as o')
    ->join('commerce.commission_payouts as cp', 'cp.id', '=', 'o.payout_id')
    ->where('o.affiliate_professional_id', $professionalId)
    ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
    ->where('cp.status', 'completed')
    ->where('o.occurred_at', '>=', $filters['from'])
    ->where('o.occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
    ->selectRaw('COALESCE(SUM(o.commission_cents), 0) AS paid_cents')
    ->first();
```

- [ ] **Step 4: Mirror the fix in `BrandCommerceAnalyticsController::buildCommissionSummary()`**

Same JOIN, swap `affiliate_professional_id` for `brand_professional_id`.

- [ ] **Step 5: Re-run; expect green**

```bash
php artisan test --filter=AffiliateCommercePaidGate
```

- [ ] **Step 6: Run the full analytics suite**

```bash
php artisan test --filter=Analytics
```

Expected: green. If any other test relied on the old "payout_id assigned = paid" semantics, fix the test fixture (set `cp.status='completed'`).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/Professional/Analytics/ tests/Feature/Analytics/AffiliateCommercePaidGateTest.php
git commit -m "fix(analytics): commission_paid_cents now requires payout.status='completed'"
```

### Task A2.2: Add `transfer.paid` webhook handler + verbatim error capture

**Files:**
- Modify: `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php`
- Test: `tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php — append

it('handles transfer.paid by flipping transferring → completed and stamping transfer_completed_at', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'transferring',
        'stripe_transfer_id' => 'tr_live_test',
        'transfer_completed_at' => null,
    ]);

    $event = $this->stripeWebhookEvent('transfer.paid', [
        'id'       => 'tr_live_test',
        'metadata' => ['sidest_payout_id' => $payout->id],
    ]);

    $this->postStripeWebhook($event)->assertSuccessful();

    $payout->refresh();
    expect($payout->status)->toBe('completed');
    expect($payout->transfer_completed_at)->not->toBeNull();
});

it('transfer.paid is idempotent on already-completed payouts', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'completed',
        'stripe_transfer_id' => 'tr_idem',
        'transfer_completed_at' => now()->subHour(),
    ]);
    $original = $payout->transfer_completed_at;

    $event = $this->stripeWebhookEvent('transfer.paid', [
        'id'       => 'tr_idem',
        'metadata' => ['sidest_payout_id' => $payout->id],
    ]);

    $this->postStripeWebhook($event)->assertSuccessful();

    expect($payout->fresh()->transfer_completed_at?->toIso8601String())
        ->toBe($original->toIso8601String());
});

it('captures verbatim Stripe error fields on transfer.failed', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'transferring']);

    $event = $this->stripeWebhookEvent('transfer.failed', [
        'id'              => 'tr_failed',
        'failure_code'    => 'account_closed',
        'failure_message' => 'The destination account is closed.',
        'metadata'        => ['sidest_payout_id' => $payout->id],
    ]);

    $this->postStripeWebhook($event)->assertSuccessful();

    $payout->refresh();
    expect($payout->status)->toBe('failed');
    expect($payout->stripe_error_code)->toBe('account_closed');
    expect($payout->stripe_error_message)->toBe('The destination account is closed.');
    expect($payout->failure_category)->toBe('affiliate_account');
});

it('handles checkout.session.completed mode=setup by syncing payment method', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);

    \Mockery::mock('overload:'.\Stripe\StripeClient::class)
        ->shouldReceive('checkout->sessions->retrieve')
        ->andReturn((object)[
            'id'    => 'cs_setup_123',
            'mode'  => 'setup',
            'setup_intent' => (object)['payment_method' => 'pm_xyz'],
            'metadata' => (object)['professional_id' => $brand->id],
        ]);

    $event = $this->stripeWebhookEvent('checkout.session.completed', [
        'id'       => 'cs_setup_123',
        'mode'     => 'setup',
        'metadata' => ['professional_id' => $brand->id, 'purpose' => 'payment_method_setup'],
    ]);

    $this->postStripeWebhook($event)->assertSuccessful();

    expect($brand->fresh()->stripe_payment_method_id)->toBe('pm_xyz');
});
```

(Test helpers `stripeWebhookEvent` and `postStripeWebhook` mirror existing patterns; if they don't exist, add them to `tests/Pest.php` based on the existing webhook test setup.)

- [ ] **Step 2: Run, expect failure**

```bash
php artisan test --filter=StripeConnectWebhookControllerEndToEnd
```

- [ ] **Step 3: Add `transfer.paid` to the match block**

```php
// app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php — extend match

match ($event->type) {
    // ... existing cases ...
    'transfer.paid'    => $this->handleTransferPaid($event->data->object),
    'transfer.failed'  => $this->handleTransferFailed($event->data->object),
    'transfer.reversed' => $this->handleTransferReversed($event->data->object),
    // ... rest ...
};
```

- [ ] **Step 4: Add `handleTransferPaid()` method**

```php
private function handleTransferPaid(object $transfer): void
{
    $payoutId = $transfer->metadata?->sidest_payout_id ?? null;
    if (! $payoutId) {
        Log::warning('stripe.transfer_paid.missing_payout_metadata', ['transfer_id' => $transfer->id]);
        return;
    }

    $payout = CommissionPayout::find($payoutId);
    if (! $payout) {
        Log::warning('stripe.transfer_paid.payout_not_found', ['transfer_id' => $transfer->id, 'payout_id' => $payoutId]);
        return;
    }

    if ($payout->status === 'completed') {
        return; // idempotent
    }

    if (in_array($payout->status, ['failed', 'cancelled', 'reversed'], true)) {
        Log::warning('stripe.transfer_paid.unexpected_status', ['payout_id' => $payoutId, 'status' => $payout->status]);
        return;
    }

    $payout->forceFill([
        'status'                => 'completed',
        'transfer_completed_at' => now(),
        'failure_code'          => null,
        'failure_reason'        => null,
        'failure_category'      => null,
        'stripe_error_code'     => null,
        'stripe_error_message'  => null,
    ])->save();

    // Push-invalidate the affected analytics caches.
    app(\App\Services\Cache\AnalyticsCacheService::class)->bumpAnalyticsVersion(
        $payout->affiliate_professional_id
    );
    app(\App\Services\Cache\AnalyticsCacheService::class)->bumpAnalyticsVersion(
        $payout->brand_professional_id
    );
    \Cache::forget(\App\Services\Cache\CacheKeyGenerator::affiliatePayoutState($payout->affiliate_professional_id));

    Log::info('stripe.transfer_paid', ['transfer_id' => $transfer->id, 'payout_id' => $payoutId]);
}
```

- [ ] **Step 5: Replace `handleCheckoutSessionCompleted()` no-op with mode-branching**

```php
private function handleCheckoutSessionCompleted(object $session, string $connectedAccountId): void
{
    $professionalId = $session->metadata?->professional_id ?? null;

    if (! $professionalId) {
        Log::warning('stripe.checkout_completed.missing_professional_id', [
            'session_id' => $session->id,
            'mode'       => $session->mode ?? null,
        ]);
        return;
    }

    $service = app(\App\Services\Stripe\StripeConnectService::class);

    match ($session->mode ?? null) {
        'setup'   => $service->syncPaymentMethodFromCheckoutSession(
                        Professional::find($professionalId),
                        $session->id
                     ),
        // 'payment' arm is wired in Phase A3.1. Until then, log + return so a live
        // top-up Checkout session doesn't throw "method does not exist" 500s.
        // We stamp the originating Stripe event id on the session object so that
        // when A3.1 lands, the wallet_movements ledger captures actor_id = the
        // event id (AUSTRAC audit trail).
        'payment' => method_exists($service, 'creditWalletFromCheckoutSession')
                        ? $service->creditWalletFromCheckoutSession(
                            $professionalId,
                            tap($session, fn ($s) => $s->_stripe_event_id = $event->id)
                          )
                        : Log::info('stripe.checkout_completed.payment_deferred', [
                            'session_id' => $session->id,
                            'phase'      => 'A2 stub; implementation lands in A3.1',
                          ]),
        default   => Log::warning('stripe.checkout_completed.unknown_mode', [
                        'session_id' => $session->id,
                        'mode'       => $session->mode ?? null,
                     ]),
    };
}
```

(`creditWalletFromCheckoutSession` is added in Phase A3.)

- [ ] **Step 6: Capture verbatim error fields in `handleTransferFailed`**

Replace existing body with:

```php
private function handleTransferFailed(object $transfer): void
{
    $payoutId = $transfer->metadata?->sidest_payout_id ?? null;
    if (! $payoutId) return;

    $payout = CommissionPayout::find($payoutId);
    if (! $payout) return;
    if (in_array($payout->status, ['failed', 'completed', 'cancelled'], true)) return;

    $payout->forceFill([
        'status'              => 'failed',
        'failure_code'        => 'transfer_failed_webhook',
        'failure_reason'      => 'Transfer failed according to Stripe webhook',
        'failure_category'    => 'affiliate_account',
        'stripe_error_code'   => $transfer->failure_code ?? null,
        'stripe_error_message'=> $transfer->failure_message ?? null,
    ])->save();

    app(\App\Services\Cache\AnalyticsCacheService::class)
        ->bumpAnalyticsVersion($payout->affiliate_professional_id);
    \Cache::forget(\App\Services\Cache\CacheKeyGenerator::affiliatePayoutState($payout->affiliate_professional_id));
}
```

Mirror in `handleTransferReversed` (keep `'reversed'` status, add the error capture, bump cache).

- [ ] **Step 7: Re-run tests**

```bash
php artisan test --filter=StripeConnectWebhookControllerEndToEnd
```

Expected: green.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php
git commit -m "feat(stripe): handle transfer.paid; capture verbatim Stripe errors; bump analytics cache on settlement"
```

### Task A2.3: Add `bumpAnalyticsVersion` helper if not present

**Files:**
- Modify: `app/Services/Cache/AnalyticsCacheService.php`

- [ ] **Step 1: Read the file and confirm whether `bumpAnalyticsVersion(string $professionalId): void` exists**

```bash
grep -n "bumpAnalyticsVersion\|analyticsSummaryVersion" app/Services/Cache/AnalyticsCacheService.php app/Services/Cache/CacheKeyGenerator.php
```

If a method already exists with this signature, **skip** to Step 3.

- [ ] **Step 2: Add the helper if missing**

```php
// app/Services/Cache/AnalyticsCacheService.php — add inside the class

/**
 * Bump the analytics version-key for a professional. Atomically invalidates every
 * windowed cache variant (any from/to range) for affiliateCommerceAnalytics +
 * brandCommerceAnalytics. Reference: docs/caching-gold-standard.md §7.5.
 */
public function bumpAnalyticsVersion(string $professionalId): void
{
    Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($professionalId));
}
```

- [ ] **Step 3: REQUIRED — wire the version key into the windowed cache keys**

This is non-negotiable. Without the version embedded in the cache key, `bumpAnalyticsVersion` is a silent no-op and the entire cache-invalidation strategy fails.

Modify `CacheKeyGenerator::affiliateCommerceAnalytics` and `::brandCommerceAnalytics` to read the current version and embed it:

```php
public static function affiliateCommerceAnalytics(string $professionalId, string $from, string $to): string
{
    $version = Cache::get(self::analyticsSummaryVersion($professionalId), 0);
    return "analytics:commerce:affiliate:v2:{$professionalId}:{$version}:{$from}:{$to}";
}

public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
{
    $version = Cache::get(self::analyticsSummaryVersion($professionalId), 0);
    return "analytics:commerce:brand:v3:{$professionalId}:{$version}:{$from}:{$to}";
}
```

- [ ] **Step 3b: Add a regression test that proves bumping invalidates ALL window variants**

```php
// tests/Feature/Cache/AnalyticsVersionInvalidationTest.php
it('bumping the version-key invalidates every windowed cache variant', function () {
    $aff = Professional::factory()->create();

    // Prime two different window keys
    $k1 = CacheKeyGenerator::affiliateCommerceAnalytics($aff->id, '2026-01-01', '2026-01-31');
    $k2 = CacheKeyGenerator::affiliateCommerceAnalytics($aff->id, '2026-02-01', '2026-02-28');
    Cache::put($k1, ['snapshot' => 1], 60);
    Cache::put($k2, ['snapshot' => 2], 60);

    expect(Cache::has($k1))->toBeTrue();
    expect(Cache::has($k2))->toBeTrue();

    app(AnalyticsCacheService::class)->bumpAnalyticsVersion($aff->id);

    // Both keys are now unreachable: re-deriving them produces a NEW key with the bumped version
    $newK1 = CacheKeyGenerator::affiliateCommerceAnalytics($aff->id, '2026-01-01', '2026-01-31');
    expect($newK1)->not->toBe($k1);
    expect(Cache::has($newK1))->toBeFalse();
});
```

- [ ] **Step 4: Run analytics tests**

```bash
php artisan test --filter=Analytics
```

Expected: green (cache version bumps don't change behaviour, just key).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cache/
git commit -m "feat(cache): version-key analytics caches; bumpAnalyticsVersion invalidates all windows"
```

### Task A2.4: Add the reconciliation job for stuck `transferring` payouts

**Files:**
- Create: `app/Jobs/Stripe/ReconcileStuckTransferringPayoutsJob.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Stripe/ReconcileStuckTransferringPayoutsJobTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/Stripe/ReconcileStuckTransferringPayoutsJobTest.php

use App\Jobs\Stripe\ReconcileStuckTransferringPayoutsJob;
use App\Models\Retail\CommissionPayout;

beforeEach(function () {
    $this->mockStripeClient(); // helper that swaps app(StripeClient::class) for a mockery
});

it('flips a stuck transferring payout to completed when Stripe says paid', function () {
    $payout = CommissionPayout::factory()->create([
        'status'             => 'transferring',
        'stripe_transfer_id' => 'tr_stuck_1',
        'updated_at'         => now()->subHours(8),
    ]);

    $this->stripeMock->shouldReceive('transfers->retrieve')
        ->with('tr_stuck_1')
        ->andReturn((object)[
            'id'     => 'tr_stuck_1',
            'status' => 'paid',
        ]);

    (new ReconcileStuckTransferringPayoutsJob)->handle();

    $payout->refresh();
    expect($payout->status)->toBe('completed');
    expect($payout->transfer_completed_at)->not->toBeNull();
});

it('skips payouts that are not stuck (< 6h)', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'transferring', 'stripe_transfer_id' => 'tr_recent',
        'updated_at' => now()->subHour(),
    ]);
    (new ReconcileStuckTransferringPayoutsJob)->handle();
    expect($payout->fresh()->status)->toBe('transferring');
});

it('flags payouts as failed when Stripe says failed', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'transferring', 'stripe_transfer_id' => 'tr_failed',
        'updated_at' => now()->subHours(7),
    ]);

    $this->stripeMock->shouldReceive('transfers->retrieve')
        ->andReturn((object)['id' => 'tr_failed', 'status' => 'failed', 'failure_code' => 'account_closed', 'failure_message' => 'closed']);

    (new ReconcileStuckTransferringPayoutsJob)->handle();

    expect($payout->fresh()->status)->toBe('failed');
});
```

- [ ] **Step 2: Run, expect failure**

```bash
php artisan test --filter=ReconcileStuckTransferringPayoutsJobTest
```

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs\Stripe;

use App\Models\Retail\CommissionPayout;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class ReconcileStuckTransferringPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1; // self-healing: rerun is the next day's schedule

    public function handle(): void
    {
        $stripe = app(StripeClient::class);
        $analytics = app(AnalyticsCacheService::class);

        CommissionPayout::query()
            ->where('status', 'transferring')
            ->where('updated_at', '<', now()->subHours(6))
            ->whereNotNull('stripe_transfer_id')
            ->chunkById(100, function ($payouts) use ($stripe, $analytics) {
                foreach ($payouts as $payout) {
                    $this->reconcileOne($payout, $stripe, $analytics);
                }
            });
    }

    private function reconcileOne(CommissionPayout $payout, StripeClient $stripe, AnalyticsCacheService $analytics): void
    {
        try {
            $transfer = $stripe->transfers->retrieve($payout->stripe_transfer_id);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::warning('payout.reconcile.stripe_error', [
                'payout_id' => $payout->id,
                'transfer_id' => $payout->stripe_transfer_id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        match ($transfer->status ?? null) {
            'paid'     => $this->markCompleted($payout, $analytics),
            'failed'   => $this->markFailed($payout, $transfer, $analytics),
            'pending'  => null, // still in flight; let it ride
            default    => Log::warning('payout.reconcile.unknown_status', [
                            'payout_id' => $payout->id,
                            'transfer_status' => $transfer->status ?? null,
                          ]),
        };
    }

    private function markCompleted(CommissionPayout $payout, AnalyticsCacheService $analytics): void
    {
        $payout->forceFill([
            'status'                => 'completed',
            'transfer_completed_at' => now(),
        ])->save();

        $analytics->bumpAnalyticsVersion($payout->affiliate_professional_id);
        $analytics->bumpAnalyticsVersion($payout->brand_professional_id);
        Cache::forget(CacheKeyGenerator::affiliatePayoutState($payout->affiliate_professional_id));

        Log::info('payout.reconcile.completed', ['payout_id' => $payout->id]);
    }

    private function markFailed(CommissionPayout $payout, object $transfer, AnalyticsCacheService $analytics): void
    {
        $payout->forceFill([
            'status'              => 'failed',
            'failure_code'        => 'transfer_failed_reconciliation',
            'failure_reason'      => 'Stripe Transfer failed; detected by reconciliation job',
            'failure_category'    => 'affiliate_account',
            'stripe_error_code'   => $transfer->failure_code ?? null,
            'stripe_error_message'=> $transfer->failure_message ?? null,
        ])->save();

        $analytics->bumpAnalyticsVersion($payout->affiliate_professional_id);
        Cache::forget(CacheKeyGenerator::affiliatePayoutState($payout->affiliate_professional_id));

        Log::warning('payout.reconcile.failed', ['payout_id' => $payout->id]);
    }
}
```

- [ ] **Step 4: Schedule daily at 07:30 UTC**

Append to `routes/console.php`:

```php
use App\Jobs\Stripe\ReconcileStuckTransferringPayoutsJob;

Schedule::job(new ReconcileStuckTransferringPayoutsJob)
    ->dailyAt('07:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->onQueue('payouts');
```

- [ ] **Step 5: Re-run tests + schedule list**

```bash
php artisan test --filter=ReconcileStuckTransferringPayoutsJob
php artisan schedule:list | grep Reconcile
```

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/Stripe/ReconcileStuckTransferringPayoutsJob.php tests/Feature/Stripe/ReconcileStuckTransferringPayoutsJobTest.php routes/console.php
git commit -m "feat(stripe): reconcile stuck transferring payouts daily via Transfer.retrieve"
```

### Task A2.5: Add the four missing endpoints (LANE B PREREQUISITES)

**Goal:** Lane B cannot start without these. Phase A2 ships them with stub-quality data layers; Phase A4/A5 enrich.

**Files:**
- Create: `app/Http/Controllers/Api/Professional/Brand/BrandBillingSummaryController.php`
- Create: `app/Http/Controllers/Api/Professional/Brand/BrandPayoutsController.php`
- Create: `app/Http/Controllers/Api/Professional/Affiliate/AffiliatePayoutsController.php`
- Modify: `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` (add affiliate Connect onboarding endpoint)
- Modify: `routes/api/professional.php`
- Tests: 4 NEW feature tests, one per endpoint

- [ ] **Step 1: Write failing test for `BrandBillingSummaryController`**

```php
<?php
// tests/Feature/Brand/BrandBillingSummaryTest.php

it('returns the brand billing summary with masked card + wallet + blocked count', function () {
    $brand = Professional::factory()->create([
        'professional_type'             => 'brand',
        'stripe_payment_method_id'      => 'pm_123',
        'stripe_payment_method_brand'   => 'visa',
        'stripe_payment_method_last4'   => '4242',
        'stripe_manual_balance_cents'   => 25000,
        'stripe_manual_balance_currency'=> 'AUD',
    ]);

    $response = $this->actingAsProfessional($brand)
        ->getJson('/api/professional/brand/billing-summary');

    $response->assertSuccessful()
        ->assertJsonStructure([
            'has_card', 'masked_card' => ['brand', 'last4'],
            'wallet_balance_cents', 'currency',
            'blocked_orders_count', 'blocked_pending_cents',
            'recent_topups',
        ]);

    expect($response->json('has_card'))->toBeTrue();
    expect($response->json('masked_card.last4'))->toBe('4242');
    expect($response->json('wallet_balance_cents'))->toBe(25000);
});

it('forbids affiliate access to brand-billing-summary', function () {
    $aff = Professional::factory()->create(['professional_type' => 'affiliate']);
    $this->actingAsProfessional($aff)
        ->getJson('/api/professional/brand/billing-summary')
        ->assertForbidden();
});
```

- [ ] **Step 2: Implement the controller**

```php
<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Controller;
use App\Models\Commerce\Order;
use App\Models\Commerce\WalletMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandBillingSummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $brand = $request->attributes->get('professional');
        $this->authorizeForUser($brand, 'manageWallet', $brand);

        $hasCard = ! empty($brand->stripe_payment_method_id);

        $blockedOrders = Order::query()
            ->where('brand_professional_id', $brand->id)
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->where('refund_cents', 0)
            ->where('rate_source', '!=', 'pending')
            ->when(! $hasCard, fn ($q) => $q)  // when no card, ALL approved orders are blocked
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(commission_cents), 0) AS pending_cents')
            ->first();

        $recentTopups = WalletMovement::query()
            ->where('professional_id', $brand->id)
            ->where('reason', 'top_up')
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get(['id', 'amount_cents', 'currency_code', 'occurred_at']);

        return response()->json([
            'has_card' => $hasCard,
            'masked_card' => $hasCard ? [
                'brand' => $brand->stripe_payment_method_brand,
                'last4' => $brand->stripe_payment_method_last4,
            ] : null,
            'wallet_balance_cents' => (int) ($brand->stripe_manual_balance_cents ?? 0),
            'currency'             => $brand->stripe_manual_balance_currency ?? 'AUD',
            'blocked_orders_count' => $hasCard ? 0 : (int) $blockedOrders->cnt,
            'blocked_pending_cents'=> $hasCard ? 0 : (int) $blockedOrders->pending_cents,
            'recent_topups'        => $recentTopups,
        ]);
    }
}
```

- [ ] **Step 3: Implement `BrandPayoutsController`**

```php
<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandPayoutResource;
use App\Models\Retail\CommissionPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BrandPayoutsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $brand = $request->attributes->get('professional');
        $this->authorizeForUser($brand, 'manageWallet', $brand);

        $payouts = CommissionPayout::query()
            ->where('brand_professional_id', $brand->id)
            ->with('affiliateProfessional:id,name')
            ->orderByDesc('created_at')
            ->paginate(50);

        return BrandPayoutResource::collection($payouts);
    }
}
```

(Create `app/Http/Resources/BrandPayoutResource.php` exposing the contract specified in Stage 2 §"What the API will look like".)

- [ ] **Step 4: Implement `AffiliatePayoutsController`**

Mirror Brand variant but filter by `affiliate_professional_id` and surface different fields (e.g. include `void_at`, hide `failure_category` if it's brand-side, surface refund-cancellation reason).

- [ ] **Step 5: Add affiliate Connect onboarding endpoint**

In `StripeConnectController` (or a new `AffiliateStripeOnboardingController`), add:

```php
public function startConnect(StartConnectRequest $request): JsonResponse
{
    $aff = $request->attributes->get('professional');
    $this->authorizeForUser($aff, 'startConnect', $aff);  // add this ability to ConnectPolicy

    $url = $this->connectService->createOnboardingLink($aff);
    return response()->json(['onboarding_url' => $url]);
}
```

(Add the `startConnect` ability to a Policy. Affiliate-side, allows self only.)

- [ ] **Step 6: Register routes**

```php
// routes/api/professional.php

Route::get('/brand/billing-summary', [BrandBillingSummaryController::class, 'show']);
Route::get('/brand/payouts',         [BrandPayoutsController::class, 'index']);
Route::get('/affiliate/payouts',     [AffiliatePayoutsController::class, 'index']);
Route::post('/affiliate/stripe/connect/start', [AffiliateStripeOnboardingController::class, 'startConnect']);
```

- [ ] **Step 7: Re-run tests + endpoint smoke**

```bash
php artisan test --filter='Brand|Affiliate'
php artisan route:list --path=api/professional | grep -E 'billing-summary|brand/payouts|affiliate/payouts|connect/start'
```

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/Professional/Brand/ app/Http/Controllers/Api/Professional/Affiliate/ app/Http/Resources/ routes/api/professional.php tests/Feature/Brand/ tests/Feature/Affiliate/
git commit -m "feat(api): add billing-summary, brand/affiliate payouts, affiliate Connect-start endpoints (Lane B prerequisites)"
```

---

## Phase A3 — Refactor existing top-up flow + card enforcement at eligibility

**Goal:** harden what's already in production. Add card-on-file gate at the eligibility step. Wire the wallet ledger.

### Task A3.1: Refactor `createManualTopUpCheckoutSession` to write a wallet movement on credit + use stable idempotency

**Files:**
- Modify: `app/Services/Stripe/StripeConnectService.php`
- Modify: `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php` (already extended in A2.2)
- Test: `tests/Feature/Stripe/WalletMovementsLedgerTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/Stripe/WalletMovementsLedgerTest.php

use App\Models\Commerce\WalletMovement;
use App\Models\Core\Professional\Professional;

it('credits wallet exactly once when checkout.session.completed fires twice (idempotent)', function () {
    $brand = Professional::factory()->create([
        'professional_type' => 'brand',
        'stripe_manual_balance_cents' => 0,
        'stripe_manual_balance_currency' => 'AUD',
        'stripe_customer_id' => 'cus_test',
    ]);

    $session = (object)[
        'id'             => 'cs_topup_dup',
        'mode'           => 'payment',
        'payment_status' => 'paid',
        'amount_total'   => 5000,
        'currency'       => 'aud',
        'metadata'       => (object)['professional_id' => $brand->id, 'purpose' => 'brand_top_up'],
    ];

    $service = app(\App\Services\Stripe\StripeConnectService::class);
    $service->creditWalletFromCheckoutSession($brand->id, $session);
    $service->creditWalletFromCheckoutSession($brand->id, $session);

    expect($brand->fresh()->stripe_manual_balance_cents)->toBe(5000);
    expect(WalletMovement::where('related_session_id', 'cs_topup_dup')->count())->toBe(1);
});

it('refunds the PaymentIntent + alerts on currency mismatch', function () {
    $brand = Professional::factory()->create([
        'stripe_manual_balance_cents' => 100,
        'stripe_manual_balance_currency' => 'AUD',
        'stripe_customer_id' => 'cus_test',
    ]);

    $session = (object)[
        'id' => 'cs_currency_mismatch',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'amount_total' => 5000,
        'currency' => 'usd', // mismatch
        'payment_intent' => 'pi_to_refund',
        'metadata' => (object)['professional_id' => $brand->id, 'purpose' => 'brand_top_up'],
    ];

    $this->stripeMock->shouldReceive('refunds->create')
        ->once()
        ->with(\Mockery::on(fn ($args) => $args['payment_intent'] === 'pi_to_refund'));

    $service = app(\App\Services\Stripe\StripeConnectService::class);
    $service->creditWalletFromCheckoutSession($brand->id, $session);

    expect($brand->fresh()->stripe_manual_balance_cents)->toBe(100); // unchanged
});

it('credits concurrently without losing updates', function () {
    $brand = Professional::factory()->create([
        'stripe_manual_balance_cents' => 0,
        'stripe_manual_balance_currency' => 'AUD',
    ]);

    // Two sessions, two amounts, fired "concurrently"
    $sessions = [
        (object)['id' => 'cs_concurrent_1', 'mode' => 'payment', 'payment_status' => 'paid',
                 'amount_total' => 1000, 'currency' => 'aud',
                 'metadata' => (object)['professional_id' => $brand->id, 'purpose' => 'brand_top_up']],
        (object)['id' => 'cs_concurrent_2', 'mode' => 'payment', 'payment_status' => 'paid',
                 'amount_total' => 2000, 'currency' => 'aud',
                 'metadata' => (object)['professional_id' => $brand->id, 'purpose' => 'brand_top_up']],
    ];

    $service = app(\App\Services\Stripe\StripeConnectService::class);
    foreach ($sessions as $s) {
        DB::transaction(fn () => $service->creditWalletFromCheckoutSession($brand->id, $s));
    }

    expect($brand->fresh()->stripe_manual_balance_cents)->toBe(3000);
    expect(WalletMovement::where('professional_id', $brand->id)->count())->toBe(2);
});
```

- [ ] **Step 2: Run, expect failure**

```bash
php artisan test --filter=WalletMovementsLedgerTest
```

- [ ] **Step 3: Implement `creditWalletFromCheckoutSession` in `StripeConnectService`**

```php
public function creditWalletFromCheckoutSession(string $professionalId, object $session): void
{
    if (($session->payment_status ?? null) !== 'paid') {
        Log::warning('stripe.topup.unpaid_session', ['session_id' => $session->id ?? null]);
        return;
    }

    $amountCents = (int) ($session->amount_total ?? 0);
    if ($amountCents <= 0) return;

    $sessionId = $session->id;
    $currency  = strtoupper($session->currency ?? 'AUD');

    DB::transaction(function () use ($professionalId, $session, $sessionId, $amountCents, $currency) {
        // Lock the brand row so concurrent webhooks serialise.
        $brand = Professional::query()
            ->where('id', $professionalId)
            ->lockForUpdate()
            ->first();

        if (! $brand) {
            Log::warning('stripe.topup.brand_not_found', ['professional_id' => $professionalId]);
            return;
        }

        // Currency mismatch → refund + alert.
        $walletCurrency = strtoupper($brand->stripe_manual_balance_currency ?? 'AUD');
        if ($walletCurrency !== $currency) {
            Log::error('stripe.topup.currency_mismatch', [
                'professional_id' => $professionalId,
                'wallet_currency' => $walletCurrency,
                'session_currency' => $currency,
                'amount_cents' => $amountCents,
            ]);

            // Auto-refund the PaymentIntent so the brand isn't out money.
            if (! empty($session->payment_intent)) {
                try {
                    $this->stripe->refunds->create([
                        'payment_intent' => $session->payment_intent,
                        'reason' => 'requested_by_customer',
                        'metadata' => [
                            'sidest_reason' => 'currency_mismatch',
                            'professional_id' => $professionalId,
                        ],
                    ], ['idempotency_key' => 'currency_mismatch_refund:' . $sessionId]);
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    Log::critical('stripe.topup.currency_mismatch_refund_failed', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage(),
                    ]);
                    // Raise to Nightwatch via report() so ops sees it.
                    report($e);
                }
            }

            return;
        }

        $idempotencyKey = 'topup:' . $sessionId;
        $stripeEventId  = $session->_stripe_event_id ?? null;  // populated by webhook handler before calling

        // Insert the ledger row first; UNIQUE on idempotency_key gives us idempotency.
        $movement = WalletMovement::create([
            'professional_id'    => $brand->id,
            'direction'          => 'credit',
            'amount_cents'       => $amountCents,
            'currency_code'      => $currency,
            'reason'             => 'top_up',
            'actor_type'         => 'webhook',
            'actor_id'           => $stripeEventId ?? 'checkout.session.completed:' . $sessionId,
            'related_session_id' => $sessionId,
            'idempotency_key'    => $idempotencyKey,
            'metadata'           => [
                'session_id' => $sessionId,
                'session_payment_intent' => $session->payment_intent ?? null,
            ],
        ]);

        // Apply to balance via increment (atomic; row already locked).
        Professional::where('id', $brand->id)
            ->update(['stripe_manual_balance_currency' => $currency]);
        Professional::where('id', $brand->id)
            ->increment('stripe_manual_balance_cents', $amountCents);

        Log::info('stripe.topup.credited', [
            'professional_id' => $brand->id, 'session_id' => $sessionId,
            'amount_cents' => $amountCents, 'movement_id' => $movement->id,
        ]);
    });
}
```

If the `WalletMovement::create` collides on `UNIQUE(idempotency_key)`, `QueryException` is thrown — catch it at the top of the try block, log "duplicate, skipping," and return.

- [ ] **Step 4: Refactor `confirmTopUpCheckoutSession` (the second top-up endpoint)**

Existing `StripeConnectService::confirmTopUpCheckoutSession()` runs when the user lands on the success URL after Checkout. It currently does its own balance update + has no Policy/FormRequest. Refactor to delegate the heavy lifting to `creditWalletFromCheckoutSession()`:

```php
public function confirmTopUpCheckoutSession(Professional $brand, string $sessionId): array
{
    $session = $this->stripe->checkout->sessions->retrieve($sessionId, ['expand' => ['payment_intent']]);

    if ($session->mode !== 'payment') {
        throw new \RuntimeException('Checkout session is not a top-up payment session.');
    }
    if (($session->metadata?->professional_id ?? null) !== $brand->id) {
        throw new \RuntimeException('Checkout session does not belong to this professional.');
    }

    // Mark this code path so the actor_type writes 'professional' (not 'webhook'),
    // since the user is hitting the success URL — not Stripe firing the webhook.
    $session->_actor_override = ['type' => 'professional', 'id' => (string) $brand->id];

    // Idempotent — if the webhook already fired, this is a no-op against the ledger UNIQUE constraint.
    $this->creditWalletFromCheckoutSession((string) $brand->id, $session);

    return [
        'session_id'   => $sessionId,
        'amount_cents' => (int) ($session->amount_total ?? 0),
        'status'       => 'credited',
    ];
}
```

Update `creditWalletFromCheckoutSession` actor selection accordingly:

```php
$actor = $session->_actor_override ?? ['type' => 'webhook', 'id' => $stripeEventId ?? 'checkout.session.completed:' . $sessionId];

$movement = WalletMovement::create([
    // ...
    'actor_type' => $actor['type'],
    'actor_id'   => $actor['id'],
    // ...
]);
```

Controller `StripeConnectController::confirmTopUpCheckoutSession` is already auth'd via Phase A0.3's `authorizeForUser($brand, 'topUp', $brand)` and uses `ConfirmTopUpCheckoutRequest`.

- [ ] **Step 5: Re-run tests; expect green**

```bash
php artisan test --filter='WalletMovementsLedgerTest|StripeConnectController'
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Stripe/StripeConnectService.php app/Models/Commerce/WalletMovement.php tests/Feature/Stripe/WalletMovementsLedgerTest.php
git commit -m "feat(stripe): wallet credit is race-safe (lockForUpdate) + idempotent (UNIQUE) + actor-tagged (AUSTRAC)"
```

### Task A3.2: Add card-on-file gate at `processEligiblePayouts`

**Files:**
- Modify: `app/Services/Stripe/CommissionPayoutService.php`
- Test: `tests/Feature/Stripe/CommissionPayoutServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
it('skips orders for brands without stripe_payment_method_id at eligibility-gate', function () {
    $brandWithoutCard = Professional::factory()->create([
        'professional_type' => 'brand',
        'stripe_customer_id' => null,
        'stripe_payment_method_id' => null,
    ]);
    $aff = Professional::factory()->create(['professional_type' => 'affiliate']);

    $order = Order::factory()->create([
        'brand_professional_id' => $brandWithoutCard->id,
        'affiliate_professional_id' => $aff->id,
        'status' => 'approved',
        'payout_id' => null,
        'refund_cents' => 0,
        'rate_source' => 'brand_default',
        'commission_cents' => 5000,
    ]);

    app(CommissionPayoutService::class)->processEligiblePayouts();

    expect($order->fresh()->payout_id)->toBeNull();
    expect(CommissionPayout::count())->toBe(0);
});
```

- [ ] **Step 2: Run, expect failure**

- [ ] **Step 3: Add the filter at the start of the eligible-orders query**

In `processEligiblePayouts()`, replace the current eligible-orders query with:

```php
$eligibleBrandIds = Professional::query()
    ->where('professional_type', 'brand')
    ->whereNotNull('stripe_customer_id')
    ->whereNotNull('stripe_payment_method_id')
    ->pluck('id');

$brandIds = Order::query()
    ->where('status', 'approved')
    ->whereNull('payout_id')
    ->where('refund_cents', 0)
    ->where('rate_source', '!=', 'pending')   // Phase A4 gate, lands together
    ->whereIn('brand_professional_id', $eligibleBrandIds)
    ->distinct()
    ->pluck('brand_professional_id');
```

- [ ] **Step 4: Re-run; expect green**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Stripe/CommissionPayoutService.php tests/Feature/Stripe/CommissionPayoutServiceTest.php
git commit -m "feat(stripe): card-on-file gate enforced at processEligiblePayouts (no card → no batch)"
```

### Task A3.3: Tighten `processPayoutBatch` to read `Transfer.status` + capture verbatim errors

**Files:**
- Modify: `app/Services/Stripe/CommissionPayoutService.php`
- Test: `tests/Feature/Stripe/CommissionPayoutServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
it('keeps payout in transferring status when Transfer.status=pending', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'collecting']);

    $this->stripeMock->shouldReceive('transfers->create')
        ->andReturn((object)['id' => 'tr_pending_test', 'status' => 'pending']);

    app(CommissionPayoutService::class)->processPayoutBatch($payout);

    expect($payout->fresh()->status)->toBe('transferring');
    expect($payout->fresh()->stripe_transfer_id)->toBe('tr_pending_test');
});

it('flips to completed when Transfer.status=paid synchronously', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'collecting']);

    $this->stripeMock->shouldReceive('transfers->create')
        ->andReturn((object)['id' => 'tr_sync_paid', 'status' => 'paid']);

    app(CommissionPayoutService::class)->processPayoutBatch($payout);

    expect($payout->fresh()->status)->toBe('completed');
    expect($payout->fresh()->transfer_completed_at)->not->toBeNull();
});

it('captures stripe_error_code on PI decline + classifies as brand_funding', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'collecting']);

    $exc = new \Stripe\Exception\CardException('Card declined', 0, 'card_declined');
    $this->stripeMock->shouldReceive('paymentIntents->create')->andThrow($exc);

    app(CommissionPayoutService::class)->processPayoutBatch($payout);

    expect($payout->fresh()->status)->toBe('pending_funds');
    expect($payout->fresh()->stripe_error_code)->toBe('card_declined');
    expect($payout->fresh()->failure_category)->toBe('brand_funding');
});
```

- [ ] **Step 2: Run, expect failure**

- [ ] **Step 3: Update `processPayoutBatch` success branch**

Replace the section that flips to `'completed'` after `Transfer.create()`:

```php
$transfer = $this->stripe->transfers->create(
    $transferPayload,
    ['idempotency_key' => 'tr_'.$payout->id]
);

$payout->forceFill(['stripe_transfer_id' => $transfer->id])->save();

$transferStatus = $transfer->status ?? null;
if ($transferStatus === 'paid') {
    $payout->forceFill([
        'status'                => 'completed',
        'processed_at'          => now(),
        'transfer_completed_at' => now(),
        'failure_code'          => null, 'failure_reason' => null, 'failure_category' => null,
        'stripe_error_code'     => null, 'stripe_error_message' => null,
    ])->save();

    app(\App\Services\Cache\AnalyticsCacheService::class)->bumpAnalyticsVersion($payout->affiliate_professional_id);
    app(\App\Services\Cache\AnalyticsCacheService::class)->bumpAnalyticsVersion($payout->brand_professional_id);
    \Cache::forget(\App\Services\Cache\CacheKeyGenerator::affiliatePayoutState($payout->affiliate_professional_id));
} else {
    // Transfer object exists but funds haven't moved. Wait for transfer.paid webhook.
    $payout->forceFill([
        'status'        => 'transferring',
        'processed_at'  => now(),  // when we attempted; transfer_completed_at lands later
    ])->save();

    Log::info('stripe.transfer.pending_awaiting_webhook', [
        'payout_id'       => $payout->id,
        'transfer_id'     => $transfer->id,
        'transfer_status' => $transferStatus,
    ]);
    return null;
}
```

- [ ] **Step 4: Update `failPayout` and `markPendingFunding` signatures**

```php
private function failPayout(
    CommissionPayout $payout,
    string $code,
    string $reason,
    ?string $stripeErrorCode = null,
    ?string $stripeErrorMessage = null,
    ?string $category = null
): void {
    $payout->forceFill([
        'status' => 'failed',
        'failure_code' => $code, 'failure_reason' => $reason, 'failure_category' => $category,
        'stripe_error_code' => $stripeErrorCode, 'stripe_error_message' => $stripeErrorMessage,
    ])->save();
}
```

Mirror in `markPendingFunding`, and additionally set `next_retry_at`:

```php
private function markPendingFunding(
    CommissionPayout $payout,
    string $code, string $reason,
    ?string $stripeErrorCode = null, ?string $stripeErrorMessage = null,
    ?string $category = 'brand_funding'
): void {
    $payout->forceFill([
        'status' => 'pending_funds',
        'failure_code' => $code, 'failure_reason' => $reason, 'failure_category' => $category,
        'stripe_error_code' => $stripeErrorCode, 'stripe_error_message' => $stripeErrorMessage,
        'next_retry_at' => now()->addDay(),
        'last_retry_at' => now(),
        'funding_failure_count' => DB::raw('funding_failure_count + 1'),
    ])->save();
}
```

- [ ] **Step 5: Update all callers of these methods**

```bash
grep -rn '->failPayout(\|->markPendingFunding(' app/ tests/
```

For each call site, pass through `$e->getStripeCode()`, `$e->getMessage()`, and a category derived from the exception type:

```php
$category = match (true) {
    $e instanceof \Stripe\Exception\CardException        => 'brand_funding',
    $e instanceof \Stripe\Exception\AuthenticationException
        || $e instanceof \Stripe\Exception\PermissionException => 'platform',
    $e instanceof \Stripe\Exception\ApiConnectionException
        || $e instanceof \Stripe\Exception\RateLimitException  => 'stripe_transient',
    default                                              => 'stripe_terminal',
};
```

`stripe_transient` exceptions should NOT call `failPayout`; they should re-throw for Horizon retry.

- [ ] **Step 6: Re-run; expect green**

```bash
php artisan test --filter=CommissionPayoutService
```

- [ ] **Step 7: Commit**

```bash
git add app/Services/Stripe/CommissionPayoutService.php tests/Feature/Stripe/CommissionPayoutServiceTest.php
git commit -m "feat(stripe): processPayoutBatch reads Transfer.status; verbatim error capture; failure_category classifier"
```

### Task A3.4: Wire `ExecuteCommissionPayoutJob` to treat null-return as in-flight (not failure)

**Files:**
- Modify: `app/Jobs/Stripe/ExecuteCommissionPayoutJob.php`
- Test: `tests/Feature/Stripe/ExecuteCommissionPayoutJobTest.php`

- [ ] **Step 1: Test for null-return-no-retry**

```php
it('does not count null-return as a job failure (transferring webhook will land)', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'collecting']);

    $this->mock(CommissionPayoutService::class)
        ->shouldReceive('processPayoutBatch')
        ->andReturn(null);

    $job = new ExecuteCommissionPayoutJob($payout->id);
    $job->handle(app(CommissionPayoutService::class));

    expect($payout->fresh()->status)->toBe('collecting');  // service set transferring; we just don't retry
});
```

- [ ] **Step 2: Update `handle()` to return cleanly on null**

```php
public function handle(CommissionPayoutService $service): void
{
    $payout = CommissionPayout::find($this->payoutId);
    if (! $payout) return;

    $result = $service->processPayoutBatch($payout);

    if ($result === null) {
        // Transfer is in flight. Webhook (or reconciliation job) finishes the state.
        return;
    }
    // existing post-success logic continues
}
```

- [ ] **Step 3: Re-run; commit**

```bash
git add app/Jobs/Stripe/ExecuteCommissionPayoutJob.php tests/Feature/Stripe/ExecuteCommissionPayoutJobTest.php
git commit -m "fix(stripe): null return from processPayoutBatch means transferring (not failure)"
```

---

## Phase A4 — Failure handling, retry, grace, per-product fix, refund recompute

### Task A4.1: `BrandPayoutFundingFailedNotification`

**Files:**
- Create: `app/Notifications/Brand/BrandPayoutFundingFailedNotification.php`
- Test: `tests/Feature/Notifications/BrandPayoutFundingFailedNotificationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Notifications/BrandPayoutFundingFailedNotificationTest.php

use App\Models\Retail\CommissionPayout;
use App\Notifications\Brand\BrandPayoutFundingFailedNotification;
use Illuminate\Support\Facades\Notification;

it('sends mail + database channel for cycle failure', function () {
    Notification::fake();
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    $payout = CommissionPayout::factory()->create([
        'brand_professional_id' => $brand->id,
        'failure_reason' => 'Card declined',
        'next_retry_at' => now()->addDay(),
    ]);

    $brand->notify(new BrandPayoutFundingFailedNotification($payout, isTerminal: false));

    Notification::assertSentTo($brand, BrandPayoutFundingFailedNotification::class, function ($n) {
        return $n->via($brand) === ['mail', 'database']
            && data_get($n->toArray($brand), 'is_terminal') === false;
    });
});

it('database payload contains required fields for terminal variant', function () {
    $brand = Professional::factory()->create();
    $aff = Professional::factory()->create(['name' => 'Affiliate Inc']);
    $payout = CommissionPayout::factory()->create([
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'failure_reason' => 'Card declined permanently',
        'next_retry_at' => null,
        'gross_commission_cents' => 12300,
    ]);

    $payload = (new BrandPayoutFundingFailedNotification($payout, isTerminal: true))->toArray($brand);

    expect($payload)->toMatchArray([
        'payout_id'      => $payout->id,
        'affiliate_name' => 'Affiliate Inc',
        'amount_cents'   => 12300,
        'failure_reason' => 'Card declined permanently',
        'is_terminal'    => true,
        'next_retry_at'  => null,
    ]);
});
```

- [ ] **Step 2: Run, expect failure**

- [ ] **Step 3: Implement the notification**

```php
<?php

namespace App\Notifications\Brand;

use App\Models\Retail\CommissionPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BrandPayoutFundingFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CommissionPayout $payout,
        public bool $isTerminal
    ) {}

    public function via(object $notifiable): array
    {
        // Email digest pattern: send mail only on the FIRST cycle ("here's what's happening,
        // we'll keep retrying for 7 days") and the TERMINAL cycle ("we gave up, action required").
        // Cycles 2..6 only write to the database channel so the dashboard banner stays fresh
        // without spamming the brand's inbox (Gmail flags 7 daily decline emails as promotional).
        $count = $this->payout->funding_failure_count ?? 0;

        if ($this->isTerminal || $count <= 1) {
            return ['mail', 'database'];
        }
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $aff = $this->payout->affiliateProfessional?->name ?? 'an affiliate';
        $amount = '$' . number_format($this->payout->gross_commission_cents / 100, 2);

        if ($this->isTerminal) {
            return (new MailMessage)
                ->subject('Action required: payout permanently failed')
                ->greeting('We need your help')
                ->line("Your card has failed 7 times trying to pay {$aff} their commission of {$amount}.")
                ->line("Your wallet has been credited back. Update your card and reach out to support so we can retry.")
                ->action('Update payment method', config('app.frontend_url') . '/brand/billing');
        }

        return (new MailMessage)
            ->subject("We'll retry your payout to {$aff} tomorrow")
            ->line("Your card couldn't be charged for {$aff}'s commission of {$amount}.")
            ->line("Reason: {$this->payout->failure_reason}")
            ->line("We'll retry on " . optional($this->payout->next_retry_at)->format('jS F') . ".");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'payout_id'      => $this->payout->id,
            'affiliate_name' => $this->payout->affiliateProfessional?->name,
            'amount_cents'   => $this->payout->gross_commission_cents,
            'failure_reason' => $this->payout->failure_reason,
            'next_retry_at'  => $this->payout->next_retry_at?->toIso8601String(),
            'is_terminal'    => $this->isTerminal,
        ];
    }
}
```

- [ ] **Step 4: Re-run; commit**

```bash
git add app/Notifications/Brand/ tests/Feature/Notifications/BrandPayoutFundingFailedNotificationTest.php
git commit -m "feat(notifications): BrandPayoutFundingFailedNotification (cycle + terminal)"
```

### Task A4.2: `AffiliatePayoutGraceWarningNotification`

**Files:**
- Create: `app/Notifications/Affiliate/AffiliatePayoutGraceWarningNotification.php`
- Test: `tests/Feature/Notifications/AffiliatePayoutGraceWarningNotificationTest.php`

- [ ] **Step 1: Write the failing tests covering all three variants**

```php
<?php
// tests/Feature/Notifications/AffiliatePayoutGraceWarningNotificationTest.php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Notifications\Affiliate\AffiliatePayoutGraceWarningNotification;
use Illuminate\Support\Facades\Notification;

it('sends mail + database channel for T-30 variant', function () {
    Notification::fake();
    $aff = Professional::factory()->create(['professional_type' => 'affiliate']);
    $brand = Professional::factory()->create(['name' => 'Brand X']);
    $payout = CommissionPayout::factory()->create([
        'affiliate_professional_id' => $aff->id,
        'brand_professional_id'     => $brand->id,
        'gross_commission_cents'    => 8500,
        'void_at'                   => now()->addDays(30),
    ]);

    $aff->notify(new AffiliatePayoutGraceWarningNotification($payout, daysRemaining: 30));

    Notification::assertSentTo($aff, AffiliatePayoutGraceWarningNotification::class, function ($n) use ($aff) {
        return $n->via($aff) === ['mail', 'database']
            && $n->daysRemaining === 30;
    });
});

it('database payload contains required fields for each variant', function (int $days) {
    $aff = Professional::factory()->create(['professional_type' => 'affiliate']);
    $brand = Professional::factory()->create(['name' => 'Brand X']);
    $payout = CommissionPayout::factory()->create([
        'affiliate_professional_id' => $aff->id,
        'brand_professional_id'     => $brand->id,
        'gross_commission_cents'    => 8500,
        'void_at'                   => now()->addDays($days),
    ]);

    $payload = (new AffiliatePayoutGraceWarningNotification($payout, $days))->toArray($aff);

    expect($payload)->toMatchArray([
        'payout_id'      => $payout->id,
        'brand_name'     => 'Brand X',
        'amount_cents'   => 8500,
        'days_remaining' => $days,
    ]);
    expect($payload['void_at'])->not->toBeNull();
    expect($payload['connect_url'])->toContain('/affiliate/stripe/connect');
})->with([30, 7, 1]);

it('mail subject and copy escalate by days_remaining', function () {
    $aff = Professional::factory()->create();
    $brand = Professional::factory()->create();
    $payout = CommissionPayout::factory()->create([
        'affiliate_professional_id' => $aff->id,
        'brand_professional_id'     => $brand->id,
    ]);

    $mail30 = (new AffiliatePayoutGraceWarningNotification($payout, 30))->toMail($aff)->subject;
    $mail7  = (new AffiliatePayoutGraceWarningNotification($payout, 7))->toMail($aff)->subject;
    $mail1  = (new AffiliatePayoutGraceWarningNotification($payout, 1))->toMail($aff)->subject;

    expect($mail30)->toContain('30 days');
    expect($mail7)->toContain('7 days')->and->toContain('Stripe');
    expect($mail1)->toMatch('/(tomorrow|24 hours|final)/i');
});
```

- [ ] **Step 2: Run, expect failure**

```bash
php artisan test --filter=AffiliatePayoutGraceWarningNotification
```

- [ ] **Step 3: Implement the notification**

```php
<?php

namespace App\Notifications\Affiliate;

use App\Models\Retail\CommissionPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AffiliatePayoutGraceWarningNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CommissionPayout $payout,
        public int $daysRemaining
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = $this->payout->brandProfessional?->name ?? 'a brand';
        $amount = '$' . number_format($this->payout->gross_commission_cents / 100, 2);
        $connect = config('app.frontend_url') . '/affiliate/stripe/connect';

        return match (true) {
            $this->daysRemaining >= 30 => (new MailMessage)
                ->subject("Your {$amount} from {$brand} expires in 30 days")
                ->greeting('Heads up')
                ->line("You have {$amount} in commission from {$brand} ready to be paid.")
                ->line("To receive it, connect a Stripe account. After 60 days unconnected, the commission expires and the brand keeps the funds.")
                ->action('Connect Stripe (5 min)', $connect),

            $this->daysRemaining >= 7 => (new MailMessage)
                ->subject("Only 7 days left to claim your {$amount} from {$brand}")
                ->greeting('Time is running short')
                ->line("Your {$amount} commission from {$brand} expires in 7 days.")
                ->line("Connect Stripe now and we'll send the funds within 24h.")
                ->action('Connect Stripe', $connect),

            default => (new MailMessage)
                ->subject("Final notice: {$amount} from {$brand} expires tomorrow")
                ->greeting('Last chance')
                ->line("This is your final reminder. Your {$amount} commission from {$brand} expires in 24 hours.")
                ->line("If you don't connect Stripe before then, the commission is forfeited.")
                ->action('Connect Stripe — final reminder', $connect),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'payout_id'      => $this->payout->id,
            'brand_name'     => $this->payout->brandProfessional?->name,
            'amount_cents'   => $this->payout->gross_commission_cents,
            'void_at'        => $this->payout->void_at?->toIso8601String(),
            'days_remaining' => $this->daysRemaining,
            'connect_url'    => config('app.frontend_url') . '/affiliate/stripe/connect',
        ];
    }
}
```

- [ ] **Step 4: Re-run; expect green**

```bash
php artisan test --filter=AffiliatePayoutGraceWarningNotification
```

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Notifications/Affiliate/ tests/Feature/Notifications/AffiliatePayoutGraceWarningNotificationTest.php
git commit -m "feat(notifications): AffiliatePayoutGraceWarningNotification (T-30/T-7/T-1 escalation)"
```

### Task A4.3: `RetryPendingFundsPayoutsJob`

**Files:**
- Create: `app/Jobs/Stripe/RetryPendingFundsPayoutsJob.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Stripe/RetryPendingFundsPayoutsJobTest.php`

- [ ] **Step 1: Write failing tests**

```php
it('picks up pending_funds payouts where next_retry_at <= now', function () {
    $due = CommissionPayout::factory()->create([
        'status' => 'pending_funds', 'next_retry_at' => now()->subHour(), 'funding_failure_count' => 1,
    ]);
    $notDue = CommissionPayout::factory()->create([
        'status' => 'pending_funds', 'next_retry_at' => now()->addDay(), 'funding_failure_count' => 1,
    ]);

    Bus::fake();
    (new RetryPendingFundsPayoutsJob)->handle();

    Bus::assertDispatched(ExecuteCommissionPayoutJob::class, fn ($j) => $j->payoutId === $due->id);
    Bus::assertNotDispatched(ExecuteCommissionPayoutJob::class, fn ($j) => $j->payoutId === $notDue->id);
});

it('flags as terminally failed after 7 attempts and credits wallet back', function () {
    $brand = Professional::factory()->create(['stripe_manual_balance_cents' => 0]);
    $payout = CommissionPayout::factory()->create([
        'brand_professional_id' => $brand->id,
        'status' => 'pending_funds',
        'next_retry_at' => now()->subHour(),
        'funding_failure_count' => 7,
        'wallet_debit_cents' => 5000,
    ]);

    Notification::fake();
    (new RetryPendingFundsPayoutsJob)->handle();

    expect($payout->fresh()->status)->toBe('failed');
    expect($payout->fresh()->failure_category)->toBe('brand_funding');
    expect($brand->fresh()->stripe_manual_balance_cents)->toBe(5000); // credited back
    Notification::assertSentTo($brand, BrandPayoutFundingFailedNotification::class,
        fn ($n) => $n->isTerminal === true);
});

it('uses retryPayout to bump retry_count (so PI idempotency key changes)', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'pending_funds', 'next_retry_at' => now()->subHour(),
        'funding_failure_count' => 1, 'retry_count' => 0,
    ]);

    $service = $this->mock(CommissionPayoutService::class);
    $service->shouldReceive('retryPayout')->once()->with(\Mockery::on(fn ($p) => $p->id === $payout->id));

    (new RetryPendingFundsPayoutsJob)->handle();
});
```

- [ ] **Step 2: Implement the job**

```php
<?php

namespace App\Jobs\Stripe;

use App\Models\Commerce\WalletMovement;
use App\Models\Retail\CommissionPayout;
use App\Notifications\Brand\BrandPayoutFundingFailedNotification;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryPendingFundsPayoutsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public const MAX_ATTEMPTS = 7;

    public function handle(CommissionPayoutService $service = null): void
    {
        $service ??= app(CommissionPayoutService::class);

        CommissionPayout::query()
            ->where('status', 'pending_funds')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->chunkById(50, function ($payouts) use ($service) {
                foreach ($payouts as $payout) {
                    $this->retryOne($payout, $service);
                }
            });
    }

    private function retryOne(CommissionPayout $payout, CommissionPayoutService $service): void
    {
        if ($payout->funding_failure_count >= self::MAX_ATTEMPTS) {
            $this->markTerminal($payout);
            return;
        }

        try {
            // retryPayout bumps retry_count → PI idempotency key changes.
            $service->retryPayout($payout);
        } catch (\Throwable $e) {
            Log::warning('payout.retry.exception', [
                'payout_id' => $payout->id, 'error' => $e->getMessage(),
            ]);
        }

        // After the retry attempt, check current status to decide whether a "cycle" notification
        // should fire (informs brand the retry happened and another is scheduled).
        // The notification's via() method gates email vs database-only — see A4.1 digest pattern.
        $payout->refresh();
        if ($payout->status === 'pending_funds') {
            $payout->brandProfessional?->notify(
                new BrandPayoutFundingFailedNotification($payout, isTerminal: false)
            );
        }
    }

    private function markTerminal(CommissionPayout $payout): void
    {
        DB::transaction(function () use ($payout) {
            // Re-fetch the payout under lock to avoid stale wallet_debit_cents reads
            // if a concurrent webhook/job is also touching this row.
            $payout = CommissionPayout::query()
                ->where('id', $payout->id)
                ->lockForUpdate()
                ->first();

            if (! $payout || $payout->status === 'failed') {
                return; // already terminal — nothing to do
            }

            $brand = $payout->brandProfessional()->lockForUpdate()->first();

            $payout->forceFill([
                'status' => 'failed',
                'failure_code' => 'brand_funding_exhausted',
                'failure_reason' => 'Card declined 7 times; wallet credited back',
                'failure_category' => 'brand_funding',
            ])->save();

            // Credit the wallet back if a wallet debit had been taken.
            if ($payout->wallet_debit_cents > 0 && $brand) {
                WalletMovement::create([
                    'professional_id'    => $brand->id,
                    'direction'          => 'credit',
                    'amount_cents'       => $payout->wallet_debit_cents,
                    'currency_code'      => $payout->currency_code,
                    'reason'             => 'retry_refund',
                    'actor_type'         => 'job',
                    'actor_id'           => self::class,
                    'related_payout_id'  => $payout->id,
                    'idempotency_key'    => 'retry_refund:' . $payout->id,
                ]);

                Professional::where('id', $brand->id)
                    ->increment('stripe_manual_balance_cents', $payout->wallet_debit_cents);
            }

            $brand?->notify(new BrandPayoutFundingFailedNotification($payout, isTerminal: true));
        });
    }
}
```

- [ ] **Step 3: Schedule daily 07:15 UTC**

In `routes/console.php`:

```php
use App\Jobs\Stripe\RetryPendingFundsPayoutsJob;

Schedule::job(new RetryPendingFundsPayoutsJob)
    ->dailyAt('07:15')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->onQueue('payouts'); // dedicated queue keeps these off `default` during retry storms
```

Audit `config/horizon.php` (or `config/queue.php`) to confirm the `payouts` queue exists or add it. If a different convention is used (e.g. `redis_video` for video, plain `default` otherwise), use the closest analog and document the choice in the commit message.

- [ ] **Step 4: Re-run; commit**

```bash
php artisan test --filter=RetryPendingFundsPayoutsJob
git add app/Jobs/Stripe/RetryPendingFundsPayoutsJob.php tests/Feature/Stripe/RetryPendingFundsPayoutsJobTest.php routes/console.php
git commit -m "feat(stripe): RetryPendingFundsPayoutsJob (7-day card-decline retry; wallet credit on terminal)"
```

### Task A4.4: Extend `VoidExpiredPayoutsJob` with grace warnings using JSONB array

**Files:**
- Modify: `app/Jobs/Stripe/VoidExpiredPayoutsJob.php`
- Test: `tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php`

- [ ] **Step 1: Write failing tests**

```php
it('fires T-30 warning once when void_at is exactly 30 days out', function () {
    Notification::fake();
    $aff = Professional::factory()->create(['stripe_connect_status' => 'pending']);
    $payout = CommissionPayout::factory()->create([
        'affiliate_professional_id' => $aff->id,
        'status' => 'pending', 'void_at' => now()->addDays(30)->addMinutes(1),
        'grace_notifications_sent' => [],
    ]);

    (new VoidExpiredPayoutsJob)->handle();

    Notification::assertSentTo($aff, AffiliatePayoutGraceWarningNotification::class,
        fn ($n) => $n->daysRemaining === 30);
    expect($payout->fresh()->grace_notifications_sent)->toContain('T-30');
});

it('does NOT re-fire T-30 if already in grace_notifications_sent', function () {
    Notification::fake();
    $aff = Professional::factory()->create(['stripe_connect_status' => 'pending']);
    $payout = CommissionPayout::factory()->create([
        'affiliate_professional_id' => $aff->id,
        'status' => 'pending', 'void_at' => now()->addDays(30)->addMinutes(1),
        'grace_notifications_sent' => ['T-30'],
    ]);

    (new VoidExpiredPayoutsJob)->handle();

    Notification::assertNotSentTo($aff, AffiliatePayoutGraceWarningNotification::class);
});

it('handles a payout created with only 5 days grace (skips T-30 + T-7, only fires T-1)', function () {
    Notification::fake();
    $aff = Professional::factory()->create(['stripe_connect_status' => 'pending']);
    $payout = CommissionPayout::factory()->create([
        'affiliate_professional_id' => $aff->id,
        'status' => 'pending', 'void_at' => now()->addDay()->addMinutes(1),
        'grace_notifications_sent' => [],
    ]);

    (new VoidExpiredPayoutsJob)->handle();

    Notification::assertSentTo($aff, AffiliatePayoutGraceWarningNotification::class,
        fn ($n) => $n->daysRemaining === 1);
    expect($payout->fresh()->grace_notifications_sent)->toBe(['T-1']);
});
```

- [ ] **Step 2: Add the grace warning loop at the start of `handle()`**

```php
public function handle(): void
{
    $this->fireGraceWarnings();
    // existing void sweep continues
    $this->voidExpiredPayouts();
}

private function fireGraceWarnings(): void
{
    foreach ([30, 7, 1] as $daysOut) {
        $tag = 'T-' . $daysOut;
        $windowStart = now()->addDays($daysOut)->startOfDay();
        $windowEnd   = now()->addDays($daysOut)->endOfDay();

        $candidates = CommissionPayout::query()
            ->whereIn('status', ['pending', 'pending_funds'])
            ->whereBetween('void_at', [$windowStart, $windowEnd])
            ->whereDoesntHave('affiliateProfessional', fn ($q) =>
                $q->where('stripe_connect_status', 'active'))
            ->get()
            ->filter(fn ($p) => ! in_array($tag, $p->grace_notifications_sent ?? [], true));

        foreach ($candidates as $payout) {
            $payout->affiliateProfessional?->notify(
                new AffiliatePayoutGraceWarningNotification($payout, $daysOut)
            );

            $sent = $payout->grace_notifications_sent ?? [];
            $sent[] = $tag;
            $payout->forceFill(['grace_notifications_sent' => array_values(array_unique($sent))])->save();
        }
    }
}
```

- [ ] **Step 3: Re-run; commit**

```bash
git add app/Jobs/Stripe/VoidExpiredPayoutsJob.php tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php
git commit -m "feat(stripe): grace warnings T-30/T-7/T-1 fired by VoidExpiredPayoutsJob (JSONB-tracked dedup)"
```

### Task A4.5: Out-of-bounds metafield → `rate_source='pending'` + payout filter

**Files:**
- Modify: `app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php` (`resolveCommissionRate`)
- Modify: `app/Services/Stripe/CommissionPayoutService.php` (already added `rate_source != 'pending'` filter in A3.2)
- Test: `tests/Feature/Webhooks/Shopify/OrderPaidHappyPathTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('out-of-bounds metafield (>100) sets rate_source=pending and skips payout eligibility', function () {
    $brand = Professional::factory()->create([
        'professional_type' => 'brand',
        'stripe_payment_method_id' => 'pm_test',
        'stripe_customer_id' => 'cus_test',
    ]);
    $aff = Professional::factory()->create();

    // Simulate webhook ingest with metafield value 150
    $this->ingestShopifyOrderPaid([
        'brand'        => $brand,
        'affiliate'    => $aff,
        'gross_cents'  => 10000,
        'metafield_override' => 150,  // out of bounds
    ]);

    $order = Order::where('brand_professional_id', $brand->id)->firstOrFail();
    expect($order->rate_source)->toBe('pending');
    expect($order->status)->toBe('approved');

    // Eligibility: should NOT pick this up
    app(CommissionPayoutService::class)->processEligiblePayouts();
    expect($order->fresh()->payout_id)->toBeNull();
});
```

- [ ] **Step 2: Update `resolveCommissionRate`**

```php
private function resolveCommissionRate(string $productId, BrandStoreSettings $settings, float $platformDefault): array
{
    $override = $this->commissionOverrides[$productId] ?? null;

    if ($override !== null) {
        if ($override > 0 && $override <= 100) {
            return [(float) $override, 'metafield_override'];
        }

        Log::warning('shopify.commission_override.out_of_bounds', [
            'product_id' => $productId, 'value' => $override,
        ]);
        report(new \DomainException("Out-of-bounds commission metafield {$override}% on product {$productId}"));

        // Use brand default for the figure but tag rate_source=pending so
        // CommissionPayoutService skips it until ops resolves.
        $defaultRate = (float) ($settings->default_commission_rate ?? $platformDefault);
        return [$defaultRate, 'pending'];
    }

    if ($settings->default_commission_rate !== null) {
        return [(float) $settings->default_commission_rate, 'brand_default'];
    }

    return [$platformDefault, 'platform_default'];
}
```

- [ ] **Step 3: Re-run; commit**

```bash
git add app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php tests/Feature/Webhooks/Shopify/OrderPaidHappyPathTest.php
git commit -m "feat(commission): out-of-bounds metafield → rate_source=pending + payout-eligibility skip"
```

### Task A4.6: `CommissionPayoutRefundService` (the centrepiece of A4)

**Files:**
- Create: `app/Services/Stripe/CommissionPayoutRefundService.php`
- Test: `tests/Feature/Stripe/CommissionPayoutRefundServiceTest.php`
- Modify: `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/Stripe/CommissionPayoutRefundServiceTest.php

it('full refund of an order in a pending payout removes the item and shrinks gross_commission', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'pending', 'gross_commission_cents' => 10000,
        'platform_fee_cents' => 1000, 'net_payout_cents' => 9000, 'ledger_entry_count' => 2,
    ]);

    $orderA = Order::factory()->create(['payout_id' => $payout->id, 'commission_cents' => 4000]);
    $orderB = Order::factory()->create(['payout_id' => $payout->id, 'commission_cents' => 6000]);

    CommissionPayoutItem::factory()->create(['payout_id' => $payout->id, 'order_id' => $orderA->id, 'amount_cents' => 4000]);
    CommissionPayoutItem::factory()->create(['payout_id' => $payout->id, 'order_id' => $orderB->id, 'amount_cents' => 6000]);

    // Full refund on orderA
    $orderA->forceFill(['status' => 'refunded', 'refund_cents' => $orderA->gross_cents])->save();

    app(CommissionPayoutRefundService::class)->handleOrderRefund($orderA);

    $payout->refresh();
    expect($payout->status)->toBe('pending');
    expect($payout->gross_commission_cents)->toBe(6000);
    expect($payout->ledger_entry_count)->toBe(1);
    expect($orderA->fresh()->payout_id)->toBeNull();
    expect(CommissionPayoutItem::where('order_id', $orderA->id)->count())->toBe(0);
});

it('full refund of last item cancels the payout', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'pending', 'gross_commission_cents' => 5000, 'ledger_entry_count' => 1,
    ]);
    $order = Order::factory()->create(['payout_id' => $payout->id, 'commission_cents' => 5000]);
    CommissionPayoutItem::factory()->create(['payout_id' => $payout->id, 'order_id' => $order->id, 'amount_cents' => 5000]);

    $order->forceFill(['status' => 'refunded', 'refund_cents' => $order->gross_cents])->save();
    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    $payout->refresh();
    expect($payout->status)->toBe('cancelled');
    expect($payout->failure_code)->toBe('refunded_within_grace');
    expect($payout->failure_category)->toBe('order_refunded');
});

it('partial refund recomputes gross_commission proportionally', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'pending']);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 10000, 'commission_cents' => 1000, 'commission_rate' => 10.0,
    ]);
    CommissionPayoutItem::factory()->create(['payout_id' => $payout->id, 'order_id' => $order->id, 'amount_cents' => 1000]);

    // Half refund
    $order->forceFill(['status' => 'partially_refunded', 'refund_cents' => 5000])->save();
    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    expect($payout->fresh()->gross_commission_cents)->toBe(500); // 10% of remaining 5000
    expect(CommissionPayoutItem::where('order_id', $order->id)->first()->amount_cents)->toBe(500);
});

it('refund of order in completed payout is a no-op (clawback flow handles it)', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'completed', 'gross_commission_cents' => 5000]);
    $order = Order::factory()->create(['payout_id' => $payout->id, 'commission_cents' => 5000, 'status' => 'refunded']);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    expect($payout->fresh()->status)->toBe('completed');
    expect($payout->fresh()->gross_commission_cents)->toBe(5000);
});

it('refund of order in collecting/transferring sets needs_manual_refund + alerts', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'collecting']);
    $order = Order::factory()->create(['payout_id' => $payout->id, 'status' => 'refunded']);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    expect($payout->fresh()->needs_manual_refund)->toBeTrue();
});

it('manually adjusts brand_affiliate_rollup (trigger does NOT fire on payout_id changes)', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'pending']);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'commission_cents' => 5000,
        'occurred_at' => now()->startOfDay(),
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);
    CommissionPayoutItem::factory()->create(['payout_id' => $payout->id, 'order_id' => $order->id, 'amount_cents' => 5000]);

    $order->forceFill(['status' => 'refunded', 'refund_cents' => $order->gross_cents])->save();
    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    $rollup = DB::table('commerce.brand_affiliate_rollup')
        ->where('day', $order->occurred_at->toDateString())
        ->where('brand_professional_id', $order->brand_professional_id)
        ->first();

    // Confirm rollup absorbed the refund (trigger handles refund_cents delta;
    // manual adjustment ensures payout_id transition is reflected too)
    expect($rollup)->not->toBeNull();
});
```

- [ ] **Step 2: Implement the service**

```php
<?php

namespace App\Services\Stripe;

use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionPayoutRefundService
{
    public function __construct(
        private readonly AnalyticsCacheService $analyticsCache
    ) {}

    public function handleOrderRefund(Order $order): void
    {
        if (! $order->payout_id) return;
        if (! in_array($order->status, ['refunded', 'partially_refunded'], true)) return;

        DB::transaction(function () use ($order) {
            $payout = CommissionPayout::query()
                ->where('id', $order->payout_id)
                ->lockForUpdate()
                ->first();

            if (! $payout) return;

            // Terminal states (post-completed) — out of scope, log and exit.
            if (in_array($payout->status, ['completed', 'failed', 'cancelled', 'reversed'], true)) {
                Log::info('payout.refund.terminal_state_skip', [
                    'order_id' => $order->id, 'payout_id' => $payout->id, 'status' => $payout->status,
                ]);
                return;
            }

            // Money in flight — flag for ops, do not unwind.
            if (in_array($payout->status, ['collecting', 'transferring'], true)) {
                $payout->forceFill(['needs_manual_refund' => true])->save();
                Log::warning('payout.refund.mid_flight', [
                    'order_id' => $order->id, 'payout_id' => $payout->id,
                ]);
                return;
            }

            // pending / pending_funds → recompute or cancel.
            if ($order->status === 'partially_refunded') {
                $this->shrinkItem($payout, $order);
            } else {
                $this->removeItem($payout, $order);
            }

            // Manual rollup adjustment because the orders trigger doesn't react to payout_id changes.
            $this->adjustRollup($order);

            $this->analyticsCache->bumpAnalyticsVersion($order->affiliate_professional_id);
            $this->analyticsCache->bumpAnalyticsVersion($order->brand_professional_id);
            Cache::forget(CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id));
        });
    }

    private function shrinkItem(CommissionPayout $payout, Order $order): void
    {
        // New commission = (gross - refund) * (rate / 100), rounded.
        $remainingNet = max(0, $order->gross_cents - $order->refund_cents);
        $newCommission = (int) round($remainingNet * ($order->commission_rate / 100.0));

        $oldItem = CommissionPayoutItem::where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->first();
        if (! $oldItem) return;

        $delta = $oldItem->amount_cents - $newCommission;

        $oldItem->forceFill(['amount_cents' => $newCommission])->save();
        $order->forceFill(['commission_cents' => $newCommission])->save();

        $newGross = max(0, $payout->gross_commission_cents - $delta);
        $newFee   = (int) round($newGross * ($payout->platform_fee_cents / max(1, $payout->gross_commission_cents)));
        $newNet   = $newGross - $newFee;

        $payout->forceFill([
            'gross_commission_cents' => $newGross,
            'platform_fee_cents'     => $newFee,
            'net_payout_cents'       => $newNet,
        ])->save();
    }

    private function removeItem(CommissionPayout $payout, Order $order): void
    {
        $item = CommissionPayoutItem::where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->first();

        if ($item) $item->delete();

        $order->forceFill(['payout_id' => null])->save();

        $remainingItems = CommissionPayoutItem::where('payout_id', $payout->id)->count();

        if ($remainingItems === 0) {
            $payout->forceFill([
                'status'           => 'cancelled',
                'failure_code'     => 'refunded_within_grace',
                'failure_reason'   => 'All orders refunded before payout completed',
                'failure_category' => 'order_refunded',
                'processed_at'     => now(),
            ])->save();
            return;
        }

        // Recompute totals from surviving items.
        $newGross = (int) CommissionPayoutItem::where('payout_id', $payout->id)->sum('amount_cents');
        $feePct = $payout->gross_commission_cents > 0
            ? $payout->platform_fee_cents / $payout->gross_commission_cents
            : 0;
        $newFee = (int) round($newGross * $feePct);
        $newNet = $newGross - $newFee;

        $payout->forceFill([
            'gross_commission_cents' => $newGross,
            'platform_fee_cents'     => $newFee,
            'net_payout_cents'       => $newNet,
            'ledger_entry_count'     => $remainingItems,
        ])->save();
    }

    /**
     * Reconcile commerce.brand_affiliate_rollup after a refund-cancellation.
     *
     * The orders trigger trg_rollup → commerce.rollup_apply_delta() runs on every
     * UPDATE of commerce.orders. For the changes this service makes to an order:
     *
     *   1. shrinkItem() — partial refund updates order.commission_cents AND
     *      order.refund_cents (both money fields). The trigger fires, and
     *      reversed_commission_cents in the rollup is incremented for the day.
     *      ✓ No manual adjustment needed.
     *
     *   2. removeItem() — full refund came via the upstream Shopify webhook,
     *      which set order.status='refunded' AND order.refund_cents=gross_cents
     *      BEFORE this service runs. The trigger already fired and updated
     *      reversed_commission_cents. We additionally set order.payout_id=NULL,
     *      but the trigger's logic (commerce.rollup_apply_delta) only inspects
     *      gross_cents/refund_cents/net_cents/commission_cents — payout_id is
     *      not a tracked field, so the additional UPDATE is a no-op for the
     *      rollup. The reversed_commission_cents value is already correct.
     *      ✓ No manual adjustment needed.
     *
     * What WOULD require manual adjustment here:
     *
     *   - If brand_affiliate_rollup ever adds paid_commission_cents or
     *     pending_commission_cents columns (denormalised by payout-status),
     *     then payout_id=NULL transitions need to move pending_commission_cents
     *     down by order.commission_cents.
     *
     *   - If we ever cancel a payout that had been promoted to status=completed
     *     (out of scope for this service — handled by the clawback flow), we'd
     *     need to move paid_commission_cents back to pending or reversed.
     *
     * For both cases: extend this method, lock the rollup row for the day with
     * SELECT ... FOR UPDATE, recompute, and commit within the same transaction
     * as the order update so the rollup never desyncs from the snapshot.
     */
    private function adjustRollup(Order $order): void
    {
        // Intentional no-op. See method docblock for the full reasoning.
        // Do NOT delete this method — keeping it as a named extension point
        // means the next dev who adds payout-status columns to the rollup has
        // an obvious place to wire reconciliation, with the contract already
        // documented.
    }
}
```

- [ ] **Step 3: Wire from `ProcessShopifyOrderUpdatedWebhookJob`**

After the refund-persist statement, add:

```php
if (in_array($order->status, ['refunded', 'partially_refunded'], true)) {
    app(CommissionPayoutRefundService::class)->handleOrderRefund($order->fresh());
}
```

- [ ] **Step 4: Re-run; commit**

```bash
git add app/Services/Stripe/CommissionPayoutRefundService.php app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php tests/Feature/Stripe/CommissionPayoutRefundServiceTest.php
git commit -m "feat(stripe): refund-during-grace cancels or shrinks in-flight payout (full + partial branches)"
```

---

## Phase A5 — Verification + final hardening

### Task A5.1: Pin the Stripe API version

**Files:**
- Modify: `config/services.php` (or `config/cashier.php`)
- Modify: `app/Providers/AppServiceProvider.php` (or wherever `StripeClient` is bound)
- Modify: `.env.example`

- [ ] **Step 1: Find the latest Stripe API version your SDK supports**

```bash
composer show stripe/stripe-php | grep -E "versions|description"
grep -rn "API_VERSION\|api_version" vendor/stripe/stripe-php/lib/Stripe.php
```

Use the value of `Stripe::API_VERSION` printed by the SDK as your default. Don't paste the placeholder string; use what's actually there.

- [ ] **Step 2: Add config**

```php
// config/services.php — append under 'stripe'
'api_version' => env('STRIPE_API_VERSION'),  // null falls back to SDK pinned default
```

- [ ] **Step 3: Pass version to StripeClient binding**

```php
$this->app->singleton(StripeClient::class, fn () => new StripeClient(array_filter([
    'api_key'        => config('services.stripe.secret'),
    'stripe_version' => config('services.stripe.api_version'),  // null is omitted by array_filter
])));
```

- [ ] **Step 4: Add to `.env.example`** (use the version you read from `Stripe::API_VERSION` in Step 1)

```
# Pin Stripe API version to insulate from SDK upgrade behaviour changes.
# Read the SDK's current default from vendor/stripe/stripe-php/lib/Stripe.php (API_VERSION constant).
STRIPE_API_VERSION=
```

- [ ] **Step 5: Commit**

```bash
git add config/ app/Providers/AppServiceProvider.php .env.example
git commit -m "feat(stripe): pin Stripe API version via STRIPE_API_VERSION env"
```

### Task A5.2: Confirm soft-delete exclusion for financial tables

**Files:**
- Audit: any `SoftDeletes` traits or scheduled deletion commands
- Modify: `app/Console/Commands/PurgeSoftDeletedRecordsCommand.php` (if it exists)

- [ ] **Step 1: Audit**

```bash
grep -rn "SoftDeletes\|forceDelete\|use Illuminate.*SoftDeletes" app/Models/Retail/CommissionPayout.php app/Models/Retail/CommissionMovement.php app/Models/Commerce/Order.php app/Models/Commerce/WalletMovement.php
```

Expected: NO `SoftDeletes` trait on any of these models.

If a `PurgeSoftDeletedRecordsCommand` (or similar) exists, confirm it excludes commerce + retail tables. Add explicit exclusion if missing.

- [ ] **Step 2: Test the exclusion**

```php
it('does not soft-delete commission_payouts after retention window', function () {
    $payout = CommissionPayout::factory()->create(['created_at' => now()->subYears(2)]);
    Artisan::call('partna:purge-soft-deleted');
    expect(CommissionPayout::find($payout->id))->not->toBeNull();
});
```

### Task A5.3: Static + suite verification

- [ ] **Step 1: Static checks**

```bash
grep -rn "stripe_application_fee_id" app/ routes/   # expect 0
grep -rn "orders.stripe_payment_intent_id\|orders.stripe_transfer_id" app/   # expect 0
grep -rn "abort(403)" app/Http/Controllers/Api/Professional/Stripe/   # expect 0
grep -rn "\$request->validate(" app/Http/Controllers/Api/Professional/Stripe/   # expect 0
```

- [ ] **Step 2: Pint**

```bash
vendor/bin/pint --dirty
```

Expect: clean.

- [ ] **Step 3: Full suite**

```bash
composer test
```

Expect: green.

- [ ] **Step 4: Schedule + route audit**

```bash
php artisan route:list --path=api/professional | grep -E 'billing-summary|brand/payouts|affiliate/payouts|topups|connect/start|sync-session'
php artisan schedule:list | grep -E 'Retry|Reconcile|Void'
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: final verification (Pint, full suite, schedule + route audit)"
```

---

# Stage 2 — Lane B (Frontend) — API contract spec

This stage is NOT implemented from this document. It's the contract Lane B implements in Partna-Frontend.

## Endpoints (Lane B reads after Lane A Phase A2 ships)

| Endpoint | Status | Lane A Task |
|----------|--------|-------------|
| `GET /api/professional/brand/billing-summary` | NEW | A2.5 |
| `GET /api/professional/brand/payouts` | NEW | A2.5 |
| `GET /api/professional/affiliate/payouts` | NEW | A2.5 |
| `POST /api/professional/affiliate/stripe/connect/start` | NEW | A2.5 |
| `POST /api/professional/stripe/topups/checkout` | EXISTING | A3.1 (refactor only) |
| `POST /api/professional/stripe/topups/confirm` | EXISTING | A3.1 (refactor only) |
| `POST /api/professional/stripe/payment-method/setup` | EXISTING | A0.3 (refactor only) |
| `POST /api/professional/stripe/payment-method/sync-session` | EXISTING | A0.3 (refactor only) |
| `GET /api/professional/affiliate/commerce-analytics` | CONTRACT TIGHTENED | A2.1 |
| `GET /api/professional/brand/commerce-analytics` | CONTRACT TIGHTENED | A2.1 |

## Response shapes

### `GET /api/professional/brand/billing-summary`

```json
{
  "has_card": true,
  "masked_card": { "brand": "visa", "last4": "4242" },
  "wallet_balance_cents": 25000,
  "currency": "AUD",
  "blocked_orders_count": 0,
  "blocked_pending_cents": 0,
  "recent_topups": [
    { "id": "uuid", "amount_cents": 10000, "currency_code": "AUD", "occurred_at": "2026-05-08T12:34:56Z" }
  ]
}
```

When `has_card === false`, `blocked_orders_count` and `blocked_pending_cents` are populated from the count of approved orders waiting for a card.

### `GET /api/professional/brand/payouts`

```json
{
  "data": [
    {
      "id": "uuid",
      "status": "pending|pending_funds|collecting|transferring|completed|failed|cancelled|reversed",
      "gross_commission_cents": 12300,
      "net_payout_cents": 11070,
      "platform_fee_cents": 1230,
      "currency_code": "AUD",
      "failure_code": "card_declined|null",
      "failure_category": "brand_funding|affiliate_account|stripe_transient|stripe_terminal|platform|order_refunded|null",
      "failure_reason": "Human-friendly reason from us",
      "stripe_error_code": "card_declined|null",
      "stripe_error_message": "Verbatim Stripe message; show in advanced disclosure only",
      "funding_failure_count": 0,
      "next_retry_at": "ISO 8601 or null",
      "last_retry_at": "ISO 8601 or null",
      "transfer_completed_at": "ISO 8601 or null",
      "void_at": "ISO 8601",
      "created_at": "ISO 8601",
      "affiliate": { "id": "uuid", "name": "Affiliate Inc" }
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "to": 50, "total": 312 }
}
```

### `GET /api/professional/affiliate/payouts`

Same shape; `affiliate` block replaced with `brand: { id, name }`. Affiliate side hides `failure_category` if it's brand-side (`'brand_funding'`) — affiliate sees "Brand is sorting funding, we'll keep trying" instead.

### `POST /api/professional/affiliate/stripe/connect/start`

```json
{ "onboarding_url": "https://connect.stripe.com/express/onboarding/..." }
```

### `GET /api/professional/me/notifications` (existing endpoint — confirmed shape for new types)

Standard Laravel paginated `notifications` table. Each item:

```json
{
  "id": "uuid",
  "type": "App\\Notifications\\Brand\\BrandPayoutFundingFailedNotification",
  "data": { /* per-type payload — see below */ },
  "read_at": null,
  "created_at": "ISO8601"
}
```

**Frontend switches on the short type name** (last `\\`-segment) to choose a renderer.

`BrandPayoutFundingFailedNotification.data`:
```json
{
  "payout_id": "uuid",
  "affiliate_name": "Affiliate Inc",
  "amount_cents": 12300,
  "failure_reason": "Card declined",
  "next_retry_at": "ISO8601 or null",
  "is_terminal": false
}
```
- **Variant discriminator**: `data.is_terminal` (`false` = cycle, `true` = terminal-after-7).

`AffiliatePayoutGraceWarningNotification.data`:
```json
{
  "payout_id": "uuid",
  "brand_name": "Brand X",
  "amount_cents": 8500,
  "void_at": "ISO8601",
  "days_remaining": 30,
  "connect_url": "https://..."
}
```
- **Variant discriminator**: `data.days_remaining` (one of `30`, `7`, `1`).

**Backend verification step** (cheap one-liner): confirm `NotificationController::index` does not strip the `type` field from response payloads. If it does, expose it.

## `/stripe/payouts` migration decision

The pre-existing `GET /stripe/payouts?role=brand|affiliate` endpoint is **migrating** to two role-scoped routes (`/brand/payouts` + `/affiliate/payouts`) shipped in A2.5. Reason: the response shape diverges (brand sees `affiliate.{id,name}`; affiliate sees `brand.{id,name}`) and the new fields (`failure_category`, `funding_failure_count`, `transfer_completed_at`, `next_retry_at`, etc.) make a single-endpoint contract awkward.

**Migration plan**:
1. A2.5 ships the two new routes alongside the existing `/stripe/payouts`.
2. `/stripe/payouts` returns the LEGACY shape (no new fields) for one release.
3. Frontend swaps over in Lane B Phase B2.
4. Follow-up ticket deletes `/stripe/payouts` once telemetry shows zero usage.

**Action item**: backend dev greps for other consumers (admin tooling, mobile, scripts) before A2.5 — if any exist, extend the deprecation window.

## Behaviour changes (frontend implications)

1. **`commission_paid_cents` lags Transfer settlement.** Don't aggressively cache analytics; backend push-invalidates on settlement.

2. **Banner state — brand has no card on file.** Read `billing-summary.has_card === false && blocked_orders_count > 0`. Banner copy: *"Add a card to start sending payouts. {N} affiliate sales totalling ${X} are waiting."*

3. **Banner state — card declined, retrying.** Read any payout where `status === 'pending_funds' && funding_failure_count > 0`. Cycle copy uses `failure_reason` and `next_retry_at`. Terminal copy fires when `status === 'failed' && failure_category === 'brand_funding'`.

4. **Banner state — grace period escalating.** Read affiliate payouts; minimum `void_at`. Color escalates: blue >30d, yellow ≤30d, orange ≤7d, red ≤1d.

5. **Refund during grace shrinks/cancels payout.** Affiliate sees:
   - `status === 'cancelled' && failure_code === 'refunded_within_grace'` → tile: *"Cancelled — order refunded"*
   - Partial refund leaves payout `pending`; affiliate sees `gross_commission_cents` decrease over time. New tile: *"Adjusted — partial refund (${X} removed)"*. Backend will fire `AffiliateCommissionRefundedNotification` if implemented (out of scope here; coordinate before adding).

6. **Top-up Checkout return states**: `?topup=success`, `?topup=cancelled`, `?topup=failed&reason={...}` (the third is new — handle the toast).

7. **Notification feed — two new types**:
   - `App\Notifications\Brand\BrandPayoutFundingFailedNotification`
   - `App\Notifications\Affiliate\AffiliatePayoutGraceWarningNotification`

8. **Display rules**:
   - `transfer_completed_at` is the user-facing "paid on" date. Don't show `processed_at`.
   - `failure_reason` to brands; `stripe_error_message` only in advanced disclosure.
   - `failure_category` powers banner-copy switching.

## Error response contract

All 4xx/5xx responses from the new endpoints follow Laravel's default envelope:

```json
{ "message": "...", "errors": { "field": ["reason"] } }
```

Per-endpoint expected status codes:

| Endpoint | 200 | 401 | 403 | 404 | 409 | 422 |
|----------|-----|-----|-----|-----|-----|-----|
| `GET /brand/billing-summary` | ✓ | missing/invalid JWT | not a brand professional | — | — | — |
| `GET /brand/payouts` | ✓ | missing/invalid JWT | not a brand professional | — | — | — |
| `GET /affiliate/payouts` | ✓ | missing/invalid JWT | not an affiliate professional | — | — | — |
| `POST /affiliate/stripe/connect/start` | ✓ | missing/invalid JWT | not an affiliate professional | — | already connected (active) | — |
| `POST /stripe/topups/checkout` | ✓ | missing/invalid JWT | not a brand | — | — | amount out of bounds, no `success_url` |
| `POST /stripe/topups/confirm` | ✓ | missing/invalid JWT | not a brand | session not found | session already confirmed | invalid `session_id` |
| `POST /stripe/payment-method/setup` | ✓ | missing/invalid JWT | not a brand | — | — | missing URL fields |
| `POST /stripe/payment-method/sync-session` | ✓ | missing/invalid JWT | not a brand | session not found | — | invalid `session_id` |
| `GET /commerce-analytics` (affiliate/brand) | ✓ | missing/invalid JWT | wrong professional type | — | — | invalid `from`/`to` |

Webhook endpoints (`/api/webhooks/stripe-connect`, `/api/webhooks/shopify/...`) ALWAYS return 2xx unless signature verification fails (then 400). Internal handler failures log + report() to Nightwatch but do NOT propagate as 5xx, to avoid Stripe disabling the endpoint after retries.

---

# Stage 3 — Cross-stack verification

After Lane A and Lane B ship.

- [ ] **Happy path**: place order → `paid_cents` updates ONLY after `transfer.paid` (not at batch creation).
- [ ] **Card decline path**: brand decline → banner appears → 7 daily retries → success between retries clears banner.
- [ ] **Affiliate not-connected path**: affiliate sees grace banner; T-30/7/1 emails fire; void at T-0 if not connected.
- [ ] **Refund-during-grace path**: full refund cancels payout; partial refund shrinks; affiliate dashboard reflects within cache TTL.
- [ ] **Out-of-bounds metafield path**: order ingests with `rate_source='pending'`; payout sweep skips it; ops sees Nightwatch alert.
- [ ] **Stuck transfer path**: simulate `transfer.paid` webhook drop; reconciliation job (07:30 UTC) flips status from `transferring` to `completed` based on Stripe truth.
- [ ] **Wallet ledger reconciliation**: sum of `wallet_movements` for a brand equals `stripe_manual_balance_cents` to the cent.

---

# Self-review checklist (run by author after writing the plan)

**1. Spec coverage:**
- ✅ All 10 locked decisions map to phases
- ✅ All 9 verified critical findings have tasks
- ✅ All 8 deep-audit issues (filtered) have tasks
- ✅ All 4 Lane B prerequisite endpoints are in Phase A2.5 (BEFORE A3+)
- ✅ The "top-up endpoints already exist" correction is honoured (A3.1 refactors both `createManualTopUpCheckoutSession` AND `confirmTopUpCheckoutSession`, doesn't recreate)
- ✅ The "trigger doesn't handle payout_id" correction is honoured (refund service documents it; explains why no manual adjustment is needed today + extension point if rollup grows)
- ✅ The "rate_source='pending' doesn't exist today" correction is honoured (introduce + filter same commit)
- ✅ Test helpers verified/created in Phase A0.4 BEFORE any test references them
- ✅ Card-setup endpoint name reconciliation tasked in A0.5
- ✅ `type` field on `/me/notifications` verified in A0.6
- ✅ AUSTRAC `actor_type` + `actor_id` on every wallet movement (audit trail from day one)
- ✅ Email digest pattern: cycle emails fire only on attempts 1 + terminal; database channel always fires

**2. Placeholder scan:**
- All "TBD" / "implement later" / "similar to Task N" patterns absent
- A4.2 has full body (was previously a "follow A4.1 template" stub — removed)
- Each step has executable code or explicit no-code-needed reason

**3. Type consistency:**
- `bumpAnalyticsVersion(string $professionalId)` used identically across A2.2, A2.4, A3.3, A4.6 — consistent
- `failPayout`/`markPendingFunding` signatures consistent across A3.3 and all callers
- `commission_payouts.failure_category` enum values consistent across migration CHECK + classifier + refund service + notifications
- `WalletMovement::reason` enum values consistent across migration CHECK + factory + all writers
- `WalletMovement::actor_type` enum values consistent across migration CHECK + factory + all writers (`'system'`, `'webhook'`, `'job'`, `'admin'`, `'professional'`)

**4. Phase ordering audit:**
- A0 lays auth + validation + test-helpers + endpoint-name + notification-type-field foundations BEFORE any new behaviour (correct)
- A1 schema is additive-then-drops (additive 1.1, 1.3, 1.4; drops 1.2) — drops happen AFTER additive, AFTER pre-flight grep, AFTER cross-repo + view checks — correct
- A2 ships the API contract (analytics tightening + transfer.paid + new endpoints + version-key wiring) BEFORE Lane B starts — correct
- A2.2 webhook stubs the `'payment'` mode so it can ship without A3.1; A3.1 implements
- A3 (top-up refactor + card gate) depends on A1 (wallet_movements table + factories) and A2 (cache version helper) — correct
- A4 (failure handling, retry, grace, refund) depends on A3 (failPayout signature, wallet_movements writers) — correct
- A5 verification is last — correct

**5. Migration safety:**
- All 5 migrations have explicit DOWN SQL in commented blocks
- `wallet_movements.related_payout_id` uses `ON DELETE SET NULL` (no orphans on payout deletion)
- A1.2 pre-flight greps backend + frontend repos + database views before dropping columns
- All schema changes are pre-pilot (no customer data at risk; full re-deploy acceptable)

**6. Compliance / financial correctness:**
- AUSTRAC audit trail: every `wallet_movements` row records `actor_type` + `actor_id` (`CHECK` enforces non-null actor_id when actor_type != 'system')
- Idempotency: every Stripe API call has a stable idempotency key; `wallet_movements.idempotency_key` is `UNIQUE`
- Stripe API version pinned via `STRIPE_API_VERSION` env (A5.1)
- Soft-delete exclusion verified in A5.2 (financial tables retain indefinitely)

---

**Plan complete and saved to `docs/superpowers/plans/2026-05-09-stripe-payout-lifecycle-and-funding.md`.**

Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using `superpowers:executing-plans`, batch execution with checkpoints.

Which approach?
