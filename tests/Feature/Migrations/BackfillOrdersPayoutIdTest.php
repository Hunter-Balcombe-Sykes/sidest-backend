<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Phase 3.1 backfill regression: copy payout_id from accrual ledger entries to
// commerce.orders, idempotently. Pgsql-only because the migration uses WITH/UPDATE/FROM
// syntax that SQLite doesn't support; SQLite test runs would never exercise the real
// migration semantics.

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Backfill migration uses WITH/UPDATE/FROM (pgsql-only).');
    }
});

it('stamps commerce.orders.payout_id for accruals with order_id and a payout_id', function () {
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $payoutId = (string) Str::uuid();
    $orderId = (string) Str::uuid();

    DB::statement("INSERT INTO commerce.orders (id, shopify_order_id, shopify_shop_domain, shopify_updated_at, brand_professional_id, affiliate_professional_id, status, occurred_at) VALUES (?, '1001', 'shop.myshopify.com', now(), ?, ?, 'approved', now())", [$orderId, $brandId, $affiliateId]);
    DB::statement("INSERT INTO commerce.commission_ledger_entries (id, brand_professional_id, affiliate_professional_id, entry_type, status, amount_cents, currency_code, commission_rate, rate_source, idempotency_key, payout_id, order_id) VALUES (?, ?, ?, 'accrual', 'approved', 1000, 'AUD', 10, 'brand_default', ?, ?, ?)", [(string) Str::uuid(), $brandId, $affiliateId, 'idempo-'.Str::uuid(), $payoutId, $orderId]);

    $sql = file_get_contents(base_path('supabase/migrations/20260506400000_backfill_orders_payout_id.sql'));
    DB::unprepared($sql);

    $stamped = DB::table('commerce.orders')->where('id', $orderId)->value('payout_id');
    expect($stamped)->toBe($payoutId);
});

it('does not overwrite an existing different payout_id on commerce.orders', function () {
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $existingPayoutId = (string) Str::uuid();
    $accrualPayoutId = (string) Str::uuid();
    $orderId = (string) Str::uuid();

    DB::statement("INSERT INTO commerce.orders (id, shopify_order_id, shopify_shop_domain, shopify_updated_at, brand_professional_id, affiliate_professional_id, status, payout_id, occurred_at) VALUES (?, '1002', 'shop.myshopify.com', now(), ?, ?, 'approved', ?, now())", [$orderId, $brandId, $affiliateId, $existingPayoutId]);
    DB::statement("INSERT INTO commerce.commission_ledger_entries (id, brand_professional_id, affiliate_professional_id, entry_type, status, amount_cents, currency_code, commission_rate, rate_source, idempotency_key, payout_id, order_id) VALUES (?, ?, ?, 'accrual', 'approved', 1000, 'AUD', 10, 'brand_default', ?, ?, ?)", [(string) Str::uuid(), $brandId, $affiliateId, 'idempo-'.Str::uuid(), $accrualPayoutId, $orderId]);

    $sql = file_get_contents(base_path('supabase/migrations/20260506400000_backfill_orders_payout_id.sql'));
    DB::unprepared($sql);

    $stamped = DB::table('commerce.orders')->where('id', $orderId)->value('payout_id');
    expect($stamped)->toBe($existingPayoutId);
});

it('is idempotent — re-running does not change a stamped payout_id', function () {
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $payoutId = (string) Str::uuid();
    $orderId = (string) Str::uuid();

    DB::statement("INSERT INTO commerce.orders (id, shopify_order_id, shopify_shop_domain, shopify_updated_at, brand_professional_id, affiliate_professional_id, status, occurred_at) VALUES (?, '1003', 'shop.myshopify.com', now(), ?, ?, 'approved', now())", [$orderId, $brandId, $affiliateId]);
    DB::statement("INSERT INTO commerce.commission_ledger_entries (id, brand_professional_id, affiliate_professional_id, entry_type, status, amount_cents, currency_code, commission_rate, rate_source, idempotency_key, payout_id, order_id) VALUES (?, ?, ?, 'accrual', 'approved', 1000, 'AUD', 10, 'brand_default', ?, ?, ?)", [(string) Str::uuid(), $brandId, $affiliateId, 'idempo-'.Str::uuid(), $payoutId, $orderId]);

    $sql = file_get_contents(base_path('supabase/migrations/20260506400000_backfill_orders_payout_id.sql'));
    DB::unprepared($sql);
    DB::unprepared($sql);

    $stamped = DB::table('commerce.orders')->where('id', $orderId)->value('payout_id');
    expect($stamped)->toBe($payoutId);
});
