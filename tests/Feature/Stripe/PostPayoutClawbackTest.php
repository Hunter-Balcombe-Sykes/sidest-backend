<?php

use App\Models\Commerce\CommissionClawback;
use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Stripe\CommissionPayoutRefundService;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Stripe\StripeClient;

use function Pest\Laravel\mock;

// Exercises the post-payout-refund clawback path. When a Shopify refund arrives
// AFTER a CommissionPayout has settled, the service must issue a proportional
// Stripe Transfer Reversal and record a commerce.commission_clawbacks row.

beforeEach(function () {
    // Brand-as-Connect-account: clawbackCompletedPayout now loads the brand
    // Professional to read stripe_connect_account_id for the reversal call.
    // Provide a stub professionals table + the brand-Connect column so the
    // factory-created brand can be retrieved with a valid Connect account.
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
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        stripe_payment_intent_id TEXT,
        stripe_transfer_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        failure_reason TEXT,
        failure_code TEXT,
        failure_category TEXT,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT,
        processed_at TEXT,
        funding_source TEXT,
        wallet_debit_cents INTEGER DEFAULT 0,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        transfer_completed_at TEXT,
        next_retry_at TEXT,
        last_retry_at TEXT,
        funding_failure_count INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        stripe_error_code TEXT,
        stripe_error_message TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY,
        payout_id TEXT,
        order_id TEXT,
        amount_cents INTEGER,
        created_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_clawbacks (
        id TEXT PRIMARY KEY,
        payout_id TEXT NOT NULL,
        order_id TEXT NOT NULL,
        shopify_refund_id TEXT,
        stripe_reversal_id TEXT,
        amount_cents INTEGER NOT NULL,
        currency_code TEXT NOT NULL,
        status TEXT NOT NULL,
        failure_reason TEXT,
        metadata TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT,
        updated_at TEXT
    )');
});

function clawbackStripeMock(): StripeClient
{
    return mock(StripeClient::class, function (MockInterface $mock) {
        $transfersMock = Mockery::mock();
        $mock->transfers = $transfersMock;
    })->makePartial();
}

/**
 * Seed a minimal brand professional row with a Connect account ID, so that
 * the refund-clawback path can read stripe_connect_account_id for the
 * brand-scoped Transfer Reversal.
 */
function clawbackSeedBrand(string $id): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => "Brand {$id}",
        'professional_type' => 'brand',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_brand_test',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('issues a proportional Transfer Reversal when refund arrives on a completed payout', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'completed',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 2000, // 20% fee
        'net_payout_cents' => 8000,
        'currency_code' => 'AUD',
        'stripe_transfer_id' => 'tr_test_completed',
        'processed_at' => now(),
    ]);

    clawbackSeedBrand($payout->brand_professional_id);

    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 5000,
        'commission_cents' => 5000,
        'refund_cents' => 2500, // partial refund — half the order
        'status' => 'partially_refunded',
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000, // this order's full commission share
    ]);

    // Expected math:
    //   item_net = 5000 * (1 - 2000/10000) = 5000 * 0.8 = 4000
    //   refund_share = 2500 / 5000 = 0.5
    //   clawback = 4000 * 0.5 = 2000 cents
    $stripe = mock(StripeClient::class);
    $transfers = Mockery::mock();
    $stripe->transfers = $transfers;

    $transfers->shouldReceive('createReversal')
        ->once()
        ->with(
            'tr_test_completed',
            Mockery::on(fn ($payload) => $payload['amount'] === 2000),
            Mockery::on(fn ($opts) => str_starts_with($opts['idempotency_key'], 'rev_'.$payout->id)
                && $opts['stripe_account'] === 'acct_brand_test')
        )
        ->andReturn((object) ['id' => 'trr_abc_123']);

    $service = new CommissionPayoutRefundService(
        app(AnalyticsCacheService::class),
        $stripe,
    );

    $service->handleOrderRefund($order, 2500, 'rf_shopify_abc');

    $clawback = CommissionClawback::query()
        ->where('payout_id', $payout->id)
        ->where('order_id', $order->id)
        ->first();

    expect($clawback)->not->toBeNull()
        ->and($clawback->stripe_reversal_id)->toBe('trr_abc_123')
        ->and($clawback->amount_cents)->toBe(2000)
        ->and($clawback->status)->toBe('reversed')
        ->and($clawback->shopify_refund_id)->toBe('rf_shopify_abc');

    // Payout stays completed — clawback is recorded but doesn't mutate the payout status.
    expect($payout->fresh()->status)->toBe('completed');
});

it('flags the payout for manual refund when the Transfer Reversal fails', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'completed',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 2000,
        'net_payout_cents' => 8000,
        'currency_code' => 'AUD',
        'stripe_transfer_id' => 'tr_insufficient',
        'processed_at' => now(),
        'needs_manual_refund' => false,
    ]);

    clawbackSeedBrand($payout->brand_professional_id);

    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 5000,
        'commission_cents' => 5000,
        'refund_cents' => 5000, // full refund
        'status' => 'refunded',
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
    ]);

    $stripe = mock(StripeClient::class);
    $transfers = Mockery::mock();
    $stripe->transfers = $transfers;

    $stripeError = Mockery::mock(\Stripe\Exception\InvalidRequestException::class);
    $stripeError->shouldReceive('getStripeCode')->andReturn('insufficient_funds');
    $stripeError->shouldReceive('getMessage')->andReturn('Insufficient funds in connected account');

    $transfers->shouldReceive('createReversal')
        ->once()
        ->andThrow($stripeError);

    $service = new CommissionPayoutRefundService(
        app(AnalyticsCacheService::class),
        $stripe,
    );

    $service->handleOrderRefund($order, 5000, 'rf_failed');

    $clawback = CommissionClawback::query()
        ->where('payout_id', $payout->id)
        ->where('order_id', $order->id)
        ->first();

    expect($clawback)->not->toBeNull()
        ->and($clawback->status)->toBe('reversal_failed')
        ->and($clawback->failure_reason)->toBe('insufficient_funds');

    expect($payout->fresh()->needs_manual_refund)->toBeTrue();
});
