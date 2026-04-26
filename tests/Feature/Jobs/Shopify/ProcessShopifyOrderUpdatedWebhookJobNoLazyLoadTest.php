<?php

/** @phpstan-ignore-all */

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Phase 0's preventLazyLoading (AppServiceProvider::boot) makes this a regression
// test for N+1 on affiliate display_name — if the affiliate relation isn't
// preloaded, observer's notifyBrandSale will throw LazyLoadingViolationException.

beforeEach(function () {
    DB::purge('pgsql');

    $conn = DB::connection('pgsql');
    foreach (['core', 'commerce'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY, handle TEXT, handle_lc TEXT, display_name TEXT,
        professional_type TEXT, status TEXT DEFAULT "active",
        primary_email TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
        id TEXT PRIMARY KEY,
        shopify_order_id TEXT, brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        entry_type TEXT, status TEXT,
        amount_cents INTEGER, currency_code TEXT,
        commission_rate REAL, rate_source TEXT,
        idempotency_key TEXT UNIQUE,
        calculation_metadata TEXT,
        occurred_at TEXT, payout_id TEXT, voided_at TEXT, void_reason TEXT,
        created_at TEXT, updated_at TEXT
    )');

    Queue::fake();

    // Silence the observer's publish() calls — notifications schema isn't
    // created in this in-memory SQLite setup. We only care that the relation
    // access doesn't trigger a lazy-load violation.
    app()->instance(NotificationPublisher::class, Mockery::mock(NotificationPublisher::class)->shouldIgnoreMissing());
});

function seedForUpdatedJobNoLazyLoadTest(): array
{
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $orderId = (string) Str::uuid();
    $conn = DB::connection('pgsql');

    $conn->table('core.professionals')->insert([
        'id' => $brandId, 'handle' => 'brand-updated', 'handle_lc' => 'brand-updated',
        'display_name' => 'Brand Updated', 'professional_type' => 'brand', 'status' => 'active',
    ]);
    $conn->table('core.professionals')->insert([
        'id' => $affiliateId, 'handle' => 'sam-affiliate', 'handle_lc' => 'sam-affiliate',
        'display_name' => 'Sam Affiliate', 'professional_type' => 'professional', 'status' => 'active',
    ]);

    // Two approved accrual entries — both will generate reversal entries when a
    // partial refund payload covers their line items.
    $conn->table('commerce.commission_ledger_entries')->insert([
        'id' => (string) Str::uuid(),
        'shopify_order_id' => $orderId,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'entry_type' => 'accrual',
        'status' => 'approved',
        'amount_cents' => 1000,
        'currency_code' => 'AUD',
        'commission_rate' => 10.0,
        'rate_source' => 'brand',
        'idempotency_key' => 'accrual-line-1-'.$orderId,
        'calculation_metadata' => json_encode(['line_item_id' => 'line_1']),
        'occurred_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    $conn->table('commerce.commission_ledger_entries')->insert([
        'id' => (string) Str::uuid(),
        'shopify_order_id' => $orderId,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'entry_type' => 'accrual',
        'status' => 'approved',
        'amount_cents' => 800,
        'currency_code' => 'AUD',
        'commission_rate' => 10.0,
        'rate_source' => 'brand',
        'idempotency_key' => 'accrual-line-2-'.$orderId,
        'calculation_metadata' => json_encode(['line_item_id' => 'line_2']),
        'occurred_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return [$brandId, $affiliateId, $orderId];
}

it('creates multiple reversal entries without triggering a lazy-load violation on the affiliate relation', function () {
    [$brandId, , $orderId] = seedForUpdatedJobNoLazyLoadTest();

    $refundId = 'refund_001';

    // Partial-refund payload covering both line items — produces 2 reversal entries,
    // each of which fires the observer's created() → notifyBrandSale() path.
    $payload = [
        'id' => $orderId,
        'financial_status' => 'partially_refunded',
        'refunds' => [
            [
                'id' => $refundId,
                'refund_line_items' => [
                    ['line_item_id' => 'line_1', 'subtotal' => 100.00],
                    ['line_item_id' => 'line_2', 'subtotal' => 80.00],
                ],
            ],
        ],
    ];

    $job = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $payload);

    // Dispatching synchronously. preventLazyLoading is active in non-production
    // (including tests) — any unloaded relation access inside the observer throws.
    $job->handle();

    // Sanity: both reversal entries were created, confirming the loop ran twice.
    $count = CommissionLedgerEntry::query()->where('entry_type', 'reversal')->count();
    expect($count)->toBe(2);

    // Primary assertion: reaching here without LazyLoadingViolationException
    // confirms the affiliate relation was preloaded before observer execution.
    expect(true)->toBeTrue();
});
