<?php

use App\Enums\BrandStatus;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Pattern A Step 5 of the embedded-rework remediation plan: detect the silent
// drift case where a Shopify integration has been disconnected (access_token
// nulled, disconnected_at set) but brand_profile.brand_status didn't propagate
// past the persistence threshold. The ReconcileStuckShopifyIntegrationsJob and
// the uninstall webhook controller both write both sides in one path, so this
// command catches the rows where the second write silently no-op'd.

beforeEach(function (): void {
    setupProfessionalIntegrationsTable();
    setupBrandProfilesTable();
});

function seedStuckShopifyIntegration(array $overrides = []): string
{
    $proId = $overrides['professional_id'] ?? (string) Str::uuid();
    $brandStatus = $overrides['_brand_status'] ?? BrandStatus::StorefrontLive->value;
    $skipBrandProfile = (bool) ($overrides['_skip_brand_profile'] ?? false);
    unset($overrides['_brand_status'], $overrides['_skip_brand_profile']);

    // forceFill is required because shopify_shop_domain is non-fillable on the
    // model. Saving via the model triggers the 'encrypted' cast on access_token,
    // matching how the reconcile job and webhook controller write the column.
    $defaults = [
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => null,
        'refresh_token' => null,
        'disconnected_at' => now()->subDays(10),
        'provider_metadata' => [],
    ];
    $integration = (new ProfessionalIntegration)->forceFill(array_merge($defaults, $overrides));
    $integration->id = (string) Str::uuid();
    $integration->save();

    if (! $skipBrandProfile) {
        DB::table('brand.brand_profiles')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $proId,
            'brand_status' => $brandStatus,
            'setup_complete' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $proId;
}

it('exits clean when no integrations match', function (): void {
    Exceptions::fake();

    $exitCode = $this->artisan('partna:report-stuck-shopify-integrations')->run();

    expect($exitCode)->toBe(0);
    Exceptions::assertNothingReported();
});

it('reports a RuntimeException when a Shopify integration is stuck beyond the threshold', function (): void {
    Exceptions::fake();
    Log::spy();

    $proId = seedStuckShopifyIntegration([
        '_brand_status' => BrandStatus::StorefrontLive->value,
        'disconnected_at' => now()->subDays(8),
    ]);

    $this->artisan('partna:report-stuck-shopify-integrations')->assertSuccessful();

    Exceptions::assertReported(\RuntimeException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $msg, array $ctx) use ($proId): bool {
            return $msg === 'shopify.reconcile.silent_drift_detected'
                && $ctx['count'] === 1
                && $ctx['threshold_days'] === 7
                && $ctx['sample'][0]['professional_id'] === $proId;
        })
        ->once();
});

it('stays silent for integrations stuck less than the threshold', function (): void {
    Exceptions::fake();

    seedStuckShopifyIntegration([
        '_brand_status' => BrandStatus::StorefrontLive->value,
        'disconnected_at' => now()->subDays(5),
    ]);

    $this->artisan('partna:report-stuck-shopify-integrations')->assertSuccessful();

    Exceptions::assertNothingReported();
});

it('stays silent when brand_status has correctly transitioned to disconnected', function (): void {
    Exceptions::fake();

    seedStuckShopifyIntegration([
        '_brand_status' => BrandStatus::Disconnected->value,
        'disconnected_at' => now()->subDays(30),
    ]);

    $this->artisan('partna:report-stuck-shopify-integrations')->assertSuccessful();

    Exceptions::assertNothingReported();
});

it('stays silent when access_token is still set (active connection)', function (): void {
    Exceptions::fake();

    seedStuckShopifyIntegration([
        '_brand_status' => BrandStatus::StorefrontLive->value,
        'access_token' => 'shpat_still_active',
        'disconnected_at' => null,
    ]);

    $this->artisan('partna:report-stuck-shopify-integrations')->assertSuccessful();

    Exceptions::assertNothingReported();
});

it('stays silent for integrations with no brand_profile row (never-onboarded)', function (): void {
    Exceptions::fake();

    seedStuckShopifyIntegration([
        '_skip_brand_profile' => true,
        'disconnected_at' => now()->subDays(60),
    ]);

    $this->artisan('partna:report-stuck-shopify-integrations')->assertSuccessful();

    Exceptions::assertNothingReported();
});

it('honours the --days option as a custom persistence threshold', function (): void {
    Exceptions::fake();

    seedStuckShopifyIntegration([
        '_brand_status' => BrandStatus::StorefrontLive->value,
        'disconnected_at' => now()->subDays(3),
    ]);

    // Default 7-day threshold: 3 days isn't stuck enough.
    $this->artisan('partna:report-stuck-shopify-integrations')->assertSuccessful();
    Exceptions::assertNothingReported();

    // --days=2: 3 days is now over threshold and alert fires.
    $this->artisan('partna:report-stuck-shopify-integrations', ['--days' => 2])
        ->assertSuccessful();
    Exceptions::assertReported(\RuntimeException::class);
});
