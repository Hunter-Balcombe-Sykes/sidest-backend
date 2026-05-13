<?php

use App\Services\Shopify\ShopifyShopResolver;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

// EmbeddedDualAuth dispatches each /internal/embedded/* request to either
// VerifyShopifySessionToken (Bearer with 3 dot-separated segments — JWT shape)
// or VerifyEmbeddedApiKey (anything else — static-key shape). The structural
// hint is not a security boundary; the sub-middleware still validates the
// token cryptographically. Each request is logged with the chosen path so we
// can watch static_key counts drop to zero before deleting the static path.

const DUAL_TEST_SECRET = 'dual-test-secret-must-be-long-enough-for-hs256';
const DUAL_TEST_CLIENT_ID = 'dual-test-client-id';
const DUAL_TEST_STATIC_KEY = 'dual-test-static-key-value';
const DUAL_TEST_SHOP = 'dual-test-shop.myshopify.com';
const DUAL_TEST_PROFESSIONAL = 'prof_dual_123';

beforeEach(function () {
    config()->set('services.shopify.api_secret', DUAL_TEST_SECRET);
    config()->set('services.shopify.api_key', DUAL_TEST_CLIENT_ID);
    config()->set('services.embedded.api_key', DUAL_TEST_STATIC_KEY);

    $resolver = Mockery::mock(ShopifyShopResolver::class);
    $resolver->shouldReceive('resolveProfessionalId')
        ->with(DUAL_TEST_SHOP)
        ->andReturn(DUAL_TEST_PROFESSIONAL);
    $resolver->shouldReceive('resolveProfessionalId')
        ->andReturn(null);
    app()->instance(ShopifyShopResolver::class, $resolver);

    Route::middleware('embedded.dual')
        ->get('/__test/dual-auth', fn (\Illuminate\Http\Request $r) => response()->json([
            'ok' => true,
            'professional_id' => $r->attributes->get('embedded_professional_id'),
        ]));

    Cache::flush();
});

function makeDualJwt(array $overrides = []): string
{
    $now = time();

    return JWT::encode(array_merge([
        'iss' => 'https://'.DUAL_TEST_SHOP.'/admin',
        'dest' => 'https://'.DUAL_TEST_SHOP,
        'aud' => DUAL_TEST_CLIENT_ID,
        'sub' => 'shopify-user-1',
        'exp' => $now + 60,
        'nbf' => $now - 5,
        'iat' => $now,
        'jti' => 'jti-'.bin2hex(random_bytes(8)),
    ], $overrides), DUAL_TEST_SECRET, 'HS256');
}

it('routes a 3-segment Bearer (JWT shape) to VerifyShopifySessionToken', function () {
    $jwt = makeDualJwt();

    getJson('/__test/dual-auth', ['Authorization' => "Bearer {$jwt}"])
        ->assertOk()
        ->assertJson(['professional_id' => DUAL_TEST_PROFESSIONAL]);
});

it('routes a 1-segment Bearer (static-key shape) to VerifyEmbeddedApiKey', function () {
    getJson('/__test/dual-auth', [
        'Authorization' => 'Bearer '.DUAL_TEST_STATIC_KEY,
        'X-Shopify-Shop' => DUAL_TEST_SHOP,
    ])
        ->assertOk()
        ->assertJson(['professional_id' => DUAL_TEST_PROFESSIONAL]);
});

it('logs auth_path session_token when the Bearer looks like a JWT', function () {
    Log::spy();

    $jwt = makeDualJwt();
    getJson('/__test/dual-auth', ['Authorization' => "Bearer {$jwt}"]);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'embedded.dual_auth.routed'
            && ($ctx['auth_path'] ?? null) === 'session_token')
        ->once();
});

it('logs auth_path static_key when the Bearer does not look like a JWT', function () {
    Log::spy();

    getJson('/__test/dual-auth', [
        'Authorization' => 'Bearer '.DUAL_TEST_STATIC_KEY,
        'X-Shopify-Shop' => DUAL_TEST_SHOP,
    ]);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'embedded.dual_auth.routed'
            && ($ctx['auth_path'] ?? null) === 'static_key')
        ->once();
});

it('propagates JWT-path rejection reasons (bad signature → 401 auth_sig_invalid)', function () {
    $now = time();
    $bad = JWT::encode([
        'iss' => 'https://'.DUAL_TEST_SHOP.'/admin',
        'dest' => 'https://'.DUAL_TEST_SHOP,
        'aud' => DUAL_TEST_CLIENT_ID,
        'sub' => 's',
        'exp' => $now + 60,
        'nbf' => $now,
        'jti' => 'jti-bad',
    ], 'wrong-secret-of-similar-length-bytes', 'HS256');

    getJson('/__test/dual-auth', ['Authorization' => "Bearer {$bad}"])
        ->assertStatus(401)
        ->assertJson(['message' => 'auth_sig_invalid']);
});

it('propagates static-key-path rejection (wrong key → 403)', function () {
    getJson('/__test/dual-auth', [
        'Authorization' => 'Bearer wrong-static-key',
        'X-Shopify-Shop' => DUAL_TEST_SHOP,
    ])->assertStatus(403);
});
