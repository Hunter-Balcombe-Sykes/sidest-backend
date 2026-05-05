<?php

use App\Models\Billing\Subscription;

it('has correct status constants', function () {
    expect(Subscription::STATUS_ACTIVE)->toBe('active');
    expect(Subscription::STATUS_PAST_DUE)->toBe('past_due');
    expect(Subscription::STATUS_UNPAID)->toBe('unpaid');
    expect(Subscription::STATUS_CANCELED)->toBe('canceled');
    expect(Subscription::STATUS_INCOMPLETE)->toBe('incomplete');
    expect(Subscription::STATUS_INCOMPLETE_EXPIRED)->toBe('incomplete_expired');
});

it('has correct grace statuses', function () {
    expect(Subscription::GRACE_STATUSES)->toBe(['active', 'past_due']);
});

it('reports active for active status with no ended_at', function () {
    $sub = new Subscription;
    $sub->status = Subscription::STATUS_ACTIVE;

    expect($sub->isActive())->toBeTrue();
    expect($sub->isInGracePeriod())->toBeTrue();
});

it('reports not active for non-active status', function () {
    $sub = new Subscription;
    $sub->status = Subscription::STATUS_PAST_DUE;

    expect($sub->isActive())->toBeFalse();
});

it('reports grace period for past_due within grace window', function () {
    $sub = new Subscription;
    // setRawAttributes bypasses the datetime cast setter, which would require a DB connection.
    $sub->setRawAttributes(['status' => Subscription::STATUS_PAST_DUE, 'current_period_end' => now()->subDays(3)]);

    expect($sub->isInGracePeriod())->toBeTrue();
});

it('revokes grace period for past_due beyond grace window', function () {
    $sub = new Subscription;
    $sub->setRawAttributes(['status' => Subscription::STATUS_PAST_DUE, 'current_period_end' => now()->subDays(8)]);

    expect($sub->isInGracePeriod())->toBeFalse();
});

it('revokes grace period for past_due with no period end', function () {
    $sub = new Subscription;
    $sub->status = Subscription::STATUS_PAST_DUE;

    expect($sub->isInGracePeriod())->toBeFalse();
});

it('reports no grace period for unpaid', function () {
    $sub = new Subscription;
    $sub->status = Subscription::STATUS_UNPAID;

    expect($sub->isInGracePeriod())->toBeFalse();
});

it('identifies stripe managed subscriptions', function () {
    $sub = new Subscription;
    $sub->provider = 'stripe';

    expect($sub->isStripeManaged())->toBeTrue();
    expect($sub->isFreeInternal())->toBeFalse();
});

it('identifies free internal subscriptions', function () {
    $sub = new Subscription;
    $sub->provider = 'internal';

    expect($sub->isFreeInternal())->toBeTrue();
    expect($sub->isStripeManaged())->toBeFalse();
});
