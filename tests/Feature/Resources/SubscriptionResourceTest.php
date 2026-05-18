<?php

use App\Http\Resources\SubscriptionResource;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use Illuminate\Http\Request;

it('includes plan data when the plan relation is loaded', function () {
    $plan = new Plan;
    $plan->setRawAttributes([
        'id' => 'plan-1',
        'plan_key' => 'pro_monthly',
        'name' => 'Pro Monthly',
        'price_cents' => 4900,
        'currency_code' => 'AUD',
        'billing_interval' => 'month',
        'entitlements' => '[]',
    ]);

    $sub = new Subscription;
    $sub->setRawAttributes([
        'id' => 'sub-1',
        'professional_id' => 'pro-1',
        'plan_id' => 'plan-1',
        'provider' => 'stripe',
        'status' => 'active',
        'current_period_start' => now()->toDateTimeString(),
        'current_period_end' => now()->addMonth()->toDateTimeString(),
        'cancel_at_period_end' => 0,
        'ended_at' => null,
        'stripe_subscription_id' => 'sub_abc',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    $sub->setRelation('plan', $plan);

    $array = (new SubscriptionResource($sub))->resolve(Request::create('/'));

    expect($array)
        ->toHaveKey('plan')
        ->and($array['plan'])->toMatchArray([
            'id' => 'plan-1',
            'plan_key' => 'pro_monthly',
            'name' => 'Pro Monthly',
            'price_cents' => 4900,
            'currency_code' => 'AUD',
            'billing_interval' => 'month',
        ]);
});

it('omits plan key when the plan relation is not loaded', function () {
    $sub = new Subscription;
    $sub->setRawAttributes([
        'id' => 'sub-2',
        'professional_id' => 'pro-1',
        'plan_id' => 'plan-1',
        'provider' => 'internal',
        'status' => 'active',
        'current_period_start' => now()->toDateTimeString(),
        'current_period_end' => now()->addMonth()->toDateTimeString(),
        'cancel_at_period_end' => 0,
        'ended_at' => null,
        'stripe_subscription_id' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    // plan relation deliberately NOT loaded

    // resolve() runs the MissingValue filter pass that toArray() skips
    $array = (new SubscriptionResource($sub))->resolve(Request::create('/'));

    // whenLoaded means the key is absent rather than silently lazy-loading
    expect($array)->not->toHaveKey('plan');
});
