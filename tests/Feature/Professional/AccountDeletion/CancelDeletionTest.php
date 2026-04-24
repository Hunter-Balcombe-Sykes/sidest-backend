<?php

use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Models\Core\Professional\Professional;
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

function seedPendingDeletionProfessional(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'pending_deletion',
        'deletion_previous_status' => 'active',
        'deletion_confirmed_at' => now()->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($data);

    return Professional::query()->where('id', $id)->first();
}

it('restores previous status on cancel', function () {
    $pro = seedPendingDeletionProfessional(['deletion_previous_status' => 'active']);

    $service = new AccountDeletionService;
    $result = $service->cancel($pro, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $pro->refresh();
    expect($pro->status)->toBe('active')
        ->and($pro->deletion_previous_status)->toBeNull()
        ->and($pro->deletion_confirmed_at)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});

it('falls back to active when previous_status is null', function () {
    $pro = seedPendingDeletionProfessional(['deletion_previous_status' => null]);

    $service = new AccountDeletionService;
    $service->cancel($pro, Request::create('/', 'POST'));

    $pro->refresh();
    expect($pro->status)->toBe('active');
});

it('sends cancellation mail', function () {
    $pro = seedPendingDeletionProfessional();

    $service = new AccountDeletionService;
    $service->cancel($pro, Request::create('/', 'POST'));

    Mail::assertSent(AccountDeletionCancelledMail::class, function ($mail) use ($pro) {
        return $mail->hasTo($pro->primary_email);
    });
});

it('writes cancelled audit event', function () {
    $pro = seedPendingDeletionProfessional();

    $service = new AccountDeletionService;
    $service->cancel($pro, Request::create('/', 'POST'));

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->where('event', 'cancelled')
        ->first();

    expect($audit)->not->toBeNull();
});

it('cancel path calls Stripe resume with the correct subscription ID from findStripeSubscription', function () {
    $pro = seedPendingDeletionProfessional();

    DB::connection('pgsql')->table('billing.subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'stripe_subscription_id' => 'sub_dedup_test',
        'status' => 'active',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    config(['services.stripe.secret_key' => 'test-key']);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('resumeSubscription')
        ->once()
        ->with('sub_dedup_test');

    app()->instance(StripeBillingService::class, $billing);

    $service = new AccountDeletionService;
    $service->cancel($pro, Request::create('/', 'POST'));
});
