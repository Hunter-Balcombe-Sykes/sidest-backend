<?php

use App\Models\Commerce\CommissionClawback;
use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Stripe\CommissionPayoutRefundService;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

// Exercises the post-payout-refund clawback path under v2 destination-charge (Option A).
// When a Shopify refund arrives AFTER a CommissionPayout has settled, the service issues a
// single atomic Stripe Refund with refund_application_fee + reverse_transfer (replacing the
// v1-era transfers->createReversal chain). Stripe proportionally reverses the affiliate
// transfer and the platform fee in one call.

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
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        payment_intent_id TEXT,
        charge_id TEXT,
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
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        transfer_completed_at TEXT,
        last_retry_at TEXT,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
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
        refund_id TEXT,
        refund_amount_cents INTEGER,
        application_fee_refund_cents INTEGER,
        transfer_reversal_cents INTEGER,
        is_partial INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        amount_cents INTEGER NOT NULL,
        currency_code TEXT NOT NULL,
        status TEXT NOT NULL,
        failure_reason TEXT,
        metadata TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT,
        updated_at TEXT
    )');
});

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

it('issues a single atomic Refund with refund_application_fee + reverse_transfer when refund arrives on a completed payout', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'completed',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 2000,
        'net_payout_cents' => 8000,
        'currency_code' => 'AUD',
        'payment_intent_id' => 'pi_test_completed',
        'processed_at' => now(),
    ]);

    clawbackSeedBrand($payout->brand_professional_id);

    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 5000,
        'commission_cents' => 5000,
        'refund_cents' => 2500,
        'status' => 'partially_refunded',
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
    ]);

    // Expected: refund_share = 2500/5000 = 0.5, refund_cents = 5000 * 0.5 = 2500.
    // Platform-scoped Refund with both reversal flags — no stripe_account header.
    // Idempotency key: rf_{payout_id}_{order_id}_{hash}
    $stripe = Mockery::mock(StripeClient::class);
    $refunds = Mockery::mock();
    $stripe->refunds = $refunds;

    $refunds->shouldReceive('create')
        ->once()
        ->with(
            Mockery::on(function (array $payload) use ($payout, $order) {
                return $payload['payment_intent'] === 'pi_test_completed'
                    && $payload['refund_application_fee'] === true
                    && $payload['reverse_transfer'] === true
                    && $payload['amount'] === 2500
                    && $payload['metadata']['sidest_payout_id'] === $payout->id
                    && $payload['metadata']['sidest_order_id'] === $order->id;
            }),
            Mockery::on(function (array $opts) use ($payout, $order) {
                // v2 format: rf_{payout_id}_{order_id}_{16-char md5 substring}
                $expectedPrefix = "rf_{$payout->id}_{$order->id}_";

                return isset($opts['idempotency_key'])
                    && str_starts_with($opts['idempotency_key'], $expectedPrefix)
                    && strlen($opts['idempotency_key']) === strlen($expectedPrefix) + 16;
            }),
        )
        ->andReturn((object) ['id' => 're_abc_123']);

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
        ->and($clawback->stripe_reversal_id)->toBe('re_abc_123')
        ->and($clawback->status)->toBe('reversed')
        ->and($clawback->shopify_refund_id)->toBe('rf_shopify_abc');

    expect($payout->fresh()->status)->toBe('completed');
});

it('flags the payout for manual refund when the Refund call fails', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'completed',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 2000,
        'net_payout_cents' => 8000,
        'currency_code' => 'AUD',
        'payment_intent_id' => 'pi_insufficient',
        'processed_at' => now(),
        'needs_manual_refund' => false,
    ]);

    clawbackSeedBrand($payout->brand_professional_id);

    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 5000,
        'commission_cents' => 5000,
        'refund_cents' => 5000,
        'status' => 'refunded',
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $refunds = Mockery::mock();
    $stripe->refunds = $refunds;

    $stripeError = Mockery::mock(\Stripe\Exception\InvalidRequestException::class);
    $stripeError->shouldReceive('getStripeCode')->andReturn('insufficient_funds');
    $stripeError->shouldReceive('getMessage')->andReturn('Insufficient funds in connected account');

    $refunds->shouldReceive('create')
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
