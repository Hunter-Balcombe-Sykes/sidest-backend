<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Race 3: orders/edited or orders/cancelled arrives BEFORE orders/paid.
// Both cases should insert a stub with the appropriate status and commission=0.
// Skips if no affiliate in payload (per Decision 2: skip + log).

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupCommerceOrdersTables();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

function insertBrandWithAffiliate(string &$brandId, string &$affiliateId): void
{
    $now = now()->toDateTimeString();
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        ['id' => $brandId, 'handle' => 'brand-x', 'handle_lc' => 'brand-x', 'display_name' => 'Brand X', 'created_at' => $now, 'updated_at' => $now],
        ['id' => $affiliateId, 'handle' => 'aff-x', 'handle_lc' => 'aff-x', 'display_name' => 'Affiliate X', 'created_at' => $now, 'updated_at' => $now],
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
        'provider' => \App\Models\Core\Professional\ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-x.myshopify.com',
        'access_token' => 'shpat_test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('orders/edited first-seen with affiliate — inserts stub with status=stub', function () {
    $brandId = '';
    $affiliateId = '';
    insertBrandWithAffiliate($brandId, $affiliateId);

    $editedPayload = [
        'id' => 'ORDER-EDIT-FIRST',
        'domain' => 'brand-x.myshopify.com',
        'updated_at' => '2026-05-01T10:30:00+00:00',
        'line_items' => [],
        'note_attributes' => [['name' => 'affiliate', 'value' => 'aff-x']],
    ];

    $job = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $editedPayload, 'orders/edited', 'evt-edit-1');
    app()->call([$job, 'handle']);

    $stub = DB::table('commerce.orders')
        ->where('shopify_order_id', 'ORDER-EDIT-FIRST')
        ->first();

    expect($stub)->not->toBeNull();
    expect($stub->status)->toBe('stub');
    expect((int) $stub->commission_cents)->toBe(0);
    expect($stub->rate_source)->toBe('pending');
});

it('orders/cancelled first-seen with affiliate — inserts stub with status=cancelled', function () {
    $brandId = '';
    $affiliateId = '';
    insertBrandWithAffiliate($brandId, $affiliateId);

    $cancelledPayload = [
        'id' => 'ORDER-CANCEL-FIRST',
        'domain' => 'brand-x.myshopify.com',
        'updated_at' => '2026-05-01T10:45:00+00:00',
        'line_items' => [],
        'note_attributes' => [['name' => 'affiliate', 'value' => 'aff-x']],
    ];

    $job = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $cancelledPayload, 'orders/cancelled', 'evt-cancel-1');
    app()->call([$job, 'handle']);

    $stub = DB::table('commerce.orders')
        ->where('shopify_order_id', 'ORDER-CANCEL-FIRST')
        ->first();

    expect($stub)->not->toBeNull();
    expect($stub->status)->toBe('cancelled');
    expect((int) $stub->commission_cents)->toBe(0);
});

it('orders/edited first-seen WITHOUT affiliate — no stub, warning logged', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'affiliate not resolvable'));

    $brandId = '';
    $affiliateId = '';
    insertBrandWithAffiliate($brandId, $affiliateId);

    $editedPayload = [
        'id' => 'ORDER-EDIT-NO-AFF',
        'domain' => 'brand-x.myshopify.com',
        'updated_at' => '2026-05-01T10:30:00+00:00',
        'line_items' => [],
        'note_attributes' => [],  // no affiliate
    ];

    $job = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $editedPayload, 'orders/edited', 'evt-edit-noaff');
    app()->call([$job, 'handle']);

    $count = DB::table('commerce.orders')
        ->where('shopify_order_id', 'ORDER-EDIT-NO-AFF')
        ->count();

    expect($count)->toBe(0);
});
