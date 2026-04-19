<?php

use App\Models\Core\Professional\Professional;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function makeProfessional(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'test-' . substr($id, 0, 8),
        'handle_lc' => 'test-' . substr($id, 0, 8),
        'display_name' => 'Test Pro',
        'primary_email' => 'test-' . substr($id, 0, 8) . '@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($data);
    return Professional::query()->where('id', $id)->first();
}

it('rejects request when professional has unpaid balance', function () {
    $pro = makeProfessional(['stripe_manual_balance_cents' => 1000]);

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(422)
        ->and($result['reasons'])->toContain('unpaid_balance');
});

it('rejects request when professional has pending commission payouts', function () {
    $pro = makeProfessional();

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $pro->id,
        'affiliate_professional_id' => (string) Str::uuid(),
        'status' => 'pending',
        'amount_cents' => 500,
        'created_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['reasons'])->toContain('pending_payouts');
});

it('stores hashed token, sets requested_at, and sends confirmation mail', function () {
    $pro = makeProfessional();

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $pro->refresh();
    expect($pro->deletion_token_hash)->not->toBeNull()
        ->and(strlen($pro->deletion_token_hash))->toBe(64) // sha256 hex
        ->and($pro->deletion_requested_at)->not->toBeNull()
        ->and($pro->status)->toBe('active'); // status does NOT change on request

    Mail::assertSent(\App\Mail\Notifications\AccountDeletionRequestedMail::class, function ($mail) use ($pro) {
        return $mail->hasTo($pro->primary_email);
    });
});

it('writes a requested audit entry on successful request', function () {
    $pro = makeProfessional();
    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_USER_AGENT' => 'TestAgent']);

    $service->request($pro, $request);

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('requested')
        ->and($audit->professional_handle_snapshot)->toBe($pro->handle)
        ->and($audit->professional_email_snapshot)->toBe($pro->primary_email)
        ->and($audit->ip_address)->toBe('1.2.3.4')
        ->and($audit->user_agent)->toBe('TestAgent');
});

it('rolls back token storage if mail send throws', function () {
    $pro = makeProfessional();

    Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(503);

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});

it('rejects request when brand has pending topups', function () {
    $pro = makeProfessional();

    DB::connection('pgsql')->table('commerce.brand_commission_topups')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $pro->id,
        'status' => 'pending',
        'amount_cents' => 5000,
        'created_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['reasons'])->toContain('pending_topups');
});
