<?php

use App\Jobs\Shopify\CreateShopifyCollectionsJob;
use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Jobs\Shopify\CreateShopifySalesChannelJob;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\Brand\BrandStatusService;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

// Pattern B coverage — feature tests for the embedded provision-integration
// endpoint that the Partna-Shopify-App calls on every admin page load. The
// controller branches on five independent flags; before these tests landed it
// had zero feature coverage. Each test exercises one branch + asserts the
// observable side effects (jobs dispatched, status sync skipped, 422 returned).

const PROVISION_SECRET = 'test-secret-must-be-long-enough-for-hs256-not-empty';
const PROVISION_CLIENT_ID = 'test-client-id-from-shopify-partners';
const PROVISION_SHOP = 'test-shop.myshopify.com';

const PROVISION_TOKEN_OLD = 'shpat_old_existing_token_xyz';
const PROVISION_TOKEN_NEW = 'shpat_new_refreshed_token_abc';

beforeEach(function () {
    config()->set('services.shopify.api_secret', PROVISION_SECRET);
    config()->set('services.shopify.api_key', PROVISION_CLIENT_ID);
    config()->set('services.shopify.api_version', '2026-04');
    config()->set('partna.throttle.enabled', false);

    tenantHelpersEnsureTables();
    setupProfessionalIntegrationsTable();
    setupBrandProfilesTable();

    // BrandStatusService::sync writes to core.brand_status_history (not seeded);
    // ProfessionalCacheService::invalidateProfessional touches caches we don't care
    // about here. Mock both so tests assert call counts directly (e.g. proving
    // the no-op refresh short-circuits the status sync).
    $this->cacheMock = $this->mock(ProfessionalCacheService::class);
    $this->cacheMock->shouldReceive('invalidateProfessional')->byDefault();

    $this->statusMock = $this->mock(BrandStatusService::class);
    $this->statusMock->shouldReceive('sync')->byDefault();

    Cache::flush();
});

/**
 * Build a valid signed Shopify session JWT for shopify.session middleware.
 */
function makeProvisionToken(string $shopDomain = PROVISION_SHOP): string
{
    $now = time();

    return JWT::encode([
        'iss' => 'https://'.$shopDomain.'/admin',
        'dest' => 'https://'.$shopDomain,
        'aud' => PROVISION_CLIENT_ID,
        'sub' => 'shopify-user-1',
        'exp' => $now + 60,
        'nbf' => $now - 5,
        'iat' => $now,
        'jti' => 'jti-'.bin2hex(random_bytes(8)),
    ], PROVISION_SECRET, 'HS256');
}

/**
 * Seed an integration row owned by a freshly-created brand. Saving via the
 * model ensures the encrypted access_token cast runs; a second raw DB UPDATE
 * sets the generated-in-prod `shopify_shop_domain` column so the resolver
 * step of the shopify.session middleware finds the brand.
 *
 * Post-DATA-2: keys promoted to columns (disconnected_at, webhook_registration_state)
 * are pulled out of $metadataOverrides automatically and written to their respective
 * columns — keeps test bodies short and matches the on-disk shape exactly.
 *
 * @param  array<string, mixed>  $metadataOverrides  merged into provider_metadata
 */
function seedProvisionIntegration(string $handle, ?string $accessToken, array $metadataOverrides = []): ProfessionalIntegration
{
    $brand = createBrandTenant($handle);

    // Promote column-backed fields out of the metadata bag (DATA-2).
    $disconnectedAt = $metadataOverrides['disconnected_at'] ?? null;
    $webhookState = $metadataOverrides['webhook_registration_state'] ?? null;
    unset($metadataOverrides['disconnected_at'], $metadataOverrides['webhook_registration_state']);

    $integration = new ProfessionalIntegration([
        'professional_id' => $brand->id,
        'provider' => 'shopify',
        'external_account_id' => PROVISION_SHOP,
        'access_token' => $accessToken,
        'provider_metadata' => array_merge(['shop_domain' => PROVISION_SHOP], $metadataOverrides),
        'disconnected_at' => $disconnectedAt,
        'webhook_registration_state' => $webhookState,
    ]);
    $integration->id = (string) Str::uuid();
    $integration->save();

    DB::connection('pgsql')->table('core.professional_integrations')
        ->where('id', $integration->id)
        ->update(['shopify_shop_domain' => PROVISION_SHOP]);

    return $integration->fresh();
}

/**
 * provider_metadata + columns for an integration that has completed the full
 * setup — webhook registration succeeded, all four collection handles present.
 * The seedProvisionIntegration helper routes webhook_registration_state to its
 * column automatically.
 *
 * @return array<string, mixed>
 */
function fullySetupMetadata(): array
{
    return [
        'webhook_registration_state' => 'registered',
        'active_collection_handle' => 'sidest-active',
        'default_collection_handle' => 'sidest-default',
        'favourites_collection_handle' => 'sidest-favourites',
        'high_commission_collection_handle' => 'sidest-high-commission',
    ];
}

it('dispatches all six setup jobs on first provision and clears disconnected_at', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => ['myshopify_domain' => PROVISION_SHOP]], 200),
    ]);

    // Brand had previously uninstalled — disconnected_at + disconnected_reason
    // were stamped onto provider_metadata by ShopifyAppUninstalledWebhookController.
    // A fresh provision must clear both keys so BrandStatusService doesn't trap
    // the brand in Disconnected status on reinstall.
    $integration = seedProvisionIntegration('prov-brand-fresh', null, [
        'disconnected_at' => '2026-05-01T00:00:00Z',
        'disconnected_reason' => 'app_uninstalled',
    ]);

    $response = postJson('/api/internal/embedded/provision-integration', [
        'access_token' => PROVISION_TOKEN_NEW,
    ], [
        'Authorization' => 'Bearer '.makeProvisionToken(),
    ]);

    $response->assertOk()->assertJsonFragment(['provisioned' => true]);

    Bus::assertDispatched(RegisterShopifyWebhooksJob::class);
    Bus::assertDispatched(CreateStorefrontAccessTokenJob::class);
    Bus::assertDispatched(CreateShopifyMetafieldsJob::class);
    Bus::assertDispatched(CreateShopifySalesChannelJob::class);
    Bus::assertDispatched(CreateShopifyCollectionsJob::class);
    Bus::assertDispatched(SyncShopifyBrandDesignJob::class);

    $integration->refresh();
    $metadata = $integration->provider_metadata;
    // disconnected_at is a column post-DATA-2 — the reinstall path must reset it
    // and clear the reason label so BrandStatusService doesn't trap the brand in
    // Disconnected. The metadata bag never sees disconnected_at anymore.
    expect($integration->disconnected_at)->toBeNull();
    expect($metadata)->not->toHaveKey('disconnected_reason');
    expect($metadata)->not->toHaveKey('disconnected_at');
});

it('skips job dispatch on a no-op token refresh when integration is complete', function () {
    Bus::fake();

    // Http::fake() with no array intercepts every outbound request. The
    // assertion below proves the controller never even *attempted* the Shopify
    // shop.json validation call — i.e. the no-op short-circuit ran before
    // validateShopifyAccessToken(). Without the short-circuit the controller
    // would call Shopify; the call would be faked-out and the assertion would
    // fail. validateShopifyAccessToken()'s own catch-all on \Throwable means
    // we cannot rely on "no network" alone to prove the short-circuit.
    Http::fake();

    seedProvisionIntegration('prov-brand-noop', PROVISION_TOKEN_OLD, fullySetupMetadata());

    $response = postJson('/api/internal/embedded/provision-integration', [
        'access_token' => PROVISION_TOKEN_OLD,
    ], [
        'Authorization' => 'Bearer '.makeProvisionToken(),
    ]);

    $response->assertOk()->assertJsonFragment(['provisioned' => true]);

    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

it('re-dispatches setup jobs when webhook_registration_state is queued', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => ['myshopify_domain' => PROVISION_SHOP]], 200),
    ]);

    // Previous provision left webhooks in the 'queued' state — likely all jobs
    // failed (e.g. bad token at the time). All collection handles are present,
    // but the queued webhook state alone should re-trigger the whole pipeline.
    seedProvisionIntegration('prov-brand-queued', PROVISION_TOKEN_OLD, array_merge(
        fullySetupMetadata(),
        ['webhook_registration_state' => 'queued'],
    ));

    postJson('/api/internal/embedded/provision-integration', [
        'access_token' => PROVISION_TOKEN_OLD,
    ], [
        'Authorization' => 'Bearer '.makeProvisionToken(),
    ])->assertOk();

    Bus::assertDispatched(RegisterShopifyWebhooksJob::class);
    Bus::assertDispatched(CreateShopifyCollectionsJob::class);
});

it('re-dispatches when any collection handle is missing (partial setup)', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => ['myshopify_domain' => PROVISION_SHOP]], 200),
    ]);

    // Smart-collection race: default + favourites + active landed but
    // high_commission_collection_handle is missing. The pre-fix `empty(active)
    // && empty(default)` check missed this state — the new per-handle test
    // catches it.
    seedProvisionIntegration('prov-brand-partial', PROVISION_TOKEN_OLD, [
        'webhook_registration_state' => 'registered',
        'active_collection_handle' => 'sidest-active',
        'default_collection_handle' => 'sidest-default',
        'favourites_collection_handle' => 'sidest-favourites',
        // high_commission_collection_handle absent
    ]);

    postJson('/api/internal/embedded/provision-integration', [
        'access_token' => PROVISION_TOKEN_OLD,
    ], [
        'Authorization' => 'Bearer '.makeProvisionToken(),
    ])->assertOk();

    Bus::assertDispatched(CreateShopifyCollectionsJob::class);
    Bus::assertDispatched(CreateShopifyMetafieldsJob::class);
});

it('returns 422 shopify_token_rejected and does not overwrite the stored token on Shopify 401', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response('Unauthorized', 401),
    ]);

    $integration = seedProvisionIntegration('prov-brand-401', PROVISION_TOKEN_OLD, fullySetupMetadata());

    $response = postJson('/api/internal/embedded/provision-integration', [
        'access_token' => PROVISION_TOKEN_NEW,
    ], [
        'Authorization' => 'Bearer '.makeProvisionToken(),
    ]);

    $response->assertStatus(422)->assertJsonFragment(['reason' => 'shopify_token_rejected']);

    // Stored token unchanged — refusing to overwrite a working credential with
    // a rejected one is the core defence here.
    $integration->refresh();
    expect($integration->access_token)->toBe(PROVISION_TOKEN_OLD);

    Bus::assertNothingDispatched();
});

it('returns 422 shopify_token_rejected on a shop domain mismatch', function () {
    Bus::fake();
    Http::fake([
        // Shopify accepts the token but reports a different shop — classic
        // cross-shop substitution (shop A submits shop B's access token).
        '*/admin/api/*/shop.json' => Http::response([
            'shop' => ['myshopify_domain' => 'wrong-shop.myshopify.com'],
        ], 200),
    ]);

    $integration = seedProvisionIntegration('prov-brand-mismatch', PROVISION_TOKEN_OLD, fullySetupMetadata());

    $response = postJson('/api/internal/embedded/provision-integration', [
        'access_token' => PROVISION_TOKEN_NEW,
    ], [
        'Authorization' => 'Bearer '.makeProvisionToken(),
    ]);

    $response->assertStatus(422)->assertJsonFragment(['reason' => 'shopify_token_rejected']);

    $integration->refresh();
    expect($integration->access_token)->toBe(PROVISION_TOKEN_OLD);

    Bus::assertNothingDispatched();
});

it('skips cache invalidation and status sync on a no-op refresh', function () {
    Bus::fake();
    Http::fake();

    // Strict expectations: neither side-effect should run when nothing changed.
    $this->cacheMock->shouldReceive('invalidateProfessional')->never();
    $this->statusMock->shouldReceive('sync')->never();

    seedProvisionIntegration('prov-brand-skip-sync', PROVISION_TOKEN_OLD, fullySetupMetadata());

    postJson('/api/internal/embedded/provision-integration', [
        'access_token' => PROVISION_TOKEN_OLD,
    ], [
        'Authorization' => 'Bearer '.makeProvisionToken(),
    ])->assertOk();

    // Same short-circuit assertion as the dedicated no-op test: prove the
    // Shopify validation call never happened.
    Http::assertNothingSent();
});
