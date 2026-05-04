<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalConfirmationPreference;
use App\Models\Core\Professional\WalletCurrencySwitchAudit;
use App\Policies\ProfessionalSelfPolicy;

beforeEach(function () {
    $this->policy = new ProfessionalSelfPolicy;
});

// --- view ---

it('allows view when the actor owns a ProfessionalConfirmationPreference', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new ProfessionalConfirmationPreference)->forceFill(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $pref))->toBeTrue();
});

it('denies view with 404 when the actor does not own a ProfessionalConfirmationPreference', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new ProfessionalConfirmationPreference)->forceFill(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $pref);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('allows view when the actor owns a WalletCurrencySwitchAudit', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $audit = (new WalletCurrencySwitchAudit)->forceFill(['professional_id' => 'pro-1']);

    expect($this->policy->view($actor, $audit))->toBeTrue();
});

it('denies view with 404 when the actor does not own a WalletCurrencySwitchAudit', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $audit = (new WalletCurrencySwitchAudit)->forceFill(['professional_id' => 'pro-2']);

    $result = $this->policy->view($actor, $audit);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

// --- update ---

it('allows update when the actor owns the resource and is active', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new ProfessionalConfirmationPreference)->forceFill(['professional_id' => 'pro-1']);

    expect($this->policy->update($actor, $pref))->toBeTrue();
});

it('denies update with 404 when the actor does not own the resource', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new ProfessionalConfirmationPreference)->forceFill(['professional_id' => 'pro-2']);

    $result = $this->policy->update($actor, $pref);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('denies update with 423 when the actor is pending deletion', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion']);
    $pref = (new ProfessionalConfirmationPreference)->forceFill(['professional_id' => 'pro-1']);

    $result = $this->policy->update($actor, $pref);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

// --- delete (delegates to update) ---

it('allows delete when the actor owns the resource', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new ProfessionalConfirmationPreference)->forceFill(['professional_id' => 'pro-1']);

    expect($this->policy->delete($actor, $pref))->toBeTrue();
});

it('denies delete with 404 when the actor does not own the resource', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active']);
    $pref = (new ProfessionalConfirmationPreference)->forceFill(['professional_id' => 'pro-2']);

    $result = $this->policy->delete($actor, $pref);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});
