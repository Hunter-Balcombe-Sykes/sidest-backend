<?php

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Policies\SubscriptionPolicy;

beforeEach(function () {
    $this->policy = new SubscriptionPolicy;
});

// --- view ---

it('allows view when the actor owns the subscription', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $subscription = new Subscription(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $subscription))->toBeTrue();
});

it('denies view with 404 when the actor does not own the subscription', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $subscription = new Subscription(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $subscription);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('allows view when the actor is pending deletion but owns the subscription', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $subscription = new Subscription(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $subscription))->toBeTrue();
});

// --- update ---

it('allows update when the actor owns the subscription and is active', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $subscription = new Subscription(['professional_id' => 'pro-1']);

    expect($this->policy->update($actor, $subscription))->toBeTrue();
});

it('denies update with 404 when the actor does not own the subscription', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $subscription = new Subscription(['professional_id' => 'pro-2']);

    $result = $this->policy->update($actor, $subscription);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies update with 423 when the actor is pending deletion', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $subscription = new Subscription(['professional_id' => 'pro-1']);

    $result = $this->policy->update($actor, $subscription);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

// --- delete (delegates to update) ---

it('allows delete when the actor owns the subscription', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $subscription = new Subscription(['professional_id' => 'pro-1']);

    expect($this->policy->delete($actor, $subscription))->toBeTrue();
});

it('denies delete with 404 when the actor does not own the subscription', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $subscription = new Subscription(['professional_id' => 'pro-2']);

    $result = $this->policy->delete($actor, $subscription);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies delete with 423 when the actor is pending deletion', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $subscription = new Subscription(['professional_id' => 'pro-1']);

    $result = $this->policy->delete($actor, $subscription);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});
