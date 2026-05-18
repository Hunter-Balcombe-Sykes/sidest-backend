<?php

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Brand\BrandTeamMembership;
use App\Models\Commerce\CommissionMovement;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Staff\PartnaStaff;

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

// PartnaStaff — role must not be mass-assignable (privilege-escalation guard, SEC-1).
// Role transitions go through promoteToAdmin() / demoteToSupport().
it('does not allow role to be mass-assigned on PartnaStaff', function () {
    expect((new PartnaStaff)->getFillable())->not->toContain('role');
});

it('rejects role via fill() on PartnaStaff', function () {
    $staff = (new PartnaStaff)->forceFill(['role' => PartnaStaff::ROLE_SUPPORT]);
    $staff->fill(['role' => PartnaStaff::ROLE_ADMIN, 'name' => 'New Name']);

    expect($staff->role)->toBe(PartnaStaff::ROLE_SUPPORT);
    expect($staff->name)->toBe('New Name');
});

// PartnaStaff — PII fields hidden from serialization (SEC-2).
it('hides primary_email, name, phone, auth_user_id from PartnaStaff::toArray()', function () {
    $staff = (new PartnaStaff)->forceFill([
        'id' => 'staff-1',
        'role' => PartnaStaff::ROLE_ADMIN,
        'auth_user_id' => 'auth-uuid',
        'primary_email' => 'admin@partna.test',
        'name' => 'Test Admin',
        'phone' => '+61400000000',
    ]);

    $array = $staff->toArray();

    expect($array)->not->toHaveKey('primary_email');
    expect($array)->not->toHaveKey('name');
    expect($array)->not->toHaveKey('phone');
    expect($array)->not->toHaveKey('auth_user_id');
    expect($array)->toHaveKey('id');
    expect($array)->toHaveKey('role');
});

// PartnaStaff — promoteToAdmin / demoteToSupport are the sanctioned role-transition methods (SEC-1).
it('exposes promoteToAdmin and demoteToSupport as the sanctioned role transition path', function () {
    expect(method_exists(PartnaStaff::class, 'promoteToAdmin'))->toBeTrue();
    expect(method_exists(PartnaStaff::class, 'demoteToSupport'))->toBeTrue();
    expect(PartnaStaff::ROLE_ADMIN)->toBe('admin');
    expect(PartnaStaff::ROLE_SUPPORT)->toBe('support');
});
