<?php

use App\Models\Commerce\WalletMovement;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Tests for creditWalletFromCheckoutSession — race-safe (lockForUpdate),
// idempotent (UNIQUE idempotency_key), and actor-tagged (AUSTRAC requirement).

beforeEach(function () {
    config(['services.stripe.secret_key' => 'sk_test_placeholder']);

    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT DEFAULT \'not_connected\'',
        'stripe_customer_id TEXT',
        'stripe_manual_balance_cents INTEGER DEFAULT 0',
        'stripe_manual_balance_currency TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    // commerce.wallet_movements — full schema required for UNIQUE(idempotency_key).
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.wallet_movements (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        direction TEXT NOT NULL DEFAULT \'credit\',
        amount_cents INTEGER NOT NULL,
        currency_code TEXT NOT NULL,
        reason TEXT NULL,
        actor_type TEXT NULL,
        actor_id TEXT NULL,
        related_payout_id TEXT NULL,
        related_session_id TEXT NULL,
        idempotency_key TEXT NOT NULL UNIQUE,
        metadata TEXT NULL,
        occurred_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

function walletLedger_makeService(?object $stripeClient = null): \App\Services\Stripe\StripeConnectService
{
    $service = new \App\Services\Stripe\StripeConnectService;

    if ($stripeClient) {
        $prop = (new ReflectionClass($service))->getProperty('stripe');
        $prop->setAccessible(true);
        $prop->setValue($service, $stripeClient);
    }

    return $service;
}

function walletLedger_makeBrand(array $attrs = []): Professional
{
    $id = (string) Str::uuid();

    return Professional::create(array_merge([
        'id'                              => $id,
        'handle'                          => "brand-{$id}",
        'handle_lc'                       => "brand-{$id}",
        'display_name'                    => "Brand {$id}",
        'professional_type'               => 'brand',
        'status'                          => 'active',
        'stripe_manual_balance_cents'     => 0,
        'stripe_manual_balance_currency'  => 'AUD',
        'stripe_customer_id'              => 'cus_test',
    ], $attrs));
}

function walletLedger_makeSession(string $brandId, array $overrides = []): object
{
    return (object) array_merge([
        'id'             => 'cs_topup_' . Str::random(8),
        'mode'           => 'payment',
        'payment_status' => 'paid',
        'amount_total'   => 5000,
        'currency'       => 'aud',
        'payment_intent' => 'pi_test_' . Str::random(8),
        'metadata'       => (object) [
            'professional_id'        => $brandId,
            'sidest_professional_id' => $brandId,
            'purpose'                => 'brand_commission_topup',
        ],
    ], $overrides);
}

it('credits wallet exactly once when called twice (idempotent via UNIQUE key)', function () {
    $brand = walletLedger_makeBrand();
    $session = walletLedger_makeSession($brand->id, ['id' => 'cs_topup_dup']);

    $service = walletLedger_makeService();
    $service->creditWalletFromCheckoutSession($brand->id, $session);
    $service->creditWalletFromCheckoutSession($brand->id, $session);

    expect($brand->fresh()->stripe_manual_balance_cents)->toBe(5000);
    expect(WalletMovement::where('related_session_id', 'cs_topup_dup')->count())->toBe(1);
});

it('does not credit wallet for currency mismatch and auto-refunds', function () {
    $brand = walletLedger_makeBrand([
        'stripe_manual_balance_cents'    => 100,
        'stripe_manual_balance_currency' => 'AUD',
    ]);

    $sessionId = 'cs_currency_mismatch';
    $piId = 'pi_to_refund';

    $refundsMock = Mockery::mock();
    $refundsMock->shouldReceive('create')
        ->once()
        ->withArgs(fn ($args) => ($args['payment_intent'] ?? null) === $piId);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('refunds')->andReturn($refundsMock);
    $stripeClient->shouldReceive('getService')->andReturn(Mockery::mock())->byDefault();

    $session = walletLedger_makeSession($brand->id, [
        'id'             => $sessionId,
        'currency'       => 'usd', // mismatch — wallet is AUD
        'payment_intent' => $piId,
    ]);

    $service = walletLedger_makeService($stripeClient);
    $service->creditWalletFromCheckoutSession($brand->id, $session);

    expect($brand->fresh()->stripe_manual_balance_cents)->toBe(100); // unchanged
    expect(WalletMovement::where('professional_id', $brand->id)->count())->toBe(0);
});

it('does not credit wallet when payment_status is not paid', function () {
    $brand = walletLedger_makeBrand();
    $session = walletLedger_makeSession($brand->id, [
        'id'             => 'cs_unpaid',
        'payment_status' => 'unpaid',
    ]);

    walletLedger_makeService()->creditWalletFromCheckoutSession($brand->id, $session);

    expect($brand->fresh()->stripe_manual_balance_cents)->toBe(0);
    expect(WalletMovement::where('related_session_id', 'cs_unpaid')->count())->toBe(0);
});

it('credits correct amount and records actor type webhook', function () {
    $brand = walletLedger_makeBrand();
    $session = walletLedger_makeSession($brand->id, [
        'id'           => 'cs_actor_tag',
        'amount_total' => 12000,
        '_stripe_event_id' => 'evt_actor_test',
    ]);

    walletLedger_makeService()->creditWalletFromCheckoutSession($brand->id, $session);

    expect($brand->fresh()->stripe_manual_balance_cents)->toBe(12000);

    $movement = WalletMovement::where('related_session_id', 'cs_actor_tag')->first();
    expect($movement)->not->toBeNull();
    expect($movement->amount_cents)->toBe(12000);
    expect($movement->direction)->toBe('credit');
    expect($movement->reason)->toBe('top_up');
    expect($movement->actor_type)->toBe('webhook');
    expect($movement->idempotency_key)->toBe('topup:cs_actor_tag');
});

it('records actor_type professional when actor override is set', function () {
    $brand = walletLedger_makeBrand();
    $session = walletLedger_makeSession($brand->id, ['id' => 'cs_pro_actor']);
    $session->_actor_override = ['type' => 'professional', 'id' => $brand->id];

    walletLedger_makeService()->creditWalletFromCheckoutSession($brand->id, $session);

    $movement = WalletMovement::where('related_session_id', 'cs_pro_actor')->first();
    expect($movement->actor_type)->toBe('professional');
    expect($movement->actor_id)->toBe($brand->id);
});

it('skips crediting when brand does not exist', function () {
    $session = walletLedger_makeSession('nonexistent-id', ['id' => 'cs_no_brand']);

    // Should not throw — just log and return
    expect(fn () => walletLedger_makeService()->creditWalletFromCheckoutSession('nonexistent-id', $session))
        ->not->toThrow(\Throwable::class);

    expect(WalletMovement::where('related_session_id', 'cs_no_brand')->count())->toBe(0);
});
