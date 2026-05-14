<?php

use App\Jobs\Stripe\ExecuteCommissionPayoutJob;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Verifies processEligiblePayouts batches commissions only for brands whose v2 Account
// is fully provisioned:
//   - stripe_connect_account_id IS NOT NULL  (v2 Account exists)
//   - stripe_connect_status = 'active'       (dual-capability check passed)
//   - stripe_payment_method_id IS NOT NULL   (saved card/BECS on the brand's own Account)
//
// Missing any of the three → brand is excluded. stripe_connect_customer_id is no longer
// checked — the v2 customer configuration enables PM storage at the Account level; a
// separate customer object is not required.

beforeEach(function () {
    Bus::fake();
    setupProfessionalsTable();
    setupCommerceOrdersTables();

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
        'stripe_payment_method_id TEXT',
        'stripe_payment_method_brand TEXT',
        'stripe_payment_method_last4 TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT, affiliate_professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT, void_at TEXT, processed_at TEXT,
        payment_intent_id TEXT, charge_cents INTEGER DEFAULT 0,
        failure_code TEXT, failure_reason TEXT,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        transfer_completed_at TEXT, last_retry_at TEXT,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY, payout_id TEXT, order_id TEXT, amount_cents INTEGER,
        created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY, professional_id TEXT, payout_hold_days INTEGER,
        default_commission_rate REAL, created_at TEXT, updated_at TEXT
    )');
});

function eligibility_seedBrand(string $id, array $overrides = []): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => "Brand {$id}",
        'professional_type' => 'brand',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_brand_test',
        'stripe_connect_status' => 'active',
        'stripe_payment_method_id' => 'pm_brand_test',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function eligibility_seedOrder(string $brandId, string $affiliateId, ?\DateTimeInterface $occurredAt = null): string
{
    $now = ($occurredAt ?? now()->subDays(10))->format('Y-m-d H:i:s');
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => $id,
        'shopify_order_id' => 'shop_'.substr($id, 0, 8),
        'shopify_shop_domain' => 'test.myshopify.com',
        'shopify_updated_at' => $now,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => 10000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 10000,
        'commission_cents' => 1000,
        'commission_rate' => 10.0,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => '{}',
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function eligibility_seedAffiliate(string $id): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "aff-{$id}",
        'handle_lc' => "aff-{$id}",
        'display_name' => "Affiliate {$id}",
        'professional_type' => 'professional',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_aff_test',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function eligibility_runSweep(): array
{
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->shouldReceive('getService')->andReturn(Mockery::mock()->shouldIgnoreMissing());

    return (new CommissionPayoutService($stripe))->processEligiblePayouts();
}

it('batches a payout when brand has all three v2 eligibility columns set', function () {
    eligibility_seedBrand('brand-eligible');
    eligibility_seedAffiliate('aff-1');
    eligibility_seedOrder('brand-eligible', 'aff-1');

    $stats = eligibility_runSweep();

    expect($stats['batches_created'])->toBe(1);
    Bus::assertDispatched(ExecuteCommissionPayoutJob::class);
});

it('skips brand without stripe_connect_account_id', function () {
    eligibility_seedBrand('brand-no-account', [
        'stripe_connect_account_id' => null,
    ]);
    eligibility_seedAffiliate('aff-1');
    eligibility_seedOrder('brand-no-account', 'aff-1');

    expect(eligibility_runSweep()['batches_created'])->toBe(0);
});

it('skips brand whose stripe_connect_status is not active', function () {
    eligibility_seedBrand('brand-restricted', ['stripe_connect_status' => 'restricted']);
    eligibility_seedAffiliate('aff-1');
    eligibility_seedOrder('brand-restricted', 'aff-1');

    expect(eligibility_runSweep()['batches_created'])->toBe(0);
});

it('skips brand whose stripe_connect_status is onboarding', function () {
    eligibility_seedBrand('brand-onboarding', ['stripe_connect_status' => 'onboarding']);
    eligibility_seedAffiliate('aff-1');
    eligibility_seedOrder('brand-onboarding', 'aff-1');

    expect(eligibility_runSweep()['batches_created'])->toBe(0);
});

it('skips brand without stripe_payment_method_id', function () {
    eligibility_seedBrand('brand-no-pm', ['stripe_payment_method_id' => null]);
    eligibility_seedAffiliate('aff-1');
    eligibility_seedOrder('brand-no-pm', 'aff-1');

    expect(eligibility_runSweep()['batches_created'])->toBe(0);
});

it('skips brand with not_connected stripe_connect_status', function () {
    eligibility_seedBrand('brand-disconnected', ['stripe_connect_status' => 'not_connected']);
    eligibility_seedAffiliate('aff-1');
    eligibility_seedOrder('brand-disconnected', 'aff-1');

    expect(eligibility_runSweep()['batches_created'])->toBe(0);
});
