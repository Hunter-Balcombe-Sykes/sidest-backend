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
