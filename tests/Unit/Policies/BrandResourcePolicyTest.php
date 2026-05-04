<?php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use App\Policies\BrandResourcePolicy;

beforeEach(function () {
    $this->policy = new BrandResourcePolicy;
});

// --- view ---

it('allows view when the actor owns the settings', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $settings = new BrandStoreSettings(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $settings))->toBeTrue();
});

it('denies view with 404 when the actor does not own the settings', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $settings = new BrandStoreSettings(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $settings);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// --- create ---

it('allows create when the actor owns the skeleton and is active', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $skeleton = new BrandStoreSettings(['professional_id' => 'pro-1']);

    expect($this->policy->create($actor, $skeleton))->toBeTrue();
});

it('denies create as false (not 404) when the skeleton targets another professional', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $skeleton = new BrandStoreSettings(['professional_id' => 'pro-other']);

    expect($this->policy->create($actor, $skeleton))->toBeFalse();
});

it('denies create with 423 when the actor is pending deletion', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $skeleton = new BrandStoreSettings(['professional_id' => 'pro-1']);

    $result = $this->policy->create($actor, $skeleton);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

// --- update ---

it('allows update when the actor owns the settings and is active', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $settings = new BrandStoreSettings(['professional_id' => 'pro-1']);

    expect($this->policy->update($actor, $settings))->toBeTrue();
});

it('denies update with 404 when the actor does not own the settings', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $settings = new BrandStoreSettings(['professional_id' => 'pro-2']);

    $result = $this->policy->update($actor, $settings);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies update with 423 when the actor is pending deletion', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $settings = new BrandStoreSettings(['professional_id' => 'pro-1']);

    $result = $this->policy->update($actor, $settings);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

// --- delete (delegates to update) ---

it('allows delete when the actor owns the settings', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $settings = new BrandStoreSettings(['professional_id' => 'pro-1']);

    expect($this->policy->delete($actor, $settings))->toBeTrue();
});

it('denies delete with 404 when the actor does not own the settings', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $settings = new BrandStoreSettings(['professional_id' => 'pro-2']);

    $result = $this->policy->delete($actor, $settings);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies delete with 423 when the actor is pending deletion', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $settings = new BrandStoreSettings(['professional_id' => 'pro-1']);

    $result = $this->policy->delete($actor, $settings);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});
