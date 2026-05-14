<?php

use App\Models\Core\Professional\ProfessionalIntegration;
use Firebase\JWT\JWT;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

// EmbeddedConnectController links a Shopify shop to a Partna brand via a
// time-limited connection code. The route uses shopify.session:lenient — the
// JWT must be valid but shop-resolution is skipped (because we're performing
// the linking right here). These tests cover the happy path, both guard-based
// 409s, and the UniqueConstraintViolationException catch that translates a
// lost cross-brand race (past the exists() guard) into a clean 409 instead
// of an ugly 500.

const CONNECT_SECRET = 'test-secret-must-be-long-enough-for-hs256-not-empty';
const CONNECT_CLIENT_ID = 'test-client-id-from-shopify-partners';
const CONNECT_SHOP = 'test-shop.myshopify.com';
const CONNECT_PROF_A = 'prof_aaaaaaa';
const CONNECT_PROF_B = 'prof_bbbbbbb';

beforeEach(function () {
    config()->set('services.shopify.api_secret', CONNECT_SECRET);
    config()->set('services.shopify.api_key', CONNECT_CLIENT_ID);
    config()->set('partna.throttle.enabled', false);

    setupProfessionalIntegrationsTable();

    Cache::flush();
});

afterEach(function () {
    // Race-path test registers a `creating` listener on ProfessionalIntegration;
    // flush it so it does not leak into subsequent tests in this process.
    ProfessionalIntegration::flushEventListeners();
});

/**
 * Build a valid signed Shopify session JWT for the lenient connect route.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeConnectToken(string $shopDomain = CONNECT_SHOP, array $overrides = []): string
{
    $now = time();
    $claims = array_merge([
        'iss' => 'https://'.$shopDomain.'/admin',
        'dest' => 'https://'.$shopDomain,
        'aud' => CONNECT_CLIENT_ID,
        'sub' => 'shopify-user-1',
        'exp' => $now + 60,
        'nbf' => $now - 5,
        'iat' => $now,
        'jti' => 'jti-'.bin2hex(random_bytes(8)),
    ], $overrides);

    return JWT::encode($claims, CONNECT_SECRET, 'HS256');
}

function seedIntegrationRow(string $professionalId, string $shopDomain): void
{
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => 'shopify',
        'external_account_id' => $shopDomain,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('rejects when the connection code is missing', function () {
    $token = makeConnectToken();

    postJson('/api/internal/embedded/connect-account', [], [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'Connection code is required.']);
});

it('rejects an invalid or expired connection code', function () {
    $token = makeConnectToken();

    postJson('/api/internal/embedded/connect-account', [
        'code' => 'does-not-exist',
    ], [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'Invalid or expired connection code. Please generate a new one from your Partna dashboard.']);
});

it('creates an integration row when the brand has no existing Shopify integration', function () {
    Cache::put('shopify:embed:connect:CODE-A', CONNECT_PROF_A, 1800);
    $token = makeConnectToken();

    postJson('/api/internal/embedded/connect-account', [
        'code' => 'CODE-A',
    ], [
        'Authorization' => "Bearer {$token}",
    ])->assertOk()
        ->assertJsonFragment(['connected' => true]);

    $row = ProfessionalIntegration::query()
        ->where('professional_id', CONNECT_PROF_A)
        ->where('provider', 'shopify')
        ->firstOrFail();

    expect($row->provider_metadata['shop_domain'])->toBe(CONNECT_SHOP);
});

it('is idempotent when the same brand reconnects the same shop', function () {
    seedIntegrationRow(CONNECT_PROF_A, CONNECT_SHOP);

    Cache::put('shopify:embed:connect:CODE-RECONNECT', CONNECT_PROF_A, 1800);
    $token = makeConnectToken();

    postJson('/api/internal/embedded/connect-account', [
        'code' => 'CODE-RECONNECT',
    ], [
        'Authorization' => "Bearer {$token}",
    ])->assertOk()
        ->assertJsonFragment(['connected' => true]);
});

it('returns 409 when another brand already owns the shop (exists guard)', function () {
    seedIntegrationRow(CONNECT_PROF_B, CONNECT_SHOP);

    Cache::put('shopify:embed:connect:CODE-TAKEN', CONNECT_PROF_A, 1800);
    $token = makeConnectToken();

    postJson('/api/internal/embedded/connect-account', [
        'code' => 'CODE-TAKEN',
    ], [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(409)
        ->assertJsonFragment(['message' => 'This Shopify store is already connected to a different Partna account.']);
});

it('returns 409 when the brand already has a different shop linked', function () {
    seedIntegrationRow(CONNECT_PROF_A, 'other-shop.myshopify.com');

    Cache::put('shopify:embed:connect:CODE-OTHER', CONNECT_PROF_A, 1800);
    $token = makeConnectToken();

    postJson('/api/internal/embedded/connect-account', [
        'code' => 'CODE-OTHER',
    ], [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(409)
        ->assertJsonFragment(['message' => 'This Partna account is already connected to other-shop.myshopify.com. Disconnect it first.']);
});

it('refuses to rebind shop_domain when the existing integration row has no shop_domain', function () {
    // Inconsistent state: a row exists from a prior partial install / manual DB
    // edit / legacy data, but provider_metadata has no shop_domain. The
    // controller MUST NOT silently attach any code-validated shop's domain to
    // this row — that would be a tenant-isolation hole (any brand with a
    // dangling shopify row could be hijacked by the next valid connect code).
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => CONNECT_PROF_A,
        'provider' => 'shopify',
        'external_account_id' => null,
        'provider_metadata' => json_encode(['scopes' => 'read_products']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Cache::put('shopify:embed:connect:CODE-PARTIAL', CONNECT_PROF_A, 1800);
    $token = makeConnectToken();

    postJson('/api/internal/embedded/connect-account', [
        'code' => 'CODE-PARTIAL',
    ], [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(409);

    // And the row was NOT mutated — shop_domain stays unset.
    $row = ProfessionalIntegration::query()
        ->where('professional_id', CONNECT_PROF_A)
        ->where('provider', 'shopify')
        ->firstOrFail();

    expect($row->provider_metadata['shop_domain'] ?? null)->toBeNull();
});

it('translates a UniqueConstraintViolationException during INSERT into a 409', function () {
    // Simulate the race: a concurrent connect for a different brand wins the
    // partial UNIQUE on shopify_shop_domain between our exists() guard and
    // our INSERT. We trigger it via the `creating` event so the test does not
    // depend on the prod-only generated column / partial index.
    ProfessionalIntegration::creating(function (): void {
        throw new UniqueConstraintViolationException(
            'pgsql',
            'INSERT INTO core.professional_integrations (...) VALUES (...)',
            [],
            new PDOException('duplicate key value violates unique constraint "professional_integrations_shopify_domain_uq"', 23505)
        );
    });

    Cache::put('shopify:embed:connect:CODE-RACE', CONNECT_PROF_A, 1800);
    $token = makeConnectToken();

    postJson('/api/internal/embedded/connect-account', [
        'code' => 'CODE-RACE',
    ], [
        'Authorization' => "Bearer {$token}",
    ])->assertStatus(409)
        ->assertJsonFragment(['message' => 'This Shopify store is already connected to a different Partna account.']);

    // And the code was consumed (lost race is non-retryable with the same code).
    expect(Cache::has('shopify:embed:connect:CODE-RACE'))->toBeFalse();
});
