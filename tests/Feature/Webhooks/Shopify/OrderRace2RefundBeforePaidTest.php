<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Race 2: refunds/create arrives BEFORE orders/paid.
// Case A: affiliate is in note_attributes → stub inserted with status='stub', commission=0, rate_source='pending'.
// Case B: no affiliate in payload → no stub inserted, warning logged.

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupCommerceOrdersTables();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

it('refunds/create first-seen with affiliate in payload — inserts stub with status=stub commission=0', function () {
    $now = now()->toDateTimeString();
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        ['id' => $brandId, 'handle' => 'brand-a', 'handle_lc' => 'brand-a', 'display_name' => 'Brand A', 'created_at' => $now, 'updated_at' => $now],
        ['id' => $affiliateId, 'handle' => 'affiliate-a', 'handle_lc' => 'affiliate-a', 'display_name' => 'Affiliate A', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('core.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // refunds/create payload before orders/paid exists in DB.
    $refundPayload = [
        'id' => '555666',
        'order_id' => 'ORDER-NEW-999',
        'created_at' => '2026-05-01T11:00:00+00:00',
        'note' => null,
        'refund_line_items' => [
            ['id' => '701', 'line_item_id' => '5001', 'quantity' => 1, 'subtotal' => '25.00'],
        ],
        'note_attributes' => [
            ['name' => 'affiliate', 'value' => 'affiliate-a'],
        ],
    ];

    $job = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $refundPayload, 'refunds/create', 'evt-race2a');
    app()->call([$job, 'handle']);

    // A stub row should exist with status='stub', commission_cents=0, rate_source='pending'.
    $stub = DB::table('commerce.orders')
        ->where('shopify_order_id', 'ORDER-NEW-999')
        ->where('shopify_shop_domain', 'brand-a.myshopify.com')
        ->first();

    expect($stub)->not->toBeNull();
    expect($stub->status)->toBe('stub');
    expect((int) $stub->commission_cents)->toBe(0);
    expect($stub->rate_source)->toBe('pending');
    expect((int) $stub->refund_cents)->toBe(2500);  // $25 refund in cents
});

it('refunds/create first-seen WITHOUT affiliate — no stub inserted, warning logged', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'affiliate not resolvable'));

    $now = now()->toDateTimeString();
    $brandId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $brandId, 'handle' => 'brand-a', 'handle_lc' => 'brand-a',
        'display_name' => 'Brand A', 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Refund payload WITHOUT affiliate attribute.
    $refundPayload = [
        'id' => '555777',
        'order_id' => 'ORDER-NO-AFFILIATE',
        'created_at' => '2026-05-01T11:00:00+00:00',
        'refund_line_items' => [
            ['id' => '801', 'line_item_id' => '5001', 'quantity' => 1, 'subtotal' => '25.00'],
        ],
        'note_attributes' => [],  // no affiliate
    ];

    $job = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $refundPayload, 'refunds/create', 'evt-race2b');
    app()->call([$job, 'handle']);

    // No row should be inserted for this order.
    $count = DB::table('commerce.orders')
        ->where('shopify_order_id', 'ORDER-NO-AFFILIATE')
        ->count();

    expect($count)->toBe(0);
});
