<?php

use App\Jobs\Shopify\PurgeAffiliateProductSelectionsJob;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupAffiliateProductSelectionsTable();
    setupBrandStoreSettingsTable();
    setupBrandProfilesTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

function uninstalledPayload(): array
{
    return [
        'id' => 12345678,
        'name' => 'Brand A',
        'myshopify_domain' => 'brand-a.myshopify.com',
        'domain' => 'brand-a.myshopify.com',
    ];
}

it('app/uninstalled — bad HMAC returns 401 and leaves integration intact', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', uninstalledPayload(), [
        'X-Shopify-Hmac-SHA256' => 'bad',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertStatus(401);

    $row = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($row->access_token)->toBe('shpat_alive');
});

it('app/uninstalled — valid HMAC clears access_token and marks disconnected_reason', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'refresh_token' => 'rt_alive',
        'provider_metadata' => json_encode(['some_existing' => 'value']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = uninstalledPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertOk();

    $row = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($row->access_token)->toBeNull();
    expect($row->refresh_token)->toBeNull();

    $meta = json_decode($row->provider_metadata, true);
    expect($meta['disconnected_reason'])->toBe('app_uninstalled');
    expect($meta['webhooks_state'])->toBe('uninstalled');
    expect($meta['some_existing'])->toBe('value');  // Pre-existing keys preserved.
    expect($meta['disconnected_at'])->not->toBeNull();
});

it('app/uninstalled — dispatches PurgeAffiliateProductSelectionsJob (Master Pattern 16)', function () {
    Bus::fake([PurgeAffiliateProductSelectionsJob::class]);

    $brandId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('commerce.affiliate_product_selections')->insert([
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/1', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/2', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $payload = uninstalledPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertOk();

    // Webhook hands off to the queue — rows are NOT deleted synchronously now.
    // Master Pattern 16 (DB-F#SCALE-3) avoids holding row locks across a large
    // delete inside the Shopify ack window.
    expect(AffiliateProductSelection::query()
        ->where('brand_professional_id', $brandId)
        ->count())->toBe(2);

    Bus::assertDispatched(
        PurgeAffiliateProductSelectionsJob::class,
        fn (PurgeAffiliateProductSelectionsJob $job) => $job->brandProfessionalId === $brandId
            && $job->queue === 'integrations',
    );
});

it('PurgeAffiliateProductSelectionsJob deletes all selections for the given brand', function () {
    $brandId = (string) Str::uuid();
    $otherBrandId = (string) Str::uuid();

    DB::table('commerce.affiliate_product_selections')->insert([
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/1', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/2', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $otherBrandId, 'shopify_product_gid' => 'gid://shopify/Product/3', 'created_at' => now(), 'updated_at' => now()],
    ]);

    (new PurgeAffiliateProductSelectionsJob($brandId))->handle();

    // Target brand's selections gone; other brands untouched.
    expect(AffiliateProductSelection::query()->where('brand_professional_id', $brandId)->count())->toBe(0);
    expect(AffiliateProductSelection::query()->where('brand_professional_id', $otherBrandId)->count())->toBe(1);
});

it('app/uninstalled — unknown shop_domain returns 200 without side effects', function () {
    $payload = uninstalledPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
    ])->assertOk();
});
