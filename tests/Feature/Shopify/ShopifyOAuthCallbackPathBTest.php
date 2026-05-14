<?php

use App\Http\Controllers\Api\Shopify\ShopifyAppOAuthController;
use App\Services\Shopify\BrandSignupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Path B (auto-link Shopify install to a Professional whose primary_email matches
// the Shopify shop.email) was removed for SEC-B#3 / SEC-F#1: the Shopify-controlled
// shop.email field is editable by any store admin and cannot prove ownership of a
// Partna account. Email-match installs now fall through to Path C — the setup-token
// flow, which forces Supabase JWT auth before the integration is linked.

// Builds a valid Shopify OAuth callback request with a correctly computed HMAC.
function makeShopifyCallbackRequest(string $shop, string $nonce, string $secret): Request
{
    $params = [
        'code' => 'authcode123',
        'shop' => $shop,
        'state' => $nonce,
        'timestamp' => '1713600000',
    ];
    ksort($params);
    $message = http_build_query($params);
    $hmac = hash_hmac('sha256', $message, $secret);
    $params['hmac'] = $hmac;

    return Request::create('/api/shopify/callback?'.http_build_query($params), 'GET');
}

beforeEach(function () {
    // Override pgsql to SQLite in-memory (same pattern as BrandBootstrapTest)
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        'services.shopify.api_secret' => 'test-shopify-secret',
        'services.shopify.api_key' => 'test-api-key',
        'services.shopify.api_version' => '2024-01',
        'services.shopify.app_handle' => 'side-st',
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-key',
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    foreach (['core', 'site', 'brand', 'notifications', 'billing'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    // Schema-prefixed tables — use shared helper to avoid DDL drift
    setupProfessionalsTable();

    // Needed for Path A check (must be empty to reach Path C in these tests)
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        shopify_shop_domain TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('does NOT auto-link when shop email matches an existing professional — falls through to Path C', function () {
    $shop = 'matching-shop.myshopify.com';
    $shopEmail = 'owner@matchedshop.com';
    $nonce = 'testnonce_'.Str::random(8);
    $secret = config('services.shopify.api_secret');

    // Seed a professional whose primary_email matches the Shopify store email —
    // pre-fix code would silently link this shop into Josh's account. New behavior:
    // ignore the email match and force the setup-token + Supabase-auth flow.
    $proId = Str::uuid()->toString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'auth_user_id' => 'supabase-uid-abc',
        'handle' => 'matchedowner',
        'handle_lc' => 'matchedowner',
        'display_name' => 'Matched Owner',
        'primary_email' => $shopEmail,
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Http::fake([
        "https://{$shop}/admin/oauth/access_token" => Http::response(['access_token' => 'shpat_fake'], 200),
        "https://{$shop}/admin/api/*/shop.json" => Http::response(['shop' => ['email' => $shopEmail, 'id' => 99]], 200),
    ]);

    // handleExistingBrandConnect must never be invoked — it was deleted along with Path B.
    $brandSignup = Mockery::mock(BrandSignupService::class);
    $brandSignup->shouldNotReceive('handleExistingBrandConnect');
    app()->instance(BrandSignupService::class, $brandSignup);

    cache()->put("shopify_oauth_nonce_{$shop}", $nonce, now()->addMinutes(10));

    $request = makeShopifyCallbackRequest($shop, $nonce, $secret);
    $controller = app(ShopifyAppOAuthController::class);
    $response = $controller->callback($request);

    expect($response->getStatusCode())->toBe(302);
    // Path C redirect — to the setup wizard with a token. Supabase auth happens there.
    expect($response->headers->get('Location'))->toContain('shopify_setup_token');
});

it('falls through to Path C when shop email does not match any professional primary_email', function () {
    $shop = 'nomatch-shop.myshopify.com';
    $shopEmail = 'nomatch@shopify.com';
    $nonce = 'testnonce_'.Str::random(8);
    $secret = config('services.shopify.api_secret');

    Http::fake([
        "https://{$shop}/admin/oauth/access_token" => Http::response(['access_token' => 'shpat_fake'], 200),
        "https://{$shop}/admin/api/*/shop.json" => Http::response(['shop' => ['email' => $shopEmail, 'id' => 88]], 200),
    ]);

    $brandSignup = Mockery::mock(BrandSignupService::class);
    $brandSignup->shouldNotReceive('handleExistingBrandConnect');
    app()->instance(BrandSignupService::class, $brandSignup);

    cache()->put("shopify_oauth_nonce_{$shop}", $nonce, now()->addMinutes(10));

    $request = makeShopifyCallbackRequest($shop, $nonce, $secret);
    $controller = app(ShopifyAppOAuthController::class);
    $response = $controller->callback($request);

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toContain('shopify_setup_token');
});

it('falls through to Path C when matching professional is soft-deleted', function () {
    $shop = 'deleted-shop.myshopify.com';
    $shopEmail = 'deleted@example.com';
    $nonce = 'testnonce_'.Str::random(8);
    $secret = config('services.shopify.api_secret');

    // Seed a soft-deleted professional with a matching email — should be ignored
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => Str::uuid()->toString(),
        'auth_user_id' => 'supabase-uid-deleted',
        'handle' => 'deletedowner',
        'handle_lc' => 'deletedowner',
        'display_name' => 'Deleted Owner',
        'primary_email' => $shopEmail,
        'professional_type' => 'brand',
        'status' => 'active',
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Http::fake([
        "https://{$shop}/admin/oauth/access_token" => Http::response(['access_token' => 'shpat_fake'], 200),
        "https://{$shop}/admin/api/*/shop.json" => Http::response(['shop' => ['email' => $shopEmail, 'id' => 77]], 200),
    ]);

    $brandSignup = Mockery::mock(BrandSignupService::class);
    $brandSignup->shouldNotReceive('handleExistingBrandConnect');
    app()->instance(BrandSignupService::class, $brandSignup);

    cache()->put("shopify_oauth_nonce_{$shop}", $nonce, now()->addMinutes(10));

    $request = makeShopifyCallbackRequest($shop, $nonce, $secret);
    $controller = app(ShopifyAppOAuthController::class);
    $response = $controller->callback($request);

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toContain('shopify_setup_token');
});
