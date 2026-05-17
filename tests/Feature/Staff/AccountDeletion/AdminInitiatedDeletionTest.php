<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffAccountDeletionController;
use App\Http\Middleware\Auth\EnsurePartnaAdmin;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\Professional\AccountDeletionService;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

// ─── Fixtures ────────────────────────────────────────────────────────────────

function makeAdminStaff(array $overrides = []): PartnaStaff
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'role' => 'admin',
        'name' => 'Support Admin',
        'primary_email' => 'admin@sidest.test',
    ], $overrides);

    DB::connection('pgsql')->table('core.partna_staff')->insert($data);

    return PartnaStaff::query()->where('id', $id)->first();
}

function makeActiveProfessional(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro User',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($data);

    return Professional::query()->where('id', $id)->first();
}

function makeAdminRequest(PartnaStaff $staff, array $body = []): Request
{
    $request = Request::create('/', 'POST', $body);
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

it('admin can initiate erasure for a clean account', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional();

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'provider' => 'shopify',
        'access_token' => 'shpat_secret',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService;
    $result = $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 request — support ticket #1234',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200)
        ->and($result['deletes_at'])->not->toBeEmpty();

    $pro->refresh();
    expect($pro->status)->toBe('pending_deletion')
        ->and($pro->deletion_confirmed_at)->not->toBeNull();

    $integrationCount = DB::connection('pgsql')->table('core.professional_integrations')
        ->where('professional_id', $pro->id)->count();
    expect($integrationCount)->toBe(0);

    Mail::assertSent(AccountDeletionScheduledMail::class);

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->where('event', 'admin_initiated')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe(ProfessionalDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN)
        ->and($audit->actor_id)->toBe($staff->id)
        ->and($audit->reason)->toBe('GDPR Article 17 request — support ticket #1234');
});

it('admin cannot initiate while another deletion is already in flight', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional(['status' => 'pending_deletion']);

    $service = new AccountDeletionService;
    $result = $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 — ticket #9999',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(409);
});

it('obligations block initiate without override flag', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional();

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $pro->id,
        'brand_professional_id' => (string) Str::uuid(),
        'status' => 'pending',
        'amount_cents' => 5000,
        'created_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService;
    $result = $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 — ticket #0001',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(422)
        ->and($result['reasons'])->toContain('pending_payouts');

    $pro->refresh();
    expect($pro->status)->toBe('active');
});

it('obligations are overridden when explicitly requested and recorded in audit metadata', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional();

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $pro->id,
        'brand_professional_id' => (string) Str::uuid(),
        'status' => 'pending',
        'amount_cents' => 5000,
        'created_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService;
    $result = $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 — ticket #0002, obligations override approved',
        overrideObligations: true,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->where('event', 'admin_initiated')
        ->first();

    $metadata = json_decode($audit->metadata, true);
    expect($metadata['obligations_overridden'])->toContain('pending_payouts');
});

it('reason is required and validated at the form request level', function () {
    $request = new \App\Http\Requests\Api\Staff\StaffInitiateDeletionRequest;

    // min:10 — too short
    $validator = validator(['reason' => 'short'], $request->rules(), $request->messages());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('reason'))->toBeTrue();

    // max:500 — too long
    $validator = validator(['reason' => str_repeat('x', 501)], $request->rules(), $request->messages());
    expect($validator->fails())->toBeTrue();

    // missing
    $validator = validator([], $request->rules(), $request->messages());
    expect($validator->fails())->toBeTrue();

    // valid
    $validator = validator(['reason' => 'GDPR Article 17 — support ticket #1234'], $request->rules(), $request->messages());
    expect($validator->fails())->toBeFalse();
});

it('admin can cancel a pending deletion during grace period', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional([
        'status' => 'pending_deletion',
        'deletion_previous_status' => 'active',
        'deletion_confirmed_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService;
    $result = $service->adminCancel(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'User contacted support to reverse — ticket #5678',
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $pro->refresh();
    expect($pro->status)->toBe('active');

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->where('event', 'admin_cancelled')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe(ProfessionalDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN);
});

it('admin cancel fails with 409 if no pending deletion exists', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional(); // status = 'active', not pending_deletion

    $service = new AccountDeletionService;
    $result = $service->adminCancel(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: null,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(409);
});

it('non-admin staff get 403 from EnsurePartnaAdmin middleware', function () {
    $nonAdminUid = (string) Str::uuid();
    $staffId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => $staffId,
        'auth_user_id' => $nonAdminUid,
        'role' => 'support',
        'name' => 'Support User',
        'primary_email' => 'support@sidest.test',
    ]);

    $nonAdmin = PartnaStaff::query()->where('id', $staffId)->first();

    $request = Request::create('/', 'POST');
    $request->attributes->set('supabase_uid', $nonAdminUid);
    $request->attributes->set('partna_staff', $nonAdmin);

    $middleware = new EnsurePartnaAdmin;
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
});

it('GET show returns deletion state and non-PII audit entries', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional([
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->toIso8601String(),
        'deletion_previous_status' => 'active',
    ]);

    // Seed an audit row with PII fields
    DB::connection('pgsql')->table('core.professional_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'professional_handle_snapshot' => $pro->handle,
        'professional_email_snapshot' => $pro->primary_email,
        'event' => 'admin_initiated',
        'actor_type' => 'staff_admin',
        'actor_id' => $staff->id,
        'actor_handle_snapshot' => 'Support Admin',
        'reason' => 'GDPR Article 17 — ticket #1234',
        'ip_address' => '1.2.3.4',
        'user_agent' => 'TestAgent',
        'created_at' => now()->toIso8601String(),
    ]);

    $controller = new StaffAccountDeletionController(new AccountDeletionService);
    $response = $controller->show($pro);
    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['status'])->toBe('pending_deletion')
        ->and($data['deletes_at'])->not->toBeNull()
        ->and($data['audit_entries'])->toHaveCount(1);

    $entry = $data['audit_entries'][0];
    expect($entry)->toHaveKey('event')
        ->and($entry)->toHaveKey('actor_type')
        ->and($entry)->toHaveKey('reason')
        ->and($entry)->not->toHaveKey('actor_handle_snapshot')
        ->and($entry)->not->toHaveKey('ip_address')
        ->and($entry)->not->toHaveKey('user_agent');
});

it('Stripe cancel-at-period-end is invoked on admin initiate', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional();

    $stripeSubId = 'sub_test_'.Str::random(10);
    DB::connection('pgsql')->table('billing.subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'stripe_subscription_id' => $stripeSubId,
        'status' => 'active',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $mockBilling = Mockery::mock(StripeBillingService::class);
    $mockBilling->shouldReceive('cancelSubscriptionAtPeriodEnd')
        ->once()
        ->with($stripeSubId);
    app()->instance(StripeBillingService::class, $mockBilling);

    // Provide a non-null Stripe secret so the guard inside cancelStripeAtPeriodEnd passes
    config(['services.stripe.secret_key' => 'sk_test_fake']);

    $service = new AccountDeletionService;
    $result = $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 — ticket #9876',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeTrue();
});

it('pseudonymises brand_profiles and all professionals PII fields when admin initiates erasure', function () {
    $staff = makeAdminStaff();
    $pro = makeActiveProfessional([
        'public_contact_email' => 'public@example.com',
        'public_contact_number' => '+61400999888',
        'bio' => 'I am a real person',
        'about' => '{"headline":"Some PII"}',
    ]);

    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'abn' => '98 765 432 109',
        'acn' => '987 654 321',
        'legal_business_name' => 'Test Brand Pty Ltd',
    ]);

    $service = new AccountDeletionService;
    $result = $service->adminInitiate(
        professional: $pro,
        staffActorId: $staff->id,
        staffActorHandle: $staff->name,
        reason: 'GDPR Article 17 — support ticket #DATA-1-test',
        overrideObligations: false,
        request: makeAdminRequest($staff),
    );

    expect($result['success'])->toBeTrue();

    $pro->refresh();
    expect($pro->public_contact_email)->toBeNull()
        ->and($pro->public_contact_number)->toBeNull()
        ->and($pro->bio)->toBeNull()
        ->and($pro->about)->toBe([]);

    $profile = DB::connection('pgsql')->table('brand.brand_profiles')
        ->where('professional_id', $pro->id)
        ->first();

    expect($profile->abn)->toBeNull()
        ->and($profile->acn)->toBeNull()
        ->and($profile->legal_business_name)->toBeNull();
});
