<?php

use App\Http\Controllers\Api\Professional\ShopifyIntegration\ShopifyIntegrationController;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyTeardownService;
use App\Services\Store\BrandAccessService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupProfessionalIntegrationsTable();

    $this->mock(BrandAccessService::class, function ($mock) {
        $mock->shouldReceive('isBrandProfessional')->andReturn(true);
        $mock->shouldReceive('canManageShopify')->andReturn(true);
    });
    $this->mock(ShopifyTeardownService::class);
});

/**
 * @param  array<string, mixed>  $metadata  extra provider_metadata JSONB keys (per-step states, etc.)
 * @param  ?string  $webhookState  webhook_registration_state column value (post-DATA-2 — the
 *                                 canonical webhook-pipeline gate, no longer a JSONB key)
 */
function seedInstallIntegration(
    string $professionalId,
    array $metadata = [],
    ?string $webhookState = null,
): ProfessionalIntegration {
    // Use model save so the encrypted access_token cast is applied correctly.
    $integration = new ProfessionalIntegration([
        'professional_id' => $professionalId,
        'provider' => 'shopify',
        'external_account_id' => 'test-shop.myshopify.com',
        'access_token' => 'shpat_test',
        'provider_metadata' => array_merge([
            'shop_domain' => 'test-shop.myshopify.com',
        ], $metadata),
        'webhook_registration_state' => $webhookState,
    ]);
    $integration->id = (string) Str::uuid();
    $integration->save();

    return $integration;
}

// ── shopifyInstallStatus() unit tests ────────────────────────────────────────

it('returns pending when no step states are set', function () {
    $brand = createBrandTenant('setup-state-brand-1');
    $integration = seedInstallIntegration($brand->id);

    $status = $integration->shopifyInstallStatus();

    expect($status['state'])->toBe('pending');
    expect($status['steps']['webhooks'])->toBeNull();
    expect($status['steps']['storefront_token'])->toBeNull();
    expect($status['steps']['brand_design'])->toBeNull();
});

it('returns complete when all five steps succeed', function () {
    $brand = createBrandTenant('setup-state-brand-2');
    $integration = seedInstallIntegration($brand->id, [
        'metafield_definitions_state' => 'registered',
        'sales_channel_state' => 'registered',
        'storefront_token_state' => 'registered',
        'brand_design_state' => 'synced',
    ], webhookState: 'registered');

    $status = $integration->shopifyInstallStatus();

    expect($status['state'])->toBe('complete');
});

it('returns incomplete when any step is failed', function () {
    $brand = createBrandTenant('setup-state-brand-3');
    $integration = seedInstallIntegration($brand->id, [
        'metafield_definitions_state' => 'failed',
        'sales_channel_state' => 'registered',
        'storefront_token_state' => 'registered',
        'brand_design_state' => 'synced',
    ], webhookState: 'registered');

    $status = $integration->shopifyInstallStatus();

    expect($status['state'])->toBe('incomplete');
});

it('returns incomplete when webhook_registration_state is partial', function () {
    $brand = createBrandTenant('setup-state-brand-4');
    $integration = seedInstallIntegration($brand->id, [
        'metafield_definitions_state' => 'registered',
        'sales_channel_state' => 'registered',
        'storefront_token_state' => 'registered',
        'brand_design_state' => 'synced',
    ], webhookState: 'partial');

    expect($integration->shopifyInstallStatus()['state'])->toBe('incomplete');
});

it('returns pending when some steps are done but others missing', function () {
    $brand = createBrandTenant('setup-state-brand-5');
    $integration = seedInstallIntegration($brand->id, [
        'metafield_definitions_state' => 'registered',
        // sales_channel, storefront_token, brand_design still pending
    ], webhookState: 'registered');

    expect($integration->shopifyInstallStatus()['state'])->toBe('pending');
});

// ── retrySetup() controller tests ────────────────────────────────────────────

it('retrySetup returns queued=false when setup is already complete', function () {
    $brand = createBrandTenant('setup-state-brand-6');
    $integration = seedInstallIntegration($brand->id, [
        'metafield_definitions_state' => 'registered',
        'sales_channel_state' => 'registered',
        'storefront_token_state' => 'registered',
        'brand_design_state' => 'synced',
    ], webhookState: 'registered');

    Queue::fake();
    $req = tenantRequestAs($brand, [], 'POST');

    $response = app(ShopifyIntegrationController::class)->retrySetup($req);
    $data = $response->getData(true);

    expect($data['queued'])->toBeFalse();
    expect($data['setup_state'])->toBe('complete');
    Queue::assertNothingPushed();
});

it('retrySetup re-dispatches failed steps and resets their state to queued', function () {
    $brand = createBrandTenant('setup-state-brand-7');
    $integration = seedInstallIntegration($brand->id, [
        'metafield_definitions_state' => 'failed',
        'sales_channel_state' => 'registered',
        'storefront_token_state' => 'registered',
        'brand_design_state' => 'failed',
    ], webhookState: 'registered');

    Queue::fake();
    $req = tenantRequestAs($brand, [], 'POST');

    $response = app(ShopifyIntegrationController::class)->retrySetup($req);
    $data = $response->getData(true);

    expect($data['queued'])->toBeTrue();
    expect($data['retried_steps'])->toContain('metafields');
    expect($data['retried_steps'])->toContain('brand_design');
    expect($data['retried_steps'])->not->toContain('webhooks');

    // State reset to 'queued' for retried steps
    $integration->refresh();
    $metadata = $integration->provider_metadata;
    expect($metadata['metafield_definitions_state'])->toBe('queued');
    expect($metadata['brand_design_state'])->toBe('queued');
    // Successful step untouched (column-backed post-DATA-2)
    expect($integration->webhook_registration_state)->toBe('registered');
});

it('status endpoint includes setup_state and setup_steps when token is provisioned', function () {
    $brand = createBrandTenant('setup-state-brand-8');
    seedInstallIntegration($brand->id, [
        'metafield_definitions_state' => 'registered',
        'sales_channel_state' => 'failed',
        'storefront_token_state' => 'registered',
        'brand_design_state' => 'synced',
    ], webhookState: 'registered');

    $req = tenantRequestAs($brand, [], 'GET');
    $response = app(ShopifyIntegrationController::class)->status($req);
    $data = $response->getData(true);

    expect($data['setup_state'])->toBe('incomplete');
    expect($data['setup_steps']['sales_channel'])->toBe('failed');
    expect($data['setup_steps']['webhooks'])->toBe('registered');
});
