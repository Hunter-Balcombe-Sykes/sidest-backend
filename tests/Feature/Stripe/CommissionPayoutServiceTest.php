<?php

use App\Jobs\Stripe\ExecuteCommissionPayoutJob;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

// V2: Process a single commission payout via destination charge.
//   One platform-scope PaymentIntent with customer_account,
//   transfer_data.destination, application_fee_amount. No transfers->create.
//
//   processPayoutBatch returns: true=completed, null=in-flight, false=failed.
//
//   State machine: pending → processing → completed|failed|cancelled.
//   No collecting/transferring/pending_funds states.

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
    date_default_timezone_set('UTC');
});

beforeEach(function () {
    Bus::fake();
    setupProfessionalsTable();
    setupCommerceOrdersTables();
    setupBrandStoreSettingsTable();

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT DEFAULT \'not_connected\'',
        'stripe_payment_method_id TEXT',
        'stripe_payment_method_brand TEXT',
        'stripe_payment_method_last4 TEXT',
        'payout_method TEXT',
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
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT,
        processed_at TEXT,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        transfer_completed_at TEXT,
        failure_category TEXT,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY,
        payout_id TEXT NOT NULL,
        order_id TEXT NOT NULL,
        amount_cents INTEGER NOT NULL DEFAULT 0
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_movements (
        id TEXT PRIMARY KEY,
        payout_id TEXT,
        entry_type TEXT,
        amount_cents INTEGER,
        created_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.brand_affiliate_rollup (
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        paid_cents INTEGER DEFAULT 0,
        reversed_commission_cents INTEGER DEFAULT 0
    )');
});

// ─── Helpers ────────────────────────────────────────────────────────────────

function v2_createBrand(?string $id = null): Professional
{
    $id ??= Str::uuid()->toString();

    return tap(new Professional([
        'id' => $id,
        'country_code' => 'AU',
        'professional_type' => 'brand',
        'stripe_connect_account_id' => 'acct_brand_'.$id,
        'stripe_connect_status' => 'active',
        'stripe_payment_method_id' => 'pm_card_'.$id,
        'stripe_payment_method_brand' => 'visa',
        'stripe_payment_method_last4' => '4242',
        'payout_method' => 'card',
    ]), fn (Professional $p) => $p->save());
}

function v2_createAffiliate(?string $id = null): Professional
{
    $id ??= Str::uuid()->toString();

    return tap(new Professional([
        'id' => $id,
        'country_code' => 'AU',
        'professional_type' => 'affiliate',
        'stripe_connect_account_id' => 'acct_aff_'.$id,
        'stripe_connect_status' => 'active',
        'stripe_payment_method_id' => null,
    ]), fn (Professional $p) => $p->save());
}

function v2_createOrder(Professional $brand, Professional $affiliate, array $overrides = []): Order
{
    // Order has $guarded=['*']; use forceFill to bypass mass-assignment guard.
    // shopify_shop_domain is part of the unique constraint with shopify_order_id, so it
    // must be set — paired with the per-order Str::uuid() to keep tests independent.
    return tap((new Order)->forceFill(array_merge([
        'id' => Str::uuid()->toString(),
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affiliate->id,
        'status' => 'approved',
        'gross_cents' => 10000,
        'commission_cents' => 1000,
        'refund_cents' => 0,
        'currency_code' => 'AUD',
        'payout_id' => null,
        'shopify_order_id' => 'shop_'.Str::uuid()->toString(),
        'shopify_shop_domain' => 'test-'.Str::random(8).'.myshopify.com',
        'occurred_at' => now()->subDays(10),
        'payout_eligible_at' => now()->subDay(),
        'rate_source' => 'active',
    ], $overrides)), fn (Order $o) => $o->save());
}

function v2_createPayout(Professional $brand, Professional $affiliate, array $overrides = []): CommissionPayout
{
    // CommissionPayout has $guarded=['*']; use forceFill to bypass mass-assignment guard.
    return tap((new CommissionPayout)->forceFill(array_merge([
        'id' => Str::uuid()->toString(),
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affiliate->id,
        'status' => 'pending',
        'gross_commission_cents' => 1000,
        'platform_fee_cents' => 30,
        'net_payout_cents' => 970,
        'currency_code' => 'AUD',
        'ledger_entry_count' => 1,
        'eligible_after' => now()->subDay(),
        'void_at' => now()->addDays(60),
        'retry_count' => 0,
    ], $overrides)), fn (CommissionPayout $p) => $p->save());
}

function v2_mockStripeClient(): StripeClient
{
    $mock = Mockery::mock(StripeClient::class)->makePartial();
    $mock->paymentIntents = Mockery::mock();
    $mock->refunds = Mockery::mock();

    return $mock;
}

// ─── processPayoutBatch — guard conditions ─────────────────────────────────

it('skips terminal-state payouts without re-creating PI (BECS race defence)', function (string $status, bool $expectedReturn) {
    // Without the terminal-state guard a BECS payout that the webhook already
    // marked 'failed' (T+2 settlement window > Stripe's 24h idempotency cache)
    // would fall through to a fresh PI create here and Stripe would charge the
    // brand a second time. Same exposure on 'cancelled' if a synchronous caller
    // (admin retry, future flow) re-entered after revalidatePayoutOrders cancelled.
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, [
        'status' => $status,
        'processed_at' => $status === 'completed' ? now() : null,
        'failure_code' => $status === 'failed' ? 'card_declined' : null,
    ]);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldNotReceive('create');

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBe($expectedReturn);
})->with([
    'completed' => ['completed', true],
    'failed' => ['failed', false],
    'cancelled' => ['cancelled', false],
]);

it('does NOT create a second PI when a processing payout is re-dispatched (BECS T+2 safety)', function () {
    // The daily sweep re-queues processing payouts so a missed webhook eventually
    // reconciles. Stripe's idempotency-key cache is only 24h, so calling PI.create
    // a second time with the same key after 24h creates a duplicate PI — a duplicate
    // charge against the brand. The service must short-circuit when payment_intent_id
    // is already set and status is processing.
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, [
        'status' => 'processing',
        'payment_intent_id' => 'pi_already_in_flight',
    ]);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldNotReceive('create');

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    // null = parked at processing, awaiting the webhook to drive it terminal.
    expect($result)->toBeNull();
    expect($payout->fresh()->payment_intent_id)->toBe('pi_already_in_flight');
});

it('DOES create a PI when a processing payout has no payment_intent_id yet (mid-flight crash recovery)', function () {
    // Edge: payout was marked processing but the PI create didn't persist (e.g. a crash
    // between status='processing' write and the PI ID write). On retry we should proceed
    // with the create; Stripe's idempotency key keeps it safe within the 24h window.
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, [
        'status' => 'processing',
        'payment_intent_id' => null,
    ]);

    $piMock = (object) ['id' => 'pi_recovered', 'status' => 'succeeded', 'latest_charge' => 'ch_recovered'];
    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()->andReturn($piMock);

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeTrue();
    expect($payout->fresh()->payment_intent_id)->toBe('pi_recovered');
});

it('fails when brand professional is missing', function () {
    $aff = v2_createAffiliate();
    $payout = v2_createPayout(
        tap(new Professional(['id' => Str::uuid()->toString(), 'professional_type' => 'brand']), fn ($p) => $p->save()),
        $aff,
        ['brand_professional_id' => 'nonexistent-id'],
    );

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldNotReceive('create');

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeFalse();
    expect($payout->fresh()->status)->toBe('failed');
    expect($payout->fresh()->failure_code)->toBe('brand_missing');
});

it('fails when affiliate connect account is not active', function () {
    $brand = v2_createBrand();
    $aff = tap(new Professional([
        'id' => Str::uuid()->toString(),
        'country_code' => 'AU',
        'professional_type' => 'affiliate',
        'stripe_connect_account_id' => null,
        'stripe_connect_status' => 'not_connected',
    ]), fn (Professional $p) => $p->save());
    $payout = v2_createPayout($brand, $aff);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldNotReceive('create');

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeFalse();
    expect($payout->fresh()->failure_code)->toBe('affiliate_not_connected');
});

it('fails when brand is not ready — missing connect account, not active, or no payment method', function (
    ?string $accountId,
    string $status,
    ?string $pmId,
    string $expectedCode,
) {
    $brand = tap(new Professional([
        'id' => Str::uuid()->toString(),
        'country_code' => 'AU',
        'professional_type' => 'brand',
        'stripe_connect_account_id' => $accountId,
        'stripe_connect_status' => $status,
        'stripe_payment_method_id' => $pmId,
    ]), fn (Professional $p) => $p->save());
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldNotReceive('create');

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeFalse();
    expect($payout->fresh()->failure_code)->toBe($expectedCode);
})->with([
    'no connect account' => [null, 'active', 'pm_123', 'brand_not_ready'],
    'not active status' => ['acct_123', 'onboarding', 'pm_123', 'brand_not_ready'],
    'no payment method' => ['acct_123', 'active', null, 'brand_not_ready'],
]);

it('fails when net payout is zero', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['net_payout_cents' => 0]);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldNotReceive('create');

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeFalse();
    expect($payout->fresh()->failure_code)->toBe('net_payout_zero');
});

// ─── processPayoutBatch — happy path (synchronous success) ─────────────────

it('creates a platform-scope PaymentIntent and marks completed on synchronous success', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);
    v2_createOrder($brand, $aff, ['payout_id' => $payout->id]);

    $pi = (object) [
        'id' => 'pi_test_123',
        'status' => 'succeeded',
        'latest_charge' => 'ch_test_456',
    ];

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')
        ->once()
        ->with(\Mockery::on(function (array $params) use ($brand, $aff, $payout) {
            return $params['customer_account'] === $brand->stripe_connect_account_id
                && ! array_key_exists('on_behalf_of', $params)
                && $params['transfer_data']['destination'] === $aff->stripe_connect_account_id
                && $params['application_fee_amount'] === $payout->platform_fee_cents
                && $params['confirm'] === true
                && $params['off_session'] === true
                && $params['metadata']['sidest_payout_id'] === $payout->id;
        }), \Mockery::any())
        ->andReturn($pi);

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeTrue();
    expect($payout->fresh()->status)->toBe('completed');
    expect($payout->fresh()->payment_intent_id)->toBe('pi_test_123');
    expect($payout->fresh()->charge_id)->toBe('ch_test_456');
    expect($payout->fresh()->processed_at)->not->toBeNull();
});

it('stores payment_intent_id and charge_id on the payout after PI create', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['gross_commission_cents' => 2000, 'platform_fee_cents' => 60, 'net_payout_cents' => 1940]);

    $pi = (object) [
        'id' => 'pi_sync_ok',
        'status' => 'succeeded',
        'latest_charge' => 'ch_sync_ok',
    ];

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()->andReturn($pi);

    $svc = new CommissionPayoutService($stripe);
    $svc->processPayoutBatch($payout);

    $fresh = $payout->fresh();
    expect($fresh->payment_intent_id)->toBe('pi_sync_ok');
    expect($fresh->charge_id)->toBe('ch_sync_ok');
});

// ─── processPayoutBatch — BECS / processing path (returns null) ─────────────

it('returns null when PI status is processing (BECS T+2 path)', function () {
    $brand = v2_createBrand();
    $brand->forceFill(['payout_method' => 'becs'])->save();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);

    $pi = (object) [
        'id' => 'pi_becs_123',
        'status' => 'processing',
        'latest_charge' => null,
    ];

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()->andReturn($pi);

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeNull();
    expect($payout->fresh()->status)->toBe('processing');
    expect($payout->fresh()->payment_intent_id)->toBe('pi_becs_123');
});

// ─── processPayoutBatch — requires_action → failure ─────────────────────────

it('fails when PI requires_action (off_session 3DS impossible)', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);

    $pi = (object) [
        'id' => 'pi_3ds',
        'status' => 'requires_action',
    ];

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()->andReturn($pi);

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeFalse();
    expect($payout->fresh()->status)->toBe('failed');
    expect($payout->fresh()->failure_code)->toBe('charge_requires_action');
});

// ─── processPayoutBatch — transient errors re-thrown for Horizon retry ──────

it('re-throws ApiConnectionException so Horizon retries', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()
        ->andThrow(new ApiConnectionException('Connection timeout'));

    $svc = new CommissionPayoutService($stripe);

    expect(fn () => $svc->processPayoutBatch($payout))
        ->toThrow(ApiConnectionException::class);
    // Status stays at 'processing' so the job retries with the same idempotency key.
    expect($payout->fresh()->status)->toBe('processing');
});

it('re-throws RateLimitException so Horizon retries', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()
        ->andThrow(new RateLimitException('Rate limit hit'));

    $svc = new CommissionPayoutService($stripe);

    expect(fn () => $svc->processPayoutBatch($payout))
        ->toThrow(RateLimitException::class);
});

// ─── processPayoutBatch — other ApiErrorException → hard failure ────────────

it('fails the payout on non-transient ApiErrorException', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()
        ->andThrow(new InvalidRequestException('Invalid request', 400));

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeFalse();
    expect($payout->fresh()->status)->toBe('failed');
    expect($payout->fresh()->failure_code)->not->toBeNull();
});

// ─── revalidatePayoutOrders — cancellation path ────────────────────────────

it('cancels payout when all orders become ineligible during revalidation', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);
    // Order exists but is now refunded — revalidatePayoutOrders should cancel.
    $order = v2_createOrder($brand, $aff, [
        'payout_id' => $payout->id,
        'status' => 'refunded',
        'refund_cents' => 10000,
    ]);
    CommissionPayoutItem::create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 1000,
    ]);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeNull();
    expect($payout->fresh()->status)->toBe('cancelled');
    expect($payout->fresh()->processed_at)->not->toBeNull();
});

it('rebuilds payout totals when some orders are removed during revalidation', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, [
        'gross_commission_cents' => 2000,
        'platform_fee_cents' => 60,
        'net_payout_cents' => 1940,
        'ledger_entry_count' => 2,
    ]);
    $good = v2_createOrder($brand, $aff, ['payout_id' => $payout->id, 'commission_cents' => 1000]);
    $stale = v2_createOrder($brand, $aff, ['payout_id' => $payout->id, 'commission_cents' => 1000, 'status' => 'refunded', 'refund_cents' => 10000]);
    CommissionPayoutItem::create(['payout_id' => $payout->id, 'order_id' => $good->id, 'amount_cents' => 1000]);
    CommissionPayoutItem::create(['payout_id' => $payout->id, 'order_id' => $stale->id, 'amount_cents' => 1000]);

    $pi = (object) ['id' => 'pi_rebuilt', 'status' => 'succeeded', 'latest_charge' => 'ch_rebuilt'];
    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()->andReturn($pi);

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->processPayoutBatch($payout);

    expect($result)->toBeTrue();
    $fresh = $payout->fresh();
    expect($fresh->gross_commission_cents)->toBe(1000);
    expect($fresh->ledger_entry_count)->toBe(1);
    // Stale order released back to the pool.
    expect(Order::find($stale->id)->payout_id)->toBeNull();
});

// ─── Retry key format ──────────────────────────────────────────────────────

it('uses pi_{payout_id} idempotency key on first attempt', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff);

    $pi = (object) ['id' => 'pi_key_1', 'status' => 'succeeded', 'latest_charge' => 'ch_1'];
    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')
        ->once()
        ->with(\Mockery::on(fn ($p) => true), ['idempotency_key' => 'pi_'.$payout->id])
        ->andReturn($pi);

    $svc = new CommissionPayoutService($stripe);
    $svc->processPayoutBatch($payout);
});

it('appends retry count to idempotency key on retry', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['retry_count' => 2]);

    $pi = (object) ['id' => 'pi_key_r2', 'status' => 'succeeded', 'latest_charge' => 'ch_r2'];
    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')
        ->once()
        ->with(\Mockery::on(fn ($p) => true), ['idempotency_key' => 'pi_'.$payout->id.'_r2'])
        ->andReturn($pi);

    $svc = new CommissionPayoutService($stripe);
    $svc->processPayoutBatch($payout);
});

// ─── retryPayout ───────────────────────────────────────────────────────────

it('retryPayout resets a failed payout to pending and re-processes', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, [
        'status' => 'failed',
        'failure_code' => 'charge_declined',
        'failure_reason' => 'Card declined',
        'processed_at' => now(),
        'retry_count' => 0,
    ]);

    $pi = (object) ['id' => 'pi_retry', 'status' => 'succeeded', 'latest_charge' => 'ch_retry'];
    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldReceive('create')->once()->andReturn($pi);

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->retryPayout($payout);

    expect($result)->toBeTrue();
    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('completed');
    expect($fresh->retry_count)->toBe(1);
    expect($fresh->failure_code)->toBeNull();
});

it('retryPayout skips completed payouts', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['status' => 'completed', 'processed_at' => now()]);

    $stripe = v2_mockStripeClient();
    $stripe->paymentIntents->shouldNotReceive('create');

    $svc = new CommissionPayoutService($stripe);
    $result = $svc->retryPayout($payout);

    expect($result)->toBeFalse();
});

// ─── Webhook hooks ─────────────────────────────────────────────────────────

it('markPaymentIntentSucceeded advances payout to completed', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['status' => 'processing', 'payment_intent_id' => 'pi_webhook']);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $svc->markPaymentIntentSucceeded($payout, 'ch_webhook_123');

    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('completed');
    expect($fresh->charge_id)->toBe('ch_webhook_123');
    expect($fresh->processed_at)->not->toBeNull();
    expect($fresh->transfer_completed_at)->not->toBeNull();
});

it('markPaymentIntentSucceeded is idempotent', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['status' => 'completed', 'processed_at' => now(), 'charge_id' => 'ch_existing']);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $svc->markPaymentIntentSucceeded($payout, 'ch_new_ignored');

    expect($payout->fresh()->charge_id)->toBe('ch_existing');
});

it('markPaymentIntentFailed transitions payout to failed', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['status' => 'processing', 'payment_intent_id' => 'pi_fail']);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $svc->markPaymentIntentFailed($payout, 'card_declined', 'Card was declined');

    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->failure_code)->toBe('card_declined');
    expect($fresh->failure_reason)->toBe('Card was declined');
    expect($fresh->processed_at)->not->toBeNull();
});

it('markPaymentIntentFailed skips already-completed payouts', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['status' => 'completed', 'processed_at' => now()]);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $svc->markPaymentIntentFailed($payout, 'should_be_ignored', 'Should not apply');

    expect($payout->fresh()->status)->toBe('completed');
});

// ─── processEligiblePayouts ────────────────────────────────────────────────

it('dispatches jobs for existing pending payouts within the sweep window', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    $payout = v2_createPayout($brand, $aff, ['status' => 'pending', 'eligible_after' => now()->subDay()]);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $stats = $svc->processEligiblePayouts();

    expect($stats['batches_dispatched'])->toBe(1);
    expect($stats['batches_requeued'])->toBe(1);
    Bus::assertDispatched(ExecuteCommissionPayoutJob::class, fn ($job) => $job->payoutId === $payout->id);
});

it('skips payouts with eligible_after in the future', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    v2_createPayout($brand, $aff, ['status' => 'pending', 'eligible_after' => now()->addDays(30)]);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $stats = $svc->processEligiblePayouts();

    expect($stats['batches_dispatched'])->toBe(0);
});

it('creates new payout batches for eligible approved orders', function () {
    $brand = v2_createBrand();
    $aff = v2_createAffiliate();
    v2_createOrder($brand, $aff, ['commission_cents' => 1500]);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $stats = $svc->processEligiblePayouts();

    expect($stats['batches_created'])->toBe(1);
});

it('excludes brands with missing payment method from eligibility', function () {
    $brand = tap(new Professional([
        'id' => Str::uuid()->toString(),
        'country_code' => 'AU',
        'professional_type' => 'brand',
        'stripe_connect_account_id' => 'acct_no_pm',
        'stripe_connect_status' => 'active',
        'stripe_payment_method_id' => null,
    ]), fn (Professional $p) => $p->save());
    $aff = v2_createAffiliate();
    v2_createOrder($brand, $aff);

    $svc = new CommissionPayoutService(v2_mockStripeClient());
    $stats = $svc->processEligiblePayouts();

    // Brand has no payment method — not eligible, no batch created.
    expect($stats['batches_created'])->toBe(0);
});
