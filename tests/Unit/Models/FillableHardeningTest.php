<?php

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\BrandTeamMembership;
use App\Models\Retail\CommissionMovement;
use App\Models\Retail\CommissionPayout;

// Subscription — Stripe provider IDs must not be mass-assignable
it('does not allow stripe_customer_id to be mass-assigned on Subscription', function () {
    expect((new Subscription)->getFillable())->not->toContain('stripe_customer_id');
});

it('does not allow stripe_subscription_id to be mass-assigned on Subscription', function () {
    expect((new Subscription)->getFillable())->not->toContain('stripe_subscription_id');
});

// Plan — primary key must not be mass-assignable
it('does not allow id to be mass-assigned on Plan', function () {
    expect((new Plan)->getFillable())->not->toContain('id');
});

// BrandStoreSettings — sensitive deployment token must not be mass-assignable
it('does not allow oxygen_deployment_token to be mass-assigned on BrandStoreSettings', function () {
    expect((new BrandStoreSettings)->getFillable())->not->toContain('oxygen_deployment_token');
});

// DataExportAudit — server-controlled timestamps must not be mass-assignable
it('does not allow created_at to be mass-assigned on DataExportAudit', function () {
    expect((new DataExportAudit)->getFillable())->not->toContain('created_at');
});

it('does not allow completed_at to be mass-assigned on DataExportAudit', function () {
    expect((new DataExportAudit)->getFillable())->not->toContain('completed_at');
});

// ProfessionalDeletionAuditEntry — server-controlled timestamp must not be mass-assignable
it('does not allow created_at to be mass-assigned on ProfessionalDeletionAuditEntry', function () {
    expect((new ProfessionalDeletionAuditEntry)->getFillable())->not->toContain('created_at');
});

// CommissionPayout — fully guarded; no mass assignment
it('guards all fields on CommissionPayout', function () {
    $model = new CommissionPayout;
    expect($model->getGuarded())->toBe(['*']);
    expect($model->getFillable())->toBe([]);
});

// CommissionMovement — fully guarded; no mass assignment
it('guards all fields on CommissionMovement', function () {
    $model = new CommissionMovement;
    expect($model->getGuarded())->toBe(['*']);
    expect($model->getFillable())->toBe([]);
});

// BrandTeamMembership — fully guarded; no mass assignment
it('guards all fields on BrandTeamMembership', function () {
    $model = new BrandTeamMembership;
    expect($model->getGuarded())->toBe(['*']);
    expect($model->getFillable())->toBe([]);
});
