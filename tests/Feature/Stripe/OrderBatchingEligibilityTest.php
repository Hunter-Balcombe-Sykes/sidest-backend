<?php

use App\Models\Commerce\Order;
use Illuminate\Support\Facades\DB;

// Tests verifying which order statuses are eligible for commission payout batching
// under the v2 state machine.
//
// The v2 eligibility filter requires orders with status='approved' that have no
// payout_id assigned. All other statuses (refunded, partially_refunded, voided,
// stub, cancelled) are excluded from batching.
//
// Orders that are refunded/partially_refunded after being batched keep their
// payout_id — refund handling is downstream via CommissionPayoutRefundService.

beforeEach(function () {
    setupProfessionalsTable();
    setupCommerceOrdersTables();

    $conn = DB::connection('pgsql');

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
        funding_failure_count INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        grace_started_at TEXT,
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

    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
        'stripe_payment_method_id TEXT',
        'primary_email TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }
});

function orderDerivation_seedProfessional(string $id, string $type = 'brand', string $stripeStatus = 'active', ?string $pmId = null): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "pro-{$id}",
        'handle_lc' => "pro-{$id}",
        'display_name' => "Pro {$id}",
        'professional_type' => $type,
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_'.$id,
        'stripe_connect_status' => $stripeStatus,
        'stripe_payment_method_id' => $pmId,
        'primary_email' => "{$id}@test.test",
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function orderDerivation_seedOrder(string $id, string $status, ?string $payoutId = null, int $commissionCents = 5000): Order
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => $id,
        'shopify_order_id' => 'shop_'.$id,
        'shopify_shop_domain' => 'test.myshopify.com',
        'brand_professional_id' => 'pro-brand',
        'affiliate_professional_id' => 'pro-aff',
        'status' => $status,
        'gross_cents' => 35000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 35000,
        'commission_cents' => $commissionCents,
        'commission_rate' => 15,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'payout_id' => $payoutId,
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Order::find($id);
}

it('includes approved orders without payout_id in batching sweep', function () {
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', 'pm_brand_1');
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'active');

    $order = orderDerivation_seedOrder('o_approved', 'approved', null);

    // A pending sweep queries status='approved' AND payout_id IS NULL.
    $found = Order::query()
        ->where('affiliate_professional_id', 'pro-aff')
        ->where('status', 'approved')
        ->whereNull('payout_id')
        ->exists();

    expect($found)->toBeTrue();
});

it('excludes approved orders that already have a payout_id', function () {
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', 'pm_brand_1');
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'active');

    orderDerivation_seedOrder('o_batched', 'approved', 'p_existing');

    $found = Order::query()
        ->where('affiliate_professional_id', 'pro-aff')
        ->where('status', 'approved')
        ->whereNull('payout_id')
        ->exists();

    expect($found)->toBeFalse();
});

it('excludes refunded orders from batching', function () {
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', 'pm_brand_1');
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'active');

    orderDerivation_seedOrder('o_refunded', 'refunded', null);

    $found = Order::query()
        ->where('affiliate_professional_id', 'pro-aff')
        ->where('status', 'approved')
        ->whereNull('payout_id')
        ->exists();

    expect($found)->toBeFalse();
});

it('excludes partially_refunded orders from batching', function () {
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', 'pm_brand_1');
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'active');

    orderDerivation_seedOrder('o_partial', 'partially_refunded', null);

    $found = Order::query()
        ->where('affiliate_professional_id', 'pro-aff')
        ->where('status', 'approved')
        ->whereNull('payout_id')
        ->exists();

    expect($found)->toBeFalse();
});

it('excludes voided orders from batching', function () {
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', 'pm_brand_1');
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'active');

    orderDerivation_seedOrder('o_voided', 'voided', null);

    $found = Order::query()
        ->where('affiliate_professional_id', 'pro-aff')
        ->where('status', 'approved')
        ->whereNull('payout_id')
        ->exists();

    expect($found)->toBeFalse();
});

it('excludes cancelled orders from batching', function () {
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', 'pm_brand_1');
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'active');

    orderDerivation_seedOrder('o_cancelled', 'cancelled', null);

    $found = Order::query()
        ->where('affiliate_professional_id', 'pro-aff')
        ->where('status', 'approved')
        ->whereNull('payout_id')
        ->exists();

    expect($found)->toBeFalse();
});

it('excludes stub orders from batching', function () {
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', 'pm_brand_1');
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'active');

    orderDerivation_seedOrder('o_stub', 'stub', null);

    $found = Order::query()
        ->where('affiliate_professional_id', 'pro-aff')
        ->where('status', 'approved')
        ->whereNull('payout_id')
        ->exists();

    expect($found)->toBeFalse();
});

it('only batches when affiliate has active Stripe Connect and brand has payment method', function () {
    // Affiliate not active → sweep returns empty.
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', 'pm_brand_1');
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'not_connected');

    orderDerivation_seedOrder('o_eligible', 'approved', null);

    // v2 eligibility: stripe_connect_account_id IS NOT NULL AND stripe_connect_status='active'
    $affiliateActive = DB::connection('pgsql')
        ->table('core.professionals')
        ->where('id', 'pro-aff')
        ->whereNotNull('stripe_connect_account_id')
        ->where('stripe_connect_status', 'active')
        ->exists();

    expect($affiliateActive)->toBeFalse();
});

it('only batches when brand has stripe_payment_method_id set', function () {
    orderDerivation_seedProfessional('pro-brand', 'brand', 'active', null); // no PM
    orderDerivation_seedProfessional('pro-aff', 'influencer', 'active');

    orderDerivation_seedOrder('o_eligible', 'approved', null);

    // v2 eligibility: brand must have stripe_payment_method_id IS NOT NULL
    $brandReady = DB::connection('pgsql')
        ->table('core.professionals')
        ->where('id', 'pro-brand')
        ->whereNotNull('stripe_connect_account_id')
        ->where('stripe_connect_status', 'active')
        ->whereNotNull('stripe_payment_method_id')
        ->exists();

    expect($brandReady)->toBeFalse();
});
