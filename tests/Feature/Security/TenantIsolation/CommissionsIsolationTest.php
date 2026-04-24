<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        stripe_payment_intent_id TEXT,
        stripe_transfer_id TEXT,
        status TEXT,
        gross_commission_cents INTEGER,
        platform_fee_cents INTEGER,
        net_payout_cents INTEGER,
        currency_code TEXT,
        failure_reason TEXT,
        failure_code TEXT,
        ledger_entry_count INTEGER,
        eligible_after TEXT,
        processed_at TEXT,
        funding_source TEXT,
        wallet_debit_cents INTEGER,
        charge_cents INTEGER,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');
});

it('stripe payouts list never returns another professionals payouts', function () {
    [$a, $b] = createTwoTenants('affiliate');

    $aPayoutId = (string) Str::uuid();
    $bPayoutId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('commerce.commission_payouts')->insert([
        [
            'id' => $aPayoutId,
            'brand_professional_id' => (string) Str::uuid(),
            'affiliate_professional_id' => $a->id,
            'status' => 'paid',
            'net_payout_cents' => 10_00,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $bPayoutId,
            'brand_professional_id' => (string) Str::uuid(),
            'affiliate_professional_id' => $b->id,
            'status' => 'paid',
            'net_payout_cents' => 20_00,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    // Mock the payout service so getPayoutSummary() doesn't run a real query
    $this->mock(CommissionPayoutService::class, fn ($mock) => $mock->shouldReceive('getPayoutSummary')->andReturn([
        'total_paid_cents' => 0,
        'total_pending_cents' => 0,
        'currency_code' => 'GBP',
    ])
    );

    $this->mock(StripeConnectService::class, fn ($mock) => $mock);

    $req = tenantRequestAs($b);
    $req->query->set('role', 'affiliate');

    $response = app(StripeConnectController::class)->payouts($req);
    $payload = $response->getData(true);

    $ids = collect($payload['payouts'] ?? [])->pluck('id')->all();

    expect($ids)->toContain($bPayoutId);
    expect($ids)->not->toContain($aPayoutId);
    expect(count($ids))->toBe(1);
});
