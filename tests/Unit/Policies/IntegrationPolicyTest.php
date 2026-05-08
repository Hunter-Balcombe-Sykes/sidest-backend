<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Policies\IntegrationPolicy;
use App\Services\Store\BrandAccessService;

beforeEach(function () {
    $this->brandAccess = Mockery::mock(BrandAccessService::class);
    $this->policy = new IntegrationPolicy($this->brandAccess);
});

it('allows view when the actor owns the integration', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-1', 'provider' => 'fresha']);

    $this->brandAccess->shouldReceive('canManageShopify')->never();

    expect($this->policy->view($actor, $integration))->toBeTrue();
});

it('denies view when the actor does not own the integration and is not a brand team member', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-2', 'provider' => 'fresha']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'pro-2')
        ->andReturn(false);

    expect($this->policy->view($actor, $integration))->toBeFalse();
});

it('allows view when the actor is a brand team member with manage capability', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'brand-9', 'provider' => 'shopify']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'brand-9')
        ->andReturn(true);

    expect($this->policy->view($actor, $integration))->toBeTrue();
});

it('denies view with 404 when the integration has no professional_id', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => null, 'provider' => 'fresha']);

    $result = $this->policy->view($actor, $integration);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(404);
});

it('allows manage when the actor owns the integration and is active', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-1', 'provider' => 'fresha']);

    expect($this->policy->manage($actor, $integration))->toBeTrue();
});

it('denies manage with a 423 deny response when the actor is pending deletion', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'pending_deletion', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-1', 'provider' => 'fresha']);

    $result = $this->policy->manage($actor, $integration);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('denies manage when the actor is not the owner and lacks brand-team capability', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'pro-2', 'provider' => 'fresha']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'pro-2')
        ->andReturn(false);

    expect($this->policy->manage($actor, $integration))->toBeFalse();
});

it('allows manage when the actor is a brand team member with manage capability', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    $integration = new ProfessionalIntegration(['professional_id' => 'brand-9', 'provider' => 'shopify']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'brand-9')
        ->andReturn(true);

    expect($this->policy->manage($actor, $integration))->toBeTrue();
});

it('denies manage on an unsaved integration skeleton when caller has no claim on the target owner', function () {
    $actor = (new Professional)->forceFill(['id' => 'pro-1', 'status' => 'active', 'professional_type' => 'professional']);
    // "Connect" flow: caller passes an unsaved skeleton with professional_id set.
    $skeleton = new ProfessionalIntegration(['professional_id' => 'brand-9', 'provider' => 'shopify']);

    $this->brandAccess->shouldReceive('canManageShopify')
        ->with(Mockery::on(fn ($p) => $p->id === 'pro-1'), 'brand-9')
        ->andReturn(false);

    expect($this->policy->manage($actor, $skeleton))->toBeFalse();
});
