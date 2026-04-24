<?php

use App\Http\Controllers\Api\Webhooks\StripeWebhookController;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// -------------------------------------------------------------------
// Schema bootstrap
// -------------------------------------------------------------------

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.plans (
        id TEXT PRIMARY KEY,
        plan_key TEXT NOT NULL,
        name TEXT NULL,
        description TEXT NULL,
        stripe_price_id TEXT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NULL,
        price_cents INTEGER NULL,
        currency_code TEXT NULL,
        billing_interval TEXT NULL,
        entitlements TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        plan_id TEXT NOT NULL,
        provider TEXT NOT NULL DEFAULT \'stripe\',
        stripe_customer_id TEXT NULL,
        stripe_subscription_id TEXT NULL,
        status TEXT NOT NULL DEFAULT \'active\',
        current_period_start TEXT NULL,
        current_period_end TEXT NULL,
        cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
        trial_ends_at TEXT NULL,
        ended_at TEXT NULL,
        provider_payload TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

// -------------------------------------------------------------------
// Shared factory helpers (whTest* prefix to avoid global collisions)
// -------------------------------------------------------------------

function whTestProfessional(): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "pro-{$id}",
        'handle_lc' => "pro-{$id}",
        'display_name' => "Test Pro {$id}",
        'professional_type' => 'affiliate',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($id);
}

function whTestPlan(string $key, string $priceId): Plan
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('billing.plans')->insert([
        'id' => $id,
        'plan_key' => $key,
        'name' => ucfirst($key),
        'stripe_price_id' => $priceId,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Plan::find($id);
}

function whTestSubscription(Professional $professional, Plan $plan, string $stripeSubId): Subscription
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('billing.subscriptions')->insert([
        'id' => $id,
        'professional_id' => $professional->id,
        'plan_id' => $plan->id,
        'provider' => 'stripe',
        'stripe_subscription_id' => $stripeSubId,
        'status' => 'active',
        'cancel_at_period_end' => 0,
        'ended_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Subscription::find($id);
}

// -------------------------------------------------------------------
// Tests
// -------------------------------------------------------------------

it('subscription.updated webhook updates plan_id when price changes', function () {
    $professional = whTestProfessional();
    $oldPlan = whTestPlan('starter', 'price_old');
    $newPlan = whTestPlan('growth', 'price_new');
    $subscription = whTestSubscription($professional, $oldPlan, 'sub_abc');

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

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handleSubscriptionUpdated');
    $method->setAccessible(true);
    $method->invoke($controller, $stripeSubscription, $event);

    expect($subscription->fresh()->plan_id)->toBe($newPlan->id);
});

it('subscription.updated webhook leaves plan_id unchanged when price is same', function () {
    $professional = whTestProfessional();
    $plan = whTestPlan('starter', 'price_same');
    $subscription = whTestSubscription($professional, $plan, 'sub_xyz');

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

    // plan_id must not change when the price already matches
    expect($subscription->fresh()->plan_id)->toBe($plan->id);
});
