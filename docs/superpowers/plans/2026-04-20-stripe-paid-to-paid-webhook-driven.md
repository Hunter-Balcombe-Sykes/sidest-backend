# Stripe Paid→Paid Plan Change: Webhook-Driven Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the local DB write from the paid→paid plan-change path so Stripe's `customer.subscription.updated` webhook becomes the sole source of truth for `plan_id`, eliminating the Stripe-success + DB-failure inconsistency window.

**Architecture:** `ChangeProfessionalPlanAction` currently calls Stripe then immediately writes `plan_id` locally. We drop that local write entirely — the existing `handleSubscriptionUpdated()` webhook handler already detects price changes and updates `plan_id` correctly. The action returns the current (pre-update) subscription; the caller should treat the plan change as in-flight until the webhook lands.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Mockery, SQLite in-memory (tests)

---

### Task 1: Write failing tests for `ChangeProfessionalPlanAction` paid→paid path

**Files:**
- Create: `tests/Feature/Billing/ChangeProfessionalPlanActionTest.php`

- [ ] **Step 1: Create the test file with the paid→paid scenario**

```php
<?php

use App\Actions\Subscription\ChangeProfessionalPlanAction;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;

// Shared factory helpers used across tests
function makeProfessional(string $type = 'affiliate'): Professional
{
    return Professional::factory()->create(['professional_type' => $type]);
}

function makePlan(string $key, string $priceId = 'price_abc'): Plan
{
    return Plan::factory()->create([
        'plan_key' => $key,
        'stripe_price_id' => $priceId,
        'is_active' => true,
    ]);
}

function makeActiveSubscription(Professional $professional, Plan $plan, string $stripeSubId = 'sub_test123'): Subscription
{
    return Subscription::factory()->create([
        'professional_id' => $professional->id,
        'plan_id' => $plan->id,
        'stripe_subscription_id' => $stripeSubId,
        'status' => 'active',
        'ended_at' => null,
    ]);
}
```

- [ ] **Step 2: Add the core paid→paid test — verifies NO local plan_id write occurs**

Append to `ChangeProfessionalPlanActionTest.php`:

```php
it('paid→paid: calls Stripe updateSubscriptionPlan and does NOT update plan_id locally', function () {
    $professional = makeProfessional();
    $currentPlan = makePlan('starter', 'price_starter');
    $newPlan     = makePlan('growth', 'price_growth');
    $subscription = makeActiveSubscription($professional, $currentPlan);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('updateSubscriptionPlan')
        ->once()
        ->with($subscription->stripe_subscription_id, Mockery::on(fn ($p) => $p->id === $newPlan->id));

    $action = new ChangeProfessionalPlanAction($billing);
    $result = $action->execute($professional, ['plan_id' => $newPlan->id]);

    // plan_id must NOT have been written locally
    expect($subscription->fresh()->plan_id)->toBe($currentPlan->id);

    // Action returns the (unmodified) subscription
    expect($result)->toBeInstanceOf(Subscription::class);
    expect($result->id)->toBe($subscription->id);
});
```

- [ ] **Step 3: Add test verifying `cancel_at_period_end` is NOT reset locally either**

```php
it('paid→paid: does not reset cancel_at_period_end locally', function () {
    $professional = makeProfessional();
    $currentPlan  = makePlan('starter', 'price_starter');
    $newPlan      = makePlan('growth', 'price_growth');
    $subscription = makeActiveSubscription($professional, $currentPlan);
    $subscription->update(['cancel_at_period_end' => true]);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('updateSubscriptionPlan')->once();

    $action = new ChangeProfessionalPlanAction($billing);
    $action->execute($professional, ['plan_id' => $newPlan->id]);

    // cancel_at_period_end untouched — webhook will reconcile it
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();
});
```

- [ ] **Step 4: Run tests to confirm they fail**

```bash
cd /Users/joshuahunter/Herd/Side\ Street/backend
php artisan test tests/Feature/Billing/ChangeProfessionalPlanActionTest.php
```

Expected: both tests fail (action currently writes `plan_id` locally, so the `plan_id` assertion fails).

---

### Task 2: Implement the fix in `ChangeProfessionalPlanAction`

**Files:**
- Modify: `app/Actions/Subscription/ChangeProfessionalPlanAction.php:81-92`

- [ ] **Step 1: Replace the paid→paid block**

Current code (lines 81–92):

```php
// Paid -> Paid: update the price on the existing Stripe subscription
$this->billing->updateSubscriptionPlan(
    $subscription->stripe_subscription_id,
    $newPlan,
);

$subscription->update([
    'plan_id' => $newPlan->id,
    'cancel_at_period_end' => false,
]);

return $subscription->fresh();
```

Replace with:

```php
// Paid -> Paid: update price on Stripe; customer.subscription.updated webhook
// reconciles plan_id and cancel_at_period_end locally (same as paid->free path).
$this->billing->updateSubscriptionPlan(
    $subscription->stripe_subscription_id,
    $newPlan,
);

return $subscription->fresh();
```

- [ ] **Step 2: Run the tests**

```bash
php artisan test tests/Feature/Billing/ChangeProfessionalPlanActionTest.php
```

Expected: both tests pass.

---

### Task 3: Verify the webhook handler covers the plan_id update

`handleSubscriptionUpdated()` already handles this (lines 164–171 of `StripeWebhookController`), but we confirm the logic with a targeted test.

**Files:**
- Create: `tests/Feature/Stripe/StripeWebhookSubscriptionUpdatedTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Http\Controllers\Api\Webhooks\StripeWebhookController;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;

it('subscription.updated webhook updates plan_id when price changes', function () {
    $professional = Professional::factory()->create();
    $oldPlan = Plan::factory()->create(['stripe_price_id' => 'price_old', 'is_active' => true]);
    $newPlan = Plan::factory()->create(['stripe_price_id' => 'price_new', 'is_active' => true]);

    $subscription = Subscription::factory()->create([
        'professional_id' => $professional->id,
        'plan_id' => $oldPlan->id,
        'stripe_subscription_id' => 'sub_abc',
        'status' => 'active',
        'ended_at' => null,
    ]);

    // Build a minimal Stripe subscription object matching what the handler reads
    $stripeSubscription = (object) [
        'id' => 'sub_abc',
        'status' => 'active',
        'current_period_start' => now()->timestamp,
        'current_period_end' => now()->addMonth()->timestamp,
        'cancel_at_period_end' => false,
        'items' => (object) [
            'data' => [
                (object) [
                    'price' => (object) ['id' => 'price_new'],
                ],
            ],
        ],
    ];

    $event = (object) ['type' => 'customer.subscription.updated'];

    // Call the private handler via reflection (no HTTP overhead)
    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handleSubscriptionUpdated');
    $method->setAccessible(true);
    $method->invoke($controller, $stripeSubscription, $event);

    expect($subscription->fresh()->plan_id)->toBe($newPlan->id);
});

it('subscription.updated webhook leaves plan_id unchanged when price is same', function () {
    $professional = Professional::factory()->create();
    $plan = Plan::factory()->create(['stripe_price_id' => 'price_same', 'is_active' => true]);

    $subscription = Subscription::factory()->create([
        'professional_id' => $professional->id,
        'plan_id' => $plan->id,
        'stripe_subscription_id' => 'sub_xyz',
        'status' => 'active',
        'ended_at' => null,
    ]);

    $stripeSubscription = (object) [
        'id' => 'sub_xyz',
        'status' => 'active',
        'current_period_start' => now()->timestamp,
        'current_period_end' => now()->addMonth()->timestamp,
        'cancel_at_period_end' => false,
        'items' => (object) [
            'data' => [
                (object) [
                    'price' => (object) ['id' => 'price_same'],
                ],
            ],
        ],
    ];

    $event = (object) ['type' => 'customer.subscription.updated'];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handleSubscriptionUpdated');
    $method->setAccessible(true);
    $method->invoke($controller, $stripeSubscription, $event);

    expect($subscription->fresh()->plan_id)->toBe($plan->id);
});
```

- [ ] **Step 2: Run the new webhook tests**

```bash
php artisan test tests/Feature/Stripe/StripeWebhookSubscriptionUpdatedTest.php
```

Expected: both pass (handler already correct).

- [ ] **Step 3: Run the full suite to catch regressions**

```bash
composer test
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add \
  app/Actions/Subscription/ChangeProfessionalPlanAction.php \
  tests/Feature/Billing/ChangeProfessionalPlanActionTest.php \
  tests/Feature/Stripe/StripeWebhookSubscriptionUpdatedTest.php

git commit -m "fix(billing): delegate paid→paid plan_id update to subscription.updated webhook

Removes the immediate local DB write after updateSubscriptionPlan() — the
customer.subscription.updated webhook already reconciles plan_id when the
Stripe price changes, matching the pattern used by the paid→free path.
Eliminates the Stripe-success + DB-failure inconsistency window."
```
