<?php

use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Verifies that processPayoutBatch creates the PaymentIntent as a DIRECT
// CHARGE on the brand's own Connect account — i.e. carries
// stripe_account=brand_connect_id, application_fee_amount=platform_fee_cents,
// and uses the brand-Connect-scoped customer + payment method (NOT the
// platform-scoped IDs reserved for SaaS billing).

beforeEach(function () {
    Bus::fake();
    setupProfessionalsTable();
    setupCommerceOrdersTables();

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
        'stripe_customer_id TEXT',
        'stripe_payment_method_id TEXT',
        'stripe_connect_customer_id TEXT',
        'stripe_connect_payment_method_id TEXT',
        'stripe_manual_balance_cents INTEGER DEFAULT 0',
        'stripe_manual_balance_currency TEXT',
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
        failure_reason TEXT, failure_code TEXT, failure_category TEXT,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT, processed_at TEXT, funding_source TEXT,
        wallet_debit_cents INTEGER DEFAULT 0, charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT, transfer_completed_at TEXT,
        stripe_error_code TEXT, stripe_error_message TEXT,
        next_retry_at TEXT, last_retry_at TEXT,
        funding_failure_count INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        grace_started_at TEXT,
        created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY, payout_id TEXT, order_id TEXT, amount_cents INTEGER,
        created_at TEXT, updated_at TEXT
    )');
});

function directCharge_seedBrand(array $overrides = []): string
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
        'stripe_connect_customer_id' => 'cus_on_brand',
        'stripe_connect_payment_method_id' => 'pm_on_brand',
        // Platform-scoped IDs intentionally set to obviously-wrong values
        // so any accidental fallback to them would be caught by the assertions.
        'stripe_customer_id' => 'cus_WRONG_PLATFORM',
        'stripe_payment_method_id' => 'pm_WRONG_PLATFORM',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

function directCharge_seedAffiliate(array $overrides = []): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "aff-{$id}",
        'handle_lc' => "aff-{$id}",
        'display_name' => 'Aff One',
        'professional_type' => 'professional',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_aff_test',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

function directCharge_seedPayout(string $brandId, string $affiliateId, array $overrides = []): CommissionPayout
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'pending',
        'gross_commission_cents' => 2000,        // $20 charge
        'platform_fee_cents' => 400,             // $4 to Partna
        'net_payout_cents' => 1600,              // $16 nominal to affiliate
        'currency_code' => 'AUD',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return CommissionPayout::find($id);
}

function directCharge_makeStripe(array $services): StripeClient
{
    $stripe = Mockery::mock(StripeClient::class);
    foreach ($services as $name => $impl) {
        $stripe->shouldReceive('getService')->with($name)->andReturn($impl);
    }

    return $stripe;
}

it('creates the PaymentIntent as a direct charge on the brand\'s Connect account', function () {
    $brandId = directCharge_seedBrand();
    $affiliateId = directCharge_seedAffiliate();
    $payout = directCharge_seedPayout($brandId, $affiliateId);

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload, $opts) use ($payout) {
            // Direct charge on brand
            expect($opts['stripe_account'])->toBe('acct_brand_test');
            // application_fee_amount routes Partna's cut to platform
            expect($payload['application_fee_amount'])->toBe($payout->platform_fee_cents);
            // Brand-Connect-scoped customer + PM, NOT the WRONG_PLATFORM ones
            expect($payload['customer'])->toBe('cus_on_brand');
            expect($payload['payment_method'])->toBe('pm_on_brand');
            expect($payload['amount'])->toBe($payout->gross_commission_cents);
            expect($payload['currency'])->toBe('aud');
            expect($payload['confirm'])->toBeTrue();
            expect($payload['off_session'])->toBeTrue();
            // balance_transaction expand is required for the Transfer-amount lookup
            expect($payload['expand'])->toContain('latest_charge.balance_transaction');
            expect($payload['metadata']['sidest_payout_id'])->toBe($payout->id);

            return true;
        })
        ->andReturn((object) [
            'id' => 'pi_brand',
            'status' => 'succeeded',
            'latest_charge' => (object) [
                'id' => 'ch_brand',
                // brand_charge_net: $20 charge − $4 app_fee − $0.62 stripe_fee = $15.38
                'balance_transaction' => (object) ['net' => 1538],
            ],
        ]);

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload, $opts) {
            // Transfer originates from brand's Connect balance, lands on affiliate's
            expect($opts['stripe_account'])->toBe('acct_brand_test');
            expect($payload['destination'])->toBe('acct_aff_test');
            // Sized at balance_transaction.net, not net_payout_cents
            expect($payload['amount'])->toBe(1538);
            expect($payload['source_transaction'])->toBe('ch_brand');

            return true;
        })
        ->andReturn((object) ['id' => 'tr_brand', 'status' => 'paid']);

    $service = new CommissionPayoutService(
        directCharge_makeStripe(['paymentIntents' => $piMock, 'transfers' => $transferMock])
    );

    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeTrue();
    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('completed');
    expect($fresh->stripe_payment_intent_id)->toBe('pi_brand');
    expect($fresh->stripe_transfer_id)->toBe('tr_brand');
});

it('marks pending_funds with brand_payment_method_missing when brand-Connect columns are unset', function () {
    $brandId = directCharge_seedBrand([
        // Platform-scoped IDs DO exist (SaaS billing) but commission flow requires brand-Connect ones
        'stripe_connect_customer_id' => null,
        'stripe_connect_payment_method_id' => null,
    ]);
    $affiliateId = directCharge_seedAffiliate();
    $payout = directCharge_seedPayout($brandId, $affiliateId);

    $piMock = Mockery::mock();
    $piMock->shouldNotReceive('create');

    $service = new CommissionPayoutService(
        directCharge_makeStripe(['paymentIntents' => $piMock, 'transfers' => Mockery::mock(), 'refunds' => Mockery::mock()])
    );

    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeNull();
    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('pending_funds');
    expect($fresh->failure_code)->toBe('brand_payment_method_missing');
});

it('marks pending_funds when brand connect status is not active', function () {
    $brandId = directCharge_seedBrand(['stripe_connect_status' => 'restricted']);
    $affiliateId = directCharge_seedAffiliate();
    $payout = directCharge_seedPayout($brandId, $affiliateId);

    $piMock = Mockery::mock();
    $piMock->shouldNotReceive('create');

    $service = new CommissionPayoutService(
        directCharge_makeStripe(['paymentIntents' => $piMock, 'transfers' => Mockery::mock(), 'refunds' => Mockery::mock()])
    );

    $service->processPayoutBatch($payout);

    expect($payout->fresh()->failure_code)->toBe('brand_payment_method_missing');
});
