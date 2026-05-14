<?php

use App\Services\Shopify\ShopifyShopResolver;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

// VerifyShopifySessionToken enforces the Shopify session-token contract on
// /internal/embedded/* routes: signature, exp/nbf, aud, dest TLD, iss==dest,
// jti replay (Redis-backed fail-closed), shop resolution. Every reject path
// is a distinct reason code surfaced as `auth_<reason>` in the response body
// and `reason: <code>` in the structured warning log — see the middleware
// header comment for the full enum.

const TEST_SECRET = 'test-secret-must-be-long-enough-for-hs256-not-empty';
const TEST_CLIENT_ID = 'test-client-id-from-shopify-partners';
const TEST_SHOP = 'test-shop.myshopify.com';
const TEST_PROFESSIONAL = 'prof_test_123';

beforeEach(function () {
    config()->set('services.shopify.api_secret', TEST_SECRET);
    config()->set('services.shopify.api_key', TEST_CLIENT_ID);

    // Stub the resolver: the configured shop resolves; everything else returns null.
    $resolver = Mockery::mock(ShopifyShopResolver::class);
    $resolver->shouldReceive('resolveProfessionalId')
        ->with(TEST_SHOP)
        ->andReturn(TEST_PROFESSIONAL);
    $resolver->shouldReceive('resolveProfessionalId')
        ->andReturn(null);
    app()->instance(ShopifyShopResolver::class, $resolver);

    Route::middleware('shopify.session')
        ->get('/__test/shopify-session', fn (\Illuminate\Http\Request $r) => response()->json([
            'ok' => true,
            'professional_id' => $r->attributes->get('embedded_professional_id'),
            'shop' => $r->attributes->get('embedded_shop_domain'),
            'user' => $r->attributes->get('embedded_shopify_user_id'),
        ]));

    Cache::flush();
});

/**
 * Build a valid signed Shopify session JWT, with overrides for individual claims.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeSessionToken(array $overrides = []): string
{
    $now = time();
    $claims = array_merge([
        'iss' => 'https://'.TEST_SHOP.'/admin',
        'dest' => 'https://'.TEST_SHOP,
        'aud' => TEST_CLIENT_ID,
        'sub' => 'shopify-user-1',
        'exp' => $now + 60,
        'nbf' => $now - 5,
        'iat' => $now,
        'jti' => 'jti-'.bin2hex(random_bytes(8)),
    ], $overrides);

    return JWT::encode($claims, TEST_SECRET, 'HS256');
}

it('rejects missing Authorization header with 401 auth_token_missing', function () {
    getJson('/__test/shopify-session')
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_token_missing']);
});

it('rejects a bad signature with 401 auth_sig_invalid', function () {
    $now = time();
    $bad = JWT::encode([
        'iss' => 'https://'.TEST_SHOP.'/admin',
        'dest' => 'https://'.TEST_SHOP,
        'aud' => TEST_CLIENT_ID,
        'sub' => 's',
        'exp' => $now + 60,
        'nbf' => $now,
        'jti' => 'jti-bad',
    ], 'wrong-secret-of-sufficient-length-bytes', 'HS256');

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$bad}"])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_sig_invalid']);
});

it('rejects an expired token with 401 auth_sig_invalid', function () {
    // exp 60s in the past, clock leeway is 10s → firmly expired
    $token = makeSessionToken(['exp' => time() - 60, 'nbf' => time() - 120, 'iat' => time() - 120]);

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_sig_invalid']);
});

it('rejects wrong aud (different app client id) with 401 auth_aud_mismatch', function () {
    $token = makeSessionToken(['aud' => 'some-other-app-client-id']);

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_aud_mismatch']);
});

it('rejects dest host that does not end .myshopify.com with 401 auth_dest_invalid', function () {
    $token = makeSessionToken([
        'dest' => 'https://evil.example.com',
        'iss' => 'https://evil.example.com/admin',
    ]);

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_dest_invalid']);
});

it('rejects iss/dest host mismatch with 401 auth_iss_mismatch', function () {
    $token = makeSessionToken([
        'iss' => 'https://attacker-shop.myshopify.com/admin',
        'dest' => 'https://'.TEST_SHOP,
    ]);

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_iss_mismatch']);
});

it('rejects missing jti with 401 auth_jti_missing', function () {
    $now = time();
    // Hand-craft without jti; makeSessionToken always injects one.
    $token = JWT::encode([
        'iss' => 'https://'.TEST_SHOP.'/admin',
        'dest' => 'https://'.TEST_SHOP,
        'aud' => TEST_CLIENT_ID,
        'sub' => 's',
        'exp' => $now + 60,
        'nbf' => $now - 5,
    ], TEST_SECRET, 'HS256');

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_jti_missing']);
});

it('rejects jti replay with 401 auth_jti_replay on the second use of the same token', function () {
    // Force one-time-use for this test. In production the default is 25 to
    // allow Remix SSR loaders to share one JWT across multiple parallel calls.
    config()->set('services.shopify.jti_max_uses', 1);

    $token = makeSessionToken();

    // First use: success.
    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    // Replay within the 120s window: rejected (count=2 > max_uses=1).
    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_jti_replay']);
});

it('returns 503 auth_cache_unavailable when the JTI cache backend throws', function () {
    // The middleware calls Cache::add first (NX counter init); throwing here
    // simulates a cache backend outage before the counter is written.
    Cache::shouldReceive('add')
        ->once()
        ->andThrow(new \RuntimeException('redis unreachable'));

    $token = makeSessionToken();

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(503)
        ->assertExactJson(['message' => 'auth_cache_unavailable']);
});

it('returns 404 auth_shop_unlinked when the resolver finds no professional', function () {
    $token = makeSessionToken([
        'dest' => 'https://unlinked-shop.myshopify.com',
        'iss' => 'https://unlinked-shop.myshopify.com/admin',
    ]);

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(404)
        ->assertExactJson(['message' => 'auth_shop_unlinked']);
});

it('logs a structured reason per failure', function () {
    Log::spy();

    getJson('/__test/shopify-session');

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) {
            return $message === 'shopify.session.failed'
                && ($context['reason'] ?? null) === 'token_missing'
                && ($context['path'] ?? null) === '__test/shopify-session'
                && array_key_exists('duration_ms', $context);
        })
        ->once();
});

it('passes through on a valid token and stashes embedded_* attributes', function () {
    $token = makeSessionToken(['sub' => 'shopify-user-9']);

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertExactJson([
            'ok' => true,
            'professional_id' => TEST_PROFESSIONAL,
            'shop' => TEST_SHOP,
            'user' => 'shopify-user-9',
        ]);
});

it('logs the post-INCR jti use-counter on each success so the distribution can be observed', function () {
    // Raise the cap above the two replays this test performs so neither hits jti_replay.
    config()->set('services.shopify.jti_max_uses', 25);

    Log::spy();

    // Reuse the same JTI twice within the 120s window; counter should climb 1 → 2.
    $token = makeSessionToken();

    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])->assertOk();
    getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])->assertOk();

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) {
            return $message === 'shopify.session.ok'
                && ($context['uses'] ?? null) === 1
                && ($context['shop'] ?? null) === TEST_SHOP;
        })
        ->once();

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) {
            return $message === 'shopify.session.ok'
                && ($context['uses'] ?? null) === 2
                && ($context['shop'] ?? null) === TEST_SHOP;
        })
        ->once();
});

it(':lenient mode passes through an unlinked shop without 404', function () {
    Route::middleware('shopify.session:lenient')
        ->get('/__test/shopify-session-lenient', fn (\Illuminate\Http\Request $r) => response()->json([
            'ok' => true,
            'professional_id' => $r->attributes->get('embedded_professional_id'),
            'shop' => $r->attributes->get('embedded_shop_domain'),
        ]));

    // Use a shop that the resolver returns null for — would 404 in default mode.
    $token = makeSessionToken([
        'dest' => 'https://unlinked-shop.myshopify.com',
        'iss' => 'https://unlinked-shop.myshopify.com/admin',
    ]);

    getJson('/__test/shopify-session-lenient', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertExactJson([
            'ok' => true,
            'professional_id' => null,
            'shop' => 'unlinked-shop.myshopify.com',
        ]);
});

it(':lenient mode still rejects on JWT validation errors (bad signature → 401)', function () {
    Route::middleware('shopify.session:lenient')
        ->get('/__test/shopify-session-lenient-2', fn () => response()->json(['ok' => true]));

    $now = time();
    $bad = JWT::encode([
        'iss' => 'https://'.TEST_SHOP.'/admin',
        'dest' => 'https://'.TEST_SHOP,
        'aud' => TEST_CLIENT_ID,
        'sub' => 's',
        'exp' => $now + 60,
        'nbf' => $now,
        'jti' => 'jti-bad-lenient',
    ], 'wrong-secret-of-sufficient-length-bytes', 'HS256');

    getJson('/__test/shopify-session-lenient-2', ['Authorization' => "Bearer {$bad}"])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'auth_sig_invalid']);
});

it('throws when SHOPIFY_API_SECRET and SHOPIFY_API_KEY are unset (deploy bug, not auth failure)', function () {
    config()->set('services.shopify.api_secret', '');
    config()->set('services.shopify.api_key', '');

    // Disable Laravel's debug-mode exception handler so the RuntimeException
    // surfaces as a real exception in the test rather than a rendered 500
    // response with a debug page.
    $this->withoutExceptionHandling();

    expect(fn () => getJson('/__test/shopify-session', ['Authorization' => 'Bearer xxx']))
        ->toThrow(\RuntimeException::class, 'Shopify session token middleware misconfigured');
});

it('restores JWT::$leeway after a successful request (no static leak into the rest of the request)', function () {
    $sentinel = 42;
    $previous = JWT::$leeway;
    JWT::$leeway = $sentinel;

    try {
        $token = makeSessionToken();
        getJson('/__test/shopify-session', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        expect(JWT::$leeway)->toBe($sentinel);
    } finally {
        JWT::$leeway = $previous;
    }
});

it('restores JWT::$leeway after a rejected request (catch path must not leak the 10s drift)', function () {
    $sentinel = 42;
    $previous = JWT::$leeway;
    JWT::$leeway = $sentinel;

    try {
        $bad = JWT::encode([
            'iss' => 'https://'.TEST_SHOP.'/admin',
            'dest' => 'https://'.TEST_SHOP,
            'aud' => TEST_CLIENT_ID,
            'sub' => 's',
            'exp' => time() + 60,
            'nbf' => time(),
            'jti' => 'jti-leeway-restore',
        ], 'wrong-secret-of-sufficient-length-bytes', 'HS256');

        getJson('/__test/shopify-session', ['Authorization' => "Bearer {$bad}"])
            ->assertStatus(401)
            ->assertExactJson(['message' => 'auth_sig_invalid']);

        expect(JWT::$leeway)->toBe($sentinel);
    } finally {
        JWT::$leeway = $previous;
    }
});
