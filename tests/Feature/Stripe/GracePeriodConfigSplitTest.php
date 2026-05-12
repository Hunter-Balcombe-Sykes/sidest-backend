<?php

use App\Services\Stripe\CommissionPayoutService;

// Verifies the grace_period_days config-key split: signup_grace_period_days drives
// affiliate signup grace (StripeConnectService), payout_grace_period_days drives
// per-payout void deadlines (CommissionPayoutService + VoidExpiredPayoutsJob).
// Previously a single key conflated the two — changing the value silently
// misconfigured one or the other.

it('payout service reads payout_grace_period_days, not the legacy key', function () {
    config()->set('partna.store.payout_grace_period_days', 45);
    config()->set('partna.store.grace_period_days', 99); // legacy — must be ignored

    $service = new CommissionPayoutService;

    $ref = new ReflectionClass($service);
    $prop = $ref->getProperty('gracePeriodDays');
    $prop->setAccessible(true);

    expect($prop->getValue($service))->toBe(45);
});

it('payout service falls back to legacy grace_period_days when new key is unset', function () {
    // Truly remove the new key from the config array. config()->offsetUnset() only
    // nulls the key, which Arr::has still treats as present, so config(..., $default)
    // would not fire the fallback. Rebuilding the partna.store array forces it out.
    $store = config('partna.store');
    unset($store['payout_grace_period_days']);
    $store['grace_period_days'] = 77;
    config()->set('partna.store', $store);

    $service = new CommissionPayoutService;

    $ref = new ReflectionClass($service);
    $prop = $ref->getProperty('gracePeriodDays');
    $prop->setAccessible(true);

    expect($prop->getValue($service))->toBe(77);
});

it('clamps the grace period to [1, 365]', function () {
    config()->set('partna.store.payout_grace_period_days', 9999);
    $hi = new CommissionPayoutService;

    $ref = new ReflectionClass($hi);
    $prop = $ref->getProperty('gracePeriodDays');
    $prop->setAccessible(true);
    expect($prop->getValue($hi))->toBe(365);

    config()->set('partna.store.payout_grace_period_days', 0);
    $lo = new CommissionPayoutService;
    expect($prop->getValue($lo))->toBe(1);
});
