<?php

use App\Enums\BrandStatus;
use App\Jobs\Shopify\ReconcileStuckShopifyIntegrationsJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// LIFE-1: Daily reconciliation for Shopify integrations whose app/uninstalled
// webhook was lost (Shopify's at-least-once delivery is occasionally zero). The
// job HEAD-checks every connected integration's access token against the Admin
// API; on 401 or shop-domain mismatch it auto-heals state into Disconnected.
// Transient outages (5xx, network errors) leave state alone — we don't punish
// merchants for Shopify hiccups.

beforeEach(function () {
    setupProfessionalIntegrationsTable();
    setupBrandProfilesTable();
    Config::set('services.shopify.api_version', '2026-04');
});

function reconcileSeedIntegration(array $overrides): string
{
    $proId = $overrides['professional_id'] ?? (string) Str::uuid();
    $brandStatus = $overrides['_brand_status'] ?? BrandStatus::ShopifyConfigured->value;
    unset($overrides['_brand_status']);

    // Seed via the model so 'encrypted' casts on access_token / refresh_token write
    // the correct ciphertext — DB::table::insert would store plaintext, which then
    // throws DecryptException when the job reads the attribute via the cast.
    //
    // forceFill is required because shopify_shop_domain is intentionally non-fillable
    // on the model (set by service code, not by user-supplied input) — mass assignment
    // silently drops it otherwise.
    $defaults = [
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'refresh_token' => null,
        'provider_metadata' => [],
    ];
    $integration = (new ProfessionalIntegration)->forceFill(array_merge($defaults, $overrides));
    $integration->id = (string) Str::uuid();
    $integration->save();

    DB::table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'brand_status' => $brandStatus,
        'setup_complete' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $proId;
}

it('heals an integration whose access token Shopify revoked (401)', function () {
    Http::fake([
        'brand-a.myshopify.com/*' => Http::response('', 401),
    ]);

    $proId = reconcileSeedIntegration([
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_revoked',
    ]);

    (new ReconcileStuckShopifyIntegrationsJob)->handle();

    $integration = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($integration->access_token)->toBeNull();
    expect($integration->refresh_token)->toBeNull();

    $meta = json_decode($integration->provider_metadata, true);
    expect($meta['disconnected_reason'])->toBe('reconcile_detected_revocation');
    expect($meta['reconcile_detection_signal'])->toBe('invalid_token');
    expect($meta['disconnected_at'])->not->toBeNull();

    $brand = DB::table('brand.brand_profiles')->where('professional_id', $proId)->first();
    expect($brand->brand_status)->toBe(BrandStatus::Disconnected->value);
    expect((int) $brand->setup_complete)->toBe(0);
});

it('heals an integration where the Admin API returns a different myshopify_domain than expected', function () {
    Http::fake([
        'brand-a.myshopify.com/*' => Http::response([
            'shop' => ['myshopify_domain' => 'attacker-shop.myshopify.com'],
        ], 200),
    ]);

    $proId = reconcileSeedIntegration([
        'shopify_shop_domain' => 'brand-a.myshopify.com',
    ]);

    (new ReconcileStuckShopifyIntegrationsJob)->handle();

    $integration = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($integration->access_token)->toBeNull();

    $meta = json_decode($integration->provider_metadata, true);
    expect($meta['reconcile_detection_signal'])->toBe('shop_domain_mismatch');
    expect($meta['disconnected_at'])->not->toBeNull();
});

it('leaves a healthy integration untouched', function () {
    Http::fake([
        'brand-a.myshopify.com/*' => Http::response([
            'shop' => ['myshopify_domain' => 'brand-a.myshopify.com'],
        ], 200),
    ]);

    $proId = reconcileSeedIntegration([
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_healthy',
    ]);

    (new ReconcileStuckShopifyIntegrationsJob)->handle();

    // Read via the model so the 'encrypted' cast decrypts the stored ciphertext
    // back to the plaintext token we seeded.
    $integration = ProfessionalIntegration::query()->where('professional_id', $proId)->first();
    expect($integration->access_token)->toBe('shpat_healthy');
    expect($integration->provider_metadata['disconnected_at'] ?? null)->toBeNull();

    $brand = DB::table('brand.brand_profiles')->where('professional_id', $proId)->first();
    expect($brand->brand_status)->toBe(BrandStatus::ShopifyConfigured->value);
});

it('leaves a transient-outage integration untouched (5xx)', function () {
    Http::fake([
        'brand-a.myshopify.com/*' => Http::response('', 503),
    ]);

    $proId = reconcileSeedIntegration([
        'shopify_shop_domain' => 'brand-a.myshopify.com',
    ]);

    (new ReconcileStuckShopifyIntegrationsJob)->handle();

    $integration = ProfessionalIntegration::query()->where('professional_id', $proId)->first();
    expect($integration->access_token)->not->toBeNull();
    expect($integration->provider_metadata['disconnected_at'] ?? null)->toBeNull();
});

it('skips integrations already marked disconnected (no Admin API call)', function () {
    Http::fake();  // Catches any unexpected call as an empty 200, but we'll assert nothing was called.

    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_already_handled',  // Stale token left around — sanity check it isn't re-cleared.
        'provider_metadata' => json_encode([
            'disconnected_at' => '2026-05-10T00:00:00+00:00',
            'disconnected_reason' => 'app_uninstalled',
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new ReconcileStuckShopifyIntegrationsJob)->handle();

    Http::assertNothingSent();
});

it('skips integrations with null access_token (never finished OAuth)', function () {
    Http::fake();

    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => null,
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new ReconcileStuckShopifyIntegrationsJob)->handle();

    Http::assertNothingSent();
});
