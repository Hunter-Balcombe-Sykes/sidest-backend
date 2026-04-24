<?php

use App\Http\Controllers\Api\Professional\Store\BrandDesignController;
use App\Http\Controllers\Api\Professional\Store\BrandStoreSettingsController;
use App\Services\Media\BrandDesignMediaService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    // brand schema is not in the default attachTestSchemas list
    try {
        DB::connection('pgsql')->statement("ATTACH DATABASE ':memory:' AS brand");
    } catch (\Throwable) {
        // already attached
    }

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        default_commission_rate REAL,
        payout_hold_days INTEGER,
        theme_id INTEGER,
        oxygen_deployment_token TEXT,
        oxygen_storefront_id TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // BrandDesignController::show() calls brandIntegration() which queries this table directly.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        access_token TEXT,
        provider_metadata TEXT,
        status TEXT,
        expires_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('store settings show returns only the callers own settings', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    DB::table('brand.brand_store_settings')->insert([
        [
            'id' => (string) Str::uuid(),
            'professional_id' => $a->id,
            'default_commission_rate' => 20.0,
            'theme_id' => 99,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::uuid(),
            'professional_id' => $b->id,
            'default_commission_rate' => 15.0,
            'theme_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    // BrandCatalogService::resolveBrandIntegration() is called in show() — stub it out
    $this->mock(BrandCatalogService::class, fn ($m) => $m->shouldReceive('resolveBrandIntegration')->andThrow(new \RuntimeException('no shopify'))
    );

    $req = tenantRequestAs($b);
    $response = app(BrandStoreSettingsController::class)->show($req);
    $payload = $response->getData(true);

    // Brand B's theme_id is 1; Brand A's is 99. Verify we got B's settings.
    // success() wraps via response()->json($resource) — no 'data' envelope.
    expect((int) ($payload['theme_id'] ?? -1))->toBe(1);
    expect((int) ($payload['theme_id'] ?? -1))->not->toBe(99);
});

it('brand design show returns only the callers own design tokens from site settings', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('site.sites')->where('id', $a->site->id)->update([
        'settings' => json_encode(['design' => ['colors' => ['accent' => '#aaaaaa']]]),
    ]);
    DB::table('site.sites')->where('id', $b->site->id)->update([
        'settings' => json_encode(['design' => ['colors' => ['accent' => '#bbbbbb']]]),
    ]);

    // BrandDesignMediaService is injected; stub it so no real DB/media queries run.
    $this->mock(BrandDesignMediaService::class, fn ($m) => $m->shouldReceive('listDesignMedia')->andReturn([
        'logo' => ['full_url' => null, 'square_url' => null],
        'placeholders' => [],
    ])
    );

    // BrandDesignController queries core.professional_integrations directly (not via
    // BrandCatalogService) — no mock needed; table was created in beforeEach.
    $req = tenantRequestAs($b);
    $response = app(BrandDesignController::class)->show($req);
    $payload = $response->getData(true);

    // success() uses response()->json($resource) — no 'data' envelope.
    $accent = data_get($payload, 'colors.accent');
    expect($accent)->toBe('#bbbbbb');
    expect($accent)->not->toBe('#aaaaaa');
});
