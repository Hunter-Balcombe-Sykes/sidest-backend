<?php

use App\Models\Commerce\CommissionClawback;
use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Stripe\CommissionPayoutRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Stripe\StripeClient;

use function Pest\Laravel\mock;

// Verifies the Transfer Reversal that claws back the affiliate's share on a
// post-payout refund is scoped to the BRAND'S Connect account via the
// `stripe_account` option — matching where the original Transfer ran. Also
// verifies the safety guard: when the brand row has no
// stripe_connect_account_id, the reversal is skipped and the payout is
// flagged needs_manual_refund (we cannot blindly address the platform).

beforeEach(function () {
    setupProfessionalsTable();
    setupCommerceOrdersTables();

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT, affiliate_professional_id TEXT,
        stripe_payment_intent_id TEXT, stripe_transfer_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        failure_reason TEXT, failure_code TEXT, failure_category TEXT,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT, processed_at TEXT, funding_source TEXT,
        wallet_debit_cents INTEGER DEFAULT 0, charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT, transfer_completed_at TEXT,
        next_retry_at TEXT, last_retry_at TEXT,
        funding_failure_count INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        stripe_error_code TEXT, stripe_error_message TEXT,
        created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY, payout_id TEXT, order_id TEXT, amount_cents INTEGER,
        created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_clawbacks (
        id TEXT PRIMARY KEY,
        payout_id TEXT NOT NULL, order_id TEXT NOT NULL,
        shopify_refund_id TEXT, stripe_reversal_id TEXT,
        amount_cents INTEGER NOT NULL, currency_code TEXT NOT NULL,
        status TEXT NOT NULL, failure_reason TEXT,
        metadata TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT, updated_at TEXT
    )');
});

function clawbackBrand_seedBrand(array $overrides = []): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => 'Side St',
        'professional_type' => 'brand',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_brand_test',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

function clawbackBrand_makeStripe(): array
{
    $transfersMock = Mockery::mock();
    $stripe = mock(StripeClient::class, function (MockInterface $mock) use ($transfersMock) {
        $mock->transfers = $transfersMock;
    })->makePartial();

    return [$stripe, $transfersMock];
}

it('issues the Transfer Reversal with stripe_account=brand_connect_id', function () {
    $brandId = clawbackBrand_seedBrand();

    $payout = CommissionPayout::factory()->create([
        'brand_professional_id' => $brandId,
        'status' => 'completed',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 2000,
        'net_payout_cents' => 8000,
        'currency_code' => 'AUD',
        'stripe_transfer_id' => 'tr_brand_test',
        'processed_at' => now(),
    ]);

    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 5000,
        'commission_cents' => 5000,
        'refund_cents' => 2500,
        'status' => 'partially_refunded',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
    ]);

    [$stripe, $transfers] = clawbackBrand_makeStripe();

    $transfers->shouldReceive('createReversal')
        ->once()
        ->withArgs(function ($transferId, $payload, $opts) {
            expect($transferId)->toBe('tr_brand_test');
            // Reversal targets the brand's account (where the original transfer lived)
            expect($opts['stripe_account'])->toBe('acct_brand_test');
            expect($opts)->toHaveKey('idempotency_key');
            // Math: item_net = 5000 * (1 - 2000/10000) = 4000; refund_share = 2500/5000 = 0.5
            // clawback = 4000 * 0.5 = 2000
            expect($payload['amount'])->toBe(2000);

            return true;
        })
        ->andReturn((object) ['id' => 'trr_brand']);

    $service = new CommissionPayoutRefundService(
        app(AnalyticsCacheService::class),
        $stripe,
    );

    $service->handleOrderRefund($order, 2500, 'rf_test');

    $clawback = CommissionClawback::where('payout_id', $payout->id)->first();
    expect($clawback->stripe_reversal_id)->toBe('trr_brand');
    expect($clawback->status)->toBe('reversed');
});

it('skips the reversal and flags manual when brand row has no Connect account ID', function () {
    $brandId = clawbackBrand_seedBrand(['stripe_connect_account_id' => null]);

    $payout = CommissionPayout::factory()->create([
        'brand_professional_id' => $brandId,
        'status' => 'completed',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 2000,
        'net_payout_cents' => 8000,
        'currency_code' => 'AUD',
        'stripe_transfer_id' => 'tr_orphan',
        'processed_at' => now(),
    ]);

    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 5000,
        'commission_cents' => 5000,
        'refund_cents' => 5000,
        'status' => 'refunded',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
    ]);

    [$stripe, $transfers] = clawbackBrand_makeStripe();
    $transfers->shouldNotReceive('createReversal');

    $service = new CommissionPayoutRefundService(
        app(AnalyticsCacheService::class),
        $stripe,
    );

    $service->handleOrderRefund($order, 5000, 'rf_orphan');

    expect($payout->fresh()->needs_manual_refund)->toBeTrue();
    // No clawback row created (we never called Stripe)
    expect(CommissionClawback::where('payout_id', $payout->id)->count())->toBe(0);
});
