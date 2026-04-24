<?php

use App\Http\Controllers\Api\Professional\SubscriptionController;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        plan_id TEXT,
        stripe_subscription_id TEXT,
        provider TEXT,
        status TEXT,
        current_period_start TEXT,
        current_period_end TEXT,
        trial_ends_at TEXT,
        ended_at TEXT,
        cancel_at_period_end INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('subscription show only returns the callers own active subscription', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    $aStripeId = 'sub_'.substr($a->id, 0, 8);
    $bStripeId = 'sub_'.substr($b->id, 0, 8);

    DB::table('billing.subscriptions')->insert([
        [
            'id' => (string) Str::uuid(),
            'professional_id' => $a->id,
            'stripe_subscription_id' => $aStripeId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::uuid(),
            'professional_id' => $b->id,
            'stripe_subscription_id' => $bStripeId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $req = tenantRequestAs($b);
    $response = app(SubscriptionController::class)->show($req);

    // show() returns a JsonResource when a subscription is found.
    // Access the underlying Eloquent model directly via ->resource to avoid the plan
    // relationship in toArray() — we only need to verify the professional_id scoping.
    expect($response)->toBeInstanceOf(JsonResource::class);
    $stripeId = $response->resource->stripe_subscription_id;
    expect($stripeId)->toBe($bStripeId);
    expect($stripeId)->not->toBe($aStripeId);
});
