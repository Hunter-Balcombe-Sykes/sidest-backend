<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
    attachTestSchemas();
    Queue::fake();

    // Stub notifications table so notifyAffiliatesOfRefund doesn't explode in test env.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        type TEXT NULL,
        category TEXT NULL,
        title TEXT NULL,
        body TEXT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        dedupe_key TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
        id TEXT PRIMARY KEY,
        shopify_order_id TEXT NULL,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        entry_type TEXT NULL,
        status TEXT NULL,
        amount_cents INTEGER NULL,
        currency_code TEXT NULL,
        commission_rate REAL NULL,
        rate_source TEXT NULL,
        idempotency_key TEXT NULL,
        calculation_metadata TEXT NULL,
        payout_id TEXT NULL,
        occurred_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

it('does nothing when the brand professional does not exist', function () {
    $deletedBrandId = (string) Str::uuid();

    $orderId = (string) Str::uuid();
    DB::connection('pgsql')->table('commerce.commission_ledger_entries')->insert([
        'id' => (string) Str::uuid(),
        'shopify_order_id' => $orderId,
        'brand_professional_id' => $deletedBrandId,
        'affiliate_professional_id' => (string) Str::uuid(),
        'entry_type' => 'accrual',
        'status' => 'approved',
        'amount_cents' => 1000,
        'currency_code' => 'AUD',
        'commission_rate' => 10.0,
        'rate_source' => 'brand',
        'idempotency_key' => 'test-key-1',
        'occurred_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $payload = ['id' => $orderId, 'financial_status' => 'refunded', 'refunds' => []];
    $job = new ProcessShopifyOrderUpdatedWebhookJob($deletedBrandId, $payload);
    $job->handle();

    $unchanged = DB::connection('pgsql')
        ->table('commerce.commission_ledger_entries')
        ->where('shopify_order_id', $orderId)
        ->where('status', 'approved')
        ->count();

    expect($unchanged)->toBe(1);
});

it('processes refund normally when the brand professional exists', function () {
    $brand = createBrandTenant('shopify-order-brand');
    $orderId = (string) Str::uuid();

    DB::connection('pgsql')->table('commerce.commission_ledger_entries')->insert([
        'id' => (string) Str::uuid(),
        'shopify_order_id' => $orderId,
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => (string) Str::uuid(),
        'entry_type' => 'accrual',
        'status' => 'approved',
        'amount_cents' => 2000,
        'currency_code' => 'AUD',
        'commission_rate' => 10.0,
        'rate_source' => 'brand',
        'idempotency_key' => 'test-key-2',
        'occurred_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $payload = ['id' => $orderId, 'financial_status' => 'refunded', 'refunds' => []];
    $job = new ProcessShopifyOrderUpdatedWebhookJob($brand->id, $payload);
    $job->handle();

    $reversed = DB::connection('pgsql')
        ->table('commerce.commission_ledger_entries')
        ->where('shopify_order_id', $orderId)
        ->where('status', 'reversed')
        ->count();

    expect($reversed)->toBe(1);
});
