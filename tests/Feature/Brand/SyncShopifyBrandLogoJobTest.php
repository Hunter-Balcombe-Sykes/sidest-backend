<?php

/** @phpstan-ignore-all */

use App\Jobs\Shopify\SyncShopifyBrandLogoJob;
use App\Models\Core\MediaVariant;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * End-to-end coverage for SyncShopifyBrandLogoJob after the SiteMedia
 * pipeline migration. The old version of this job wrote the raw Shopify
 * CDN URL straight into site.settings.design.media.brand_logo_url, which
 * left the logo dependent on Shopify's CDN and skipped variant generation.
 *
 * These tests assert the new contract: download → SiteMedia row → R2
 * original → WebP variants → variant URL in site.settings, with a dedupe
 * path for unchanged logos.
 */

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();

    $conn = DB::connection('pgsql');
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        access_token TEXT NULL,
        provider_metadata TEXT NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    Storage::fake('media');
    Cache::flush();

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull();
    app()->instance(SiteCacheService::class, $cache);
});

/**
 * Real PNG bytes that both finfo and GD will accept. imagecreatetruecolor
 * + imagepng produces a valid 4x4 PNG so ProcessImageVariantsJob can
 * actually run the full pipeline under Storage::fake('media').
 */
function makeTinyPngBytes(): string
{
    $gd = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($gd);
    $bytes = ob_get_clean();
    imagedestroy($gd);

    return $bytes;
}

function createShopifyBrandIntegration(array $overrides = []): ProfessionalIntegration
{
    $brandId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $intId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandId,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => 'logosync',
        'handle_lc' => 'logosync',
        'display_name' => 'Logo Sync Brand',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $brandId,
        'subdomain' => 'logosync',
        'settings' => json_encode($overrides['settings'] ?? []),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // access_token is cast as 'encrypted' on the model, so raw DB inserts leave
    // the column with plaintext that the decryptor can't read. Go through the
    // model so the cast runs and the ciphertext lands in the row.
    $integration = new ProfessionalIntegration;
    $integration->id = $intId;
    $integration->professional_id = $brandId;
    $integration->provider = ProfessionalIntegration::PROVIDER_SHOPIFY;
    $integration->access_token = 'shpat_fake_token';
    $integration->provider_metadata = ['shop_domain' => 'test-shop.myshopify.com'];
    $integration->status = 'active';
    $integration->created_at = $now;
    $integration->updated_at = $now;
    $integration->save();

    return ProfessionalIntegration::query()->findOrFail($intId);
}

/**
 * Wire up the two Http calls the job makes: Shopify Admin GraphQL for the
 * logo URL, and the CDN fetch for the image bytes.
 */
function fakeShopifyLogoHttp(string $logoUrl, string $imageBytes): void
{
    Http::fake([
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'shop' => [
                    'brand' => [
                        'squareLogo' => [
                            'image' => ['url' => $logoUrl],
                        ],
                    ],
                ],
            ],
        ], 200),
        'cdn.shopify.com/*' => Http::response($imageBytes, 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);
}

function runLogoJob(ProfessionalIntegration $integration): void
{
    $job = new SyncShopifyBrandLogoJob((string) $integration->id);
    $job->handle(app(ImageVariantService::class), app(SiteCacheService::class));
}

it('downloads the logo, creates a design-pool SiteMedia, and writes variant URL to site settings', function () {
    $integration = createShopifyBrandIntegration();
    fakeShopifyLogoHttp('https://cdn.shopify.com/s/files/1/0001/brand-logo.png', makeTinyPngBytes());

    runLogoJob($integration);

    $logo = SiteMedia::query()
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('alt_text', 'logo')
        ->whereNull('deleted_at')
        ->first();

    expect($logo)->not->toBeNull();
    expect($logo->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY);
    expect($logo->original_mime)->toBe('image/png');
    expect($logo->path)->toStartWith("images/{$integration->professional_id}/{$logo->id}/original_");

    // The original must actually land on the fake media disk — this is what
    // previously-broken behavior was silently skipping.
    expect(Storage::disk('media')->exists($logo->path))->toBeTrue();

    // A WebP variant row must exist so Hydrogen can resolve a real URL.
    $variant = MediaVariant::query()
        ->where('media_id', $logo->id)
        ->where('artifact_type', 'webp')
        ->first();
    expect($variant)->not->toBeNull();

    $site = Site::query()->where('professional_id', $integration->professional_id)->firstOrFail();
    $media = data_get($site->settings, 'design.media');

    expect($media['brand_logo_url'] ?? null)->toBeString();
    // The key assertion: the URL no longer points at Shopify's CDN — it
    // resolves through our media disk instead.
    expect($media['brand_logo_url'] ?? null)->not->toContain('cdn.shopify.com');
    expect($media['brand_logo_path'] ?? null)->toBe($logo->path);
    expect($media['brand_logo_name'] ?? null)->toBe('shopify-brand-logo.png');
});

it('dedupes repeat syncs when logo bytes are unchanged', function () {
    $integration = createShopifyBrandIntegration();
    $bytes = makeTinyPngBytes();
    fakeShopifyLogoHttp('https://cdn.shopify.com/s/files/1/0001/brand-logo.png', $bytes);

    runLogoJob($integration);

    $firstLogo = SiteMedia::query()
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('alt_text', 'logo')
        ->whereNull('deleted_at')
        ->firstOrFail();
    $firstLogoId = $firstLogo->id;
    $firstVariantCount = MediaVariant::query()->count();

    // Second run against the same bytes — should be a no-op.
    runLogoJob($integration);

    $activeLogos = SiteMedia::query()
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('alt_text', 'logo')
        ->whereNull('deleted_at')
        ->get();

    expect($activeLogos)->toHaveCount(1);
    // Same row, not a fresh insert that happens to look identical.
    expect($activeLogos->first()->id)->toBe($firstLogoId);
    // No re-processing → no new variant rows.
    expect(MediaVariant::query()->count())->toBe($firstVariantCount);
});

it('replaces the previous logo when the bytes change', function () {
    $integration = createShopifyBrandIntegration();

    // Different bytes across runs — same URL is fine, the dedupe keys off
    // content hash not URL. Use Http::sequence so the second CDN fetch returns
    // the white 8x8 PNG instead of the 4x4 default-black one. A second
    // Http::fake([...]) call would *merge* stubs (old callback wins), not
    // replace them, which is why we pre-register the sequence up front.
    $otherBytes = (function () {
        $gd = imagecreatetruecolor(8, 8);
        $white = imagecolorallocate($gd, 255, 255, 255);
        imagefill($gd, 0, 0, $white);
        ob_start();
        imagepng($gd);
        $bytes = ob_get_clean();
        imagedestroy($gd);

        return $bytes;
    })();

    Http::fake([
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'shop' => [
                    'brand' => [
                        'squareLogo' => [
                            'image' => ['url' => 'https://cdn.shopify.com/s/files/1/0001/brand-logo.png'],
                        ],
                    ],
                ],
            ],
        ], 200),
        'cdn.shopify.com/*' => Http::sequence()
            ->push(makeTinyPngBytes(), 200, ['Content-Type' => 'image/png'])
            ->push($otherBytes, 200, ['Content-Type' => 'image/png']),
    ]);

    runLogoJob($integration);

    $firstLogo = SiteMedia::query()
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('alt_text', 'logo')
        ->whereNull('deleted_at')
        ->firstOrFail();

    runLogoJob($integration);

    $active = SiteMedia::query()
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('alt_text', 'logo')
        ->whereNull('deleted_at')
        ->get();

    expect($active)->toHaveCount(1);
    expect($active->first()->id)->not->toBe($firstLogo->id);

    // Old row is soft-deleted, not hard-deleted.
    $all = SiteMedia::withTrashed()
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('alt_text', 'logo')
        ->get();
    expect($all)->toHaveCount(2);
    expect($all->whereNotNull('deleted_at'))->toHaveCount(1);
});

it('busts the Hydrogen brand-design cache after a successful sync', function () {
    $integration = createShopifyBrandIntegration();
    fakeShopifyLogoHttp('https://cdn.shopify.com/s/files/1/0001/brand-logo.png', makeTinyPngBytes());

    $cacheKey = CacheKeyGenerator::brandDesignConfig((string) $integration->professional_id);
    Cache::put($cacheKey, ['stale' => true], now()->addMinutes(5));

    runLogoJob($integration);

    expect(Cache::get($cacheKey))->toBeNull();
});

it('refuses logo URLs from non-allowlisted hosts', function () {
    $integration = createShopifyBrandIntegration();
    Http::fake([
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'shop' => [
                    'brand' => [
                        'squareLogo' => [
                            // Attacker-controlled storefront returns a URL pointing
                            // at an internal metadata endpoint. The job must refuse.
                            'image' => ['url' => 'https://169.254.169.254/latest/meta-data/'],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    runLogoJob($integration);

    expect(SiteMedia::query()->count())->toBe(0);

    $site = Site::query()->where('professional_id', $integration->professional_id)->firstOrFail();
    expect(data_get($site->settings, 'design.media.brand_logo_url'))->toBeNull();
});

it('no-ops when Shopify returns no logo', function () {
    $integration = createShopifyBrandIntegration(['settings' => [
        'design' => ['media' => ['brand_logo_url' => 'https://existing.example.com/logo.png']],
    ]]);

    Http::fake([
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => ['shop' => ['brand' => ['squareLogo' => null]]],
        ], 200),
    ]);

    runLogoJob($integration);

    expect(SiteMedia::query()->count())->toBe(0);

    // Existing settings must be left alone so we don't wipe a manually-uploaded logo.
    $site = Site::query()->where('professional_id', $integration->professional_id)->firstOrFail();
    expect(data_get($site->settings, 'design.media.brand_logo_url'))
        ->toBe('https://existing.example.com/logo.png');
});

it('returns early when the integration does not exist', function () {
    fakeShopifyLogoHttp('https://cdn.shopify.com/s/files/1/0001/brand-logo.png', makeTinyPngBytes());

    $job = new SyncShopifyBrandLogoJob('00000000-0000-0000-0000-000000000000');
    $job->handle(app(ImageVariantService::class), app(SiteCacheService::class));

    expect(SiteMedia::query()->count())->toBe(0);
    // Should not have hit the GraphQL endpoint either.
    Http::assertNothingSent();
});
