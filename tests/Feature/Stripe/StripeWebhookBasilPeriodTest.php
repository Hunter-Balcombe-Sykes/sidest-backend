<?php

use App\Http\Controllers\Api\Webhooks\StripeWebhookController;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Exercises the defensive read in resolveSubscriptionPeriod for the Stripe Basil
// 2025-03-31 API change. Pre-Basil shape exposes current_period_start/end on the
// Subscription resource; Basil-and-later shape moves them to items.data[].

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    // setupProfessionalsTable doesn't include stripe billing columns — add them so
    // the customer-lookup path in handleSubscriptionCreated can resolve a Professional.
    try {
        $conn->statement('ALTER TABLE core.professionals ADD COLUMN stripe_customer_id TEXT');
    } catch (\Throwable) {
        // Column may already exist if the helper added it.
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.plans (
        id TEXT PRIMARY KEY,
        plan_key TEXT NOT NULL,
        name TEXT NULL,
        stripe_price_id TEXT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
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

function basilTestProfessional(string $stripeCustomerId): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "pro-{$id}",
        'handle_lc' => "pro-{$id}",
        'display_name' => "Test Pro {$id}",
        'professional_type' => 'professional',
        'status' => 'active',
        'stripe_customer_id' => $stripeCustomerId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($id);
}

function basilTestPlan(string $priceId): Plan
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('billing.plans')->insert([
        'id' => $id,
        'plan_key' => 'starter',
        'name' => 'Starter',
        'stripe_price_id' => $priceId,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Plan::find($id);
}

it('reads period fields from items[0] on Basil-shape subscriptions', function () {
    $professional = basilTestProfessional('cus_basil');
    basilTestPlan('price_basil');

    $start = now()->startOfMonth()->timestamp;
    $end = now()->endOfMonth()->timestamp;

    // Basil shape: period fields are on items.data[0], NOT at the subscription level.
    $stripeSubscription = (object) [
        'id' => 'sub_basil',
        'customer' => 'cus_basil',
        'status' => 'active',
        'cancel_at_period_end' => false,
        'metadata' => (object) ['sidest_professional_id' => $professional->id],
        'items' => (object) [
            'data' => [
                (object) [
                    'price' => (object) ['id' => 'price_basil'],
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                ],
            ],
        ],
        // Deliberately NOT setting current_period_start/end at top level
    ];
    $event = (object) ['type' => 'customer.subscription.created'];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handleSubscriptionCreated');
    $method->setAccessible(true);
    $method->invoke($controller, $stripeSubscription, $event);

    $sub = Subscription::where('stripe_subscription_id', 'sub_basil')->first();
    expect($sub)->not->toBeNull()
        ->and($sub->current_period_start?->timestamp)->toBe($start)
        ->and($sub->current_period_end?->timestamp)->toBe($end);
});

it('falls back to top-level period fields on pre-Basil shape', function () {
    $professional = basilTestProfessional('cus_pre_basil');
    basilTestPlan('price_pre_basil');

    $start = now()->startOfMonth()->timestamp;
    $end = now()->endOfMonth()->timestamp;

    // Pre-Basil shape: period fields at the subscription level.
    $stripeSubscription = (object) [
        'id' => 'sub_pre_basil',
        'customer' => 'cus_pre_basil',
        'status' => 'active',
        'current_period_start' => $start,
        'current_period_end' => $end,
        'cancel_at_period_end' => false,
        'metadata' => (object) ['sidest_professional_id' => $professional->id],
        'items' => (object) [
            'data' => [
                (object) [
                    'price' => (object) ['id' => 'price_pre_basil'],
                    // No current_period_* on the item
                ],
            ],
        ],
    ];
    $event = (object) ['type' => 'customer.subscription.created'];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handleSubscriptionCreated');
    $method->setAccessible(true);
    $method->invoke($controller, $stripeSubscription, $event);

    $sub = Subscription::where('stripe_subscription_id', 'sub_pre_basil')->first();
    expect($sub)->not->toBeNull()
        ->and($sub->current_period_start?->timestamp)->toBe($start)
        ->and($sub->current_period_end?->timestamp)->toBe($end);
});

it('logs and returns without throwing when both shapes are missing period fields', function () {
    $professional = basilTestProfessional('cus_missing');
    basilTestPlan('price_missing');

    $stripeSubscription = (object) [
        'id' => 'sub_missing',
        'customer' => 'cus_missing',
        'status' => 'active',
        'cancel_at_period_end' => false,
        'metadata' => (object) ['sidest_professional_id' => $professional->id],
        'items' => (object) [
            'data' => [
                (object) [
                    'price' => (object) ['id' => 'price_missing'],
                ],
            ],
        ],
    ];
    $event = (object) ['type' => 'customer.subscription.created'];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handleSubscriptionCreated');
    $method->setAccessible(true);

    // Must not throw — the defensive read returns null and the handler logs+bails.
    $method->invoke($controller, $stripeSubscription, $event);

    // No subscription row should have been created since we bailed before insert.
    expect(Subscription::where('stripe_subscription_id', 'sub_missing')->exists())->toBeFalse();
});

it('subscription updated handler delegates to created when no local row exists (race)', function () {
    $professional = basilTestProfessional('cus_race');
    basilTestPlan('price_race');

    $start = now()->startOfMonth()->timestamp;
    $end = now()->endOfMonth()->timestamp;

    // .updated arrives before .created has committed locally — should upsert.
    $stripeSubscription = (object) [
        'id' => 'sub_race_unknown',
        'customer' => 'cus_race',
        'status' => 'active',
        'cancel_at_period_end' => false,
        'metadata' => (object) ['sidest_professional_id' => $professional->id],
        'items' => (object) [
            'data' => [
                (object) [
                    'price' => (object) ['id' => 'price_race'],
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                ],
            ],
        ],
    ];
    $event = (object) ['type' => 'customer.subscription.updated'];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handleSubscriptionUpdated');
    $method->setAccessible(true);
    $method->invoke($controller, $stripeSubscription, $event);

    expect(Subscription::where('stripe_subscription_id', 'sub_race_unknown')->exists())->toBeTrue();
});

it('downgrades trialing subscriptions to incomplete and logs (STRP-H — no throw)', function () {
    // STRP-H: previously this handler threw a LogicException when Stripe sent trialing
    // status. Combined with the ack-before-process anti-pattern (now fixed by the
    // STRP-C delete-on-failure trait), throwing silenced the event and left the local
    // subscription unsynced forever. New behavior: log loudly for Nightwatch alerting
    // and map trialing → STATUS_INCOMPLETE so no entitlements are granted; a later
    // subscription.updated with the real status promotes the row at that point.
    $professional = basilTestProfessional('cus_trial');
    basilTestPlan('price_trial');

    $start = now()->startOfMonth()->timestamp;
    $end = now()->endOfMonth()->timestamp;

    $stripeSubscription = (object) [
        'id' => 'sub_trial',
        'customer' => 'cus_trial',
        'status' => 'trialing',
        'cancel_at_period_end' => false,
        'metadata' => (object) ['sidest_professional_id' => $professional->id],
        'items' => (object) [
            'data' => [(object) [
                'price' => (object) ['id' => 'price_trial'],
                'current_period_start' => $start,
                'current_period_end' => $end,
            ]],
        ],
    ];
    $event = (object) ['type' => 'customer.subscription.created'];

    \Illuminate\Support\Facades\Log::spy();

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handleSubscriptionCreated');
    $method->setAccessible(true);

    // No throw — the handler completes and persists the subscription as 'incomplete'.
    $method->invoke($controller, $stripeSubscription, $event);

    $localSub = \App\Models\Billing\Subscription::where('stripe_subscription_id', 'sub_trial')->first();
    expect($localSub)->not->toBeNull()
        ->and($localSub->status)->toBe(\App\Models\Billing\Subscription::STATUS_INCOMPLETE);

    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'unexpected_trialing_status'))
        ->once();
});
